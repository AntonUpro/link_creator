<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ShortUrl;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShortUrl>
 *
 * @method ShortUrl|null find($id, $lockMode = null, $lockVersion = null)
 * @method ShortUrl|null findOneBy(array $criteria, array $orderBy = null)
 * @method ShortUrl[]    findAll()
 * @method ShortUrl[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ShortUrlRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShortUrl::class);
    }

    /**
     * Найти активную ссылку по shortCode или customAlias
     */
    public function findActiveByShortCode(string $shortCode): ?ShortUrl
    {
        return $this->createQueryBuilder('s')
            ->where('(s.shortCode = :shortCode OR s.customAlias = :shortCode)')
            ->andWhere('s.isActive = :active')
            ->setParameter('shortCode', $shortCode)
            ->setParameter('active', true)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Проверить существует ли кастомный алиас
     */
    public function customAliasExists(string $customAlias): bool
    {
        $result = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.customAlias = :alias')
            ->setParameter('alias', $customAlias)
            ->getQuery()
            ->getSingleScalarResult();

        return $result > 0;
    }

    /**
     * Найти ссылки пользователя с пагинацией
     */
    public function findUserLinks(int $userId, ?int $limit = null, ?int $offset = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->where('s.user = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('s.createdAt', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Получить количество ссылок пользователя
     */
    public function countUserLinks(int $userId): int
    {
        return $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.user = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Увеличить счетчик кликов
     */
    public function incrementClicks(ShortUrl $shortUrl): void
    {
        $this->createQueryBuilder('s')
            ->update()
            ->set('s.clicks', 's.clicks + 1')
            ->where('s.id = :id')
            ->setParameter('id', $shortUrl->getId())
            ->getQuery()
            ->execute();
    }

    /**
     * Найти истекшие ссылки
     */
    public function findExpiredLinks(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.expiresAt < :now')
            ->andWhere('s.isActive = :active')
            ->setParameter('now', new \DateTime())
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();
    }

    /**
     * Деактивировать истекшие ссылки
     */
    public function deactivateExpiredLinks(): int
    {
        return $this->createQueryBuilder('s')
            ->update()
            ->set('s.isActive', ':inactive')
            ->where('s.expiresAt < :now')
            ->andWhere('s.isActive = :active')
            ->setParameter('now', new \DateTime())
            ->setParameter('active', true)
            ->setParameter('inactive', false)
            ->getQuery()
            ->execute();
    }

    /**
     * Найти самые популярные ссылки
     */
    public function findMostPopular(int $limit = 10): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('s.clicks', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Найти недавно созданные ссылки
     */
    public function findRecentlyCreated(int $limit = 10): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Поиск ссылок по домену
     */
    public function findByDomain(string $domain): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.longUrl LIKE :domain')
            ->andWhere('s.isActive = :active')
            ->setParameter('domain', '%' . $domain . '%')
            ->setParameter('active', true)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Получить статистику по дням
     */
    public function getDailyStats(?\DateTimeInterface $startDate = null, ?\DateTimeInterface $endDate = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->select(
                "DATE(s.createdAt) as date",
                "COUNT(s.id) as links_created",
                "SUM(s.clicks) as total_clicks"
            )
            ->groupBy('date')
            ->orderBy('date', 'DESC');

        if ($startDate) {
            $qb->andWhere('s.createdAt >= :startDate')
                ->setParameter('startDate', $startDate);
        }

        if ($endDate) {
            $qb->andWhere('s.createdAt <= :endDate')
                ->setParameter('endDate', $endDate);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Поиск по оригинальному URL (частичное совпадение)
     */
    public function searchByLongUrl(string $searchTerm, ?User $user = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->where('s.longUrl LIKE :search')
            ->setParameter('search', '%' . $searchTerm . '%')
            ->orderBy('s.createdAt', 'DESC');

        if ($user) {
            $qb->andWhere('s.user = :user')
                ->setParameter('user', $user);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Удалить старые неактивные ссылки
     */
    public function deleteOldInactiveLinks(int $daysOld = 365): int
    {
        $date = new \DateTime("-{$daysOld} days");

        return $this->createQueryBuilder('s')
            ->delete()
            ->where('s.isActive = :inactive')
            ->andWhere('s.createdAt < :date')
            ->setParameter('inactive', false)
            ->setParameter('date', $date)
            ->getQuery()
            ->execute();
    }

    /**
     * Получить общую статистику
     */
    public function getGlobalStats(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = "
            SELECT
                COUNT(*) as total_links,
                SUM(clicks) as total_clicks,
                AVG(clicks) as avg_clicks,
                COUNT(DISTINCT user_id) as total_users,
                COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_links,
                COUNT(CASE WHEN custom_alias IS NOT NULL THEN 1 END) as custom_aliases
            FROM short_urls
        ";

        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery();

        return $result->fetchAssociative() ?: [];
    }

    /**
     * Проверить существует ли shortCode
     */
    public function shortCodeExists(string $shortCode): bool
    {
        $result = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.shortCode = :shortCode OR s.customAlias = :shortCode')
            ->setParameter('shortCode', $shortCode)
            ->getQuery()
            ->getSingleScalarResult();

        return $result > 0;
    }

    /**
     * Получить следующую доступную короткую ссылку (для генерации)
     */
    public function getNextAvailableShortCode(string $baseCode): string
    {
        $counter = 1;
        $proposedCode = $baseCode;

        while ($this->shortCodeExists($proposedCode)) {
            $proposedCode = $baseCode . '-' . $counter;
            $counter++;
        }

        return $proposedCode;
    }
}
