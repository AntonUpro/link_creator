<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LinkClick;
use App\Entity\ShortUrl;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LinkClick>
 *
 * @method LinkClick|null find($id, $lockMode = null, $lockVersion = null)
 * @method LinkClick|null findOneBy(array $criteria, array $orderBy = null)
 * @method LinkClick[]    findAll()
 * @method LinkClick[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class LinkClickRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LinkClick::class);
    }

    /**
     * Получить клики по короткой ссылке
     */
    public function findByShortUrl(ShortUrl $shortUrl, ?int $limit = null, ?int $offset = null): array
    {
        $qb = $this->createQueryBuilder('lc')
            ->where('lc.shortUrl = :shortUrl')
            ->setParameter('shortUrl', $shortUrl)
            ->orderBy('lc.createdAt', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Получить количество кликов по короткой ссылке
     */
    public function countByShortUrl(ShortUrl $shortUrl): int
    {
        return $this->createQueryBuilder('lc')
            ->select('COUNT(lc.id)')
            ->where('lc.shortUrl = :shortUrl')
            ->setParameter('shortUrl', $shortUrl)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Получить статистику кликов по периодам
     */
    public function getStatsForShortUrl(
        ShortUrl $shortUrl,
        ?\DateTimeInterface $startDate = null,
        ?\DateTimeInterface $endDate = null,
        string $groupBy = 'day'
    ): array {
        $format = match ($groupBy) {
            'hour' => '%Y-%m-%d %H:00:00',
            'day' => '%Y-%m-%d',
            'week' => '%Y-%u',
            'month' => '%Y-%m',
            default => '%Y-%m-%d',
        };

        $conn = $this->getEntityManager()->getConnection();

        $sql = "
            SELECT
                DATE_FORMAT(created_at, :format) as period,
                COUNT(*) as clicks,
                COUNT(DISTINCT ip_address) as unique_visitors
            FROM link_clicks
            WHERE short_url_id = :shortUrlId
        ";

        $params = [
            'format' => $format,
            'shortUrlId' => $shortUrl->getId(),
        ];

        if ($startDate) {
            $sql .= " AND created_at >= :startDate";
            $params['startDate'] = $startDate->format('Y-m-d H:i:s');
        }

        if ($endDate) {
            $sql .= " AND created_at <= :endDate";
            $params['endDate'] = $endDate->format('Y-m-d H:i:s');
        }

        $sql .= " GROUP BY period ORDER BY period DESC";

        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery($params);

        return $result->fetchAllAssociative();
    }

    /**
     * Получить топ стран по кликам
     */
    public function getTopCountries(ShortUrl $shortUrl, int $limit = 10): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = "
            SELECT
                country,
                COUNT(*) as clicks,
                COUNT(DISTINCT ip_address) as unique_visitors
            FROM link_clicks
            WHERE short_url_id = :shortUrlId
                AND country IS NOT NULL
            GROUP BY country
            ORDER BY clicks DESC
            LIMIT :limit
        ";

        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery([
            'shortUrlId' => $shortUrl->getId(),
            'limit' => $limit,
        ]);

        return $result->fetchAllAssociative();
    }

    /**
     * Получить топ рефереров
     */
    public function getTopReferrers(ShortUrl $shortUrl, int $limit = 10): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = "
            SELECT
                referer,
                COUNT(*) as clicks
            FROM link_clicks
            WHERE short_url_id = :shortUrlId
                AND referer IS NOT NULL
                AND referer != ''
            GROUP BY referer
            ORDER BY clicks DESC
            LIMIT :limit
        ";

        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery([
            'shortUrlId' => $shortUrl->getId(),
            'limit' => $limit,
        ]);

        return $result->fetchAllAssociative();
    }

    /**
     * Получить статистику по браузерам/устройствам
     */
    public function getBrowserStats(ShortUrl $shortUrl): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = "
            SELECT
                CASE
                    WHEN user_agent LIKE '%Mobile%' THEN 'Mobile'
                    WHEN user_agent LIKE '%Tablet%' THEN 'Tablet'
                    ELSE 'Desktop'
                END as device_type,
                CASE
                    WHEN user_agent LIKE '%Chrome%' THEN 'Chrome'
                    WHEN user_agent LIKE '%Firefox%' THEN 'Firefox'
                    WHEN user_agent LIKE '%Safari%' AND user_agent NOT LIKE '%Chrome%' THEN 'Safari'
                    WHEN user_agent LIKE '%Edge%' THEN 'Edge'
                    WHEN user_agent LIKE '%Opera%' THEN 'Opera'
                    ELSE 'Other'
                END as browser,
                COUNT(*) as clicks
            FROM link_clicks
            WHERE short_url_id = :shortUrlId
                AND user_agent IS NOT NULL
            GROUP BY device_type, browser
            ORDER BY clicks DESC
        ";

        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery(['shortUrlId' => $shortUrl->getId()]);

        return $result->fetchAllAssociative();
    }

    /**
     * Получить пиковые часы активности
     */
    public function getPeakHours(ShortUrl $shortUrl): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = "
            SELECT
                HOUR(created_at) as hour,
                COUNT(*) as clicks
            FROM link_clicks
            WHERE short_url_id = :shortUrlId
            GROUP BY hour
            ORDER BY clicks DESC
            LIMIT 5
        ";

        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery(['shortUrlId' => $shortUrl->getId()]);

        return $result->fetchAllAssociative();
    }

    /**
     * Получить уникальных посетителей по IP
     */
    public function getUniqueVisitors(ShortUrl $shortUrl, ?\DateTimeInterface $startDate = null): int
    {
        $qb = $this->createQueryBuilder('lc')
            ->select('COUNT(DISTINCT lc.ipAddress)')
            ->where('lc.shortUrl = :shortUrl')
            ->setParameter('shortUrl', $shortUrl);

        if ($startDate) {
            $qb->andWhere('lc.createdAt >= :startDate')
                ->setParameter('startDate', $startDate);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Получить среднее время между кликами
     */
    public function getAverageTimeBetweenClicks(ShortUrl $shortUrl): ?float
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = "
            SELECT
                AVG(TIME_TO_SEC(TIMEDIFF(next_click.created_at, prev_click.created_at))) as avg_seconds
            FROM link_clicks prev_click
            JOIN link_clicks next_click ON (
                next_click.id = (
                    SELECT MIN(id)
                    FROM link_clicks
                    WHERE short_url_id = :shortUrlId
                    AND created_at > prev_click.created_at
                )
            )
            WHERE prev_click.short_url_id = :shortUrlId
        ";

        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery(['shortUrlId' => $shortUrl->getId()]);
        $data = $result->fetchAssociative();

        return $data['avg_seconds'] ? (float) $data['avg_seconds'] : null;
    }

    /**
     * Удалить старые записи кликов
     */
    public function deleteOldClicks(int $daysOld = 365): int
    {
        $date = new \DateTime("-{$daysOld} days");

        return $this->createQueryBuilder('lc')
            ->delete()
            ->where('lc.createdAt < :date')
            ->setParameter('date', $date)
            ->getQuery()
            ->execute();
    }

    /**
     * Получить географическое распределение кликов
     */
    public function getGeographicDistribution(ShortUrl $shortUrl): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = "
            SELECT
                country,
                COUNT(*) as clicks,
                (COUNT(*) * 100.0 / (SELECT COUNT(*) FROM link_clicks WHERE short_url_id = :shortUrlId)) as percentage
            FROM link_clicks
            WHERE short_url_id = :shortUrlId
                AND country IS NOT NULL
            GROUP BY country
            ORDER BY clicks DESC
        ";

        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery(['shortUrlId' => $shortUrl->getId()]);

        return $result->fetchAllAssociative();
    }

    /**
     * Получить клики за последние N дней
     */
    public function getClicksLastDays(ShortUrl $shortUrl, int $days = 30): array
    {
        $startDate = new \DateTime("-{$days} days");

        return $this->createQueryBuilder('lc')
            ->select(
                "DATE(lc.createdAt) as date",
                "COUNT(lc.id) as clicks",
                "COUNT(DISTINCT lc.ipAddress) as unique_visitors"
            )
            ->where('lc.shortUrl = :shortUrl')
            ->andWhere('lc.createdAt >= :startDate')
            ->setParameter('shortUrl', $shortUrl)
            ->setParameter('startDate', $startDate)
            ->groupBy('date')
            ->orderBy('date', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Проверить уникальность клика (по IP и времени)
     */
    public function isUniqueClick(ShortUrl $shortUrl, string $ipAddress, \DateTimeInterface $timeWindow = null): bool
    {
        if ($timeWindow === null) {
            $timeWindow = new \DateTime('-1 hour');
        }

        $count = $this->createQueryBuilder('lc')
            ->select('COUNT(lc.id)')
            ->where('lc.shortUrl = :shortUrl')
            ->andWhere('lc.ipAddress = :ipAddress')
            ->andWhere('lc.createdAt > :timeWindow')
            ->setParameter('shortUrl', $shortUrl)
            ->setParameter('ipAddress', $ipAddress)
            ->setParameter('timeWindow', $timeWindow)
            ->getQuery()
            ->getSingleScalarResult();

        return $count == 0;
    }
}
