<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 *
 * @implements PasswordUpgraderInterface<User>
 *
 * @method User|null find($id, $lockMode = null, $lockVersion = null)
 * @method User|null findOneBy(array $criteria, array $orderBy = null)
 * @method User[]    findAll()
 * @method User[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /**
     * Найти пользователя по email
     */
    public function findByEmail(string $email): ?User
    {
        return $this->createQueryBuilder('u')
            ->where('u.email = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Найти пользователя по API токену
     */
    public function findByApiToken(string $apiToken): ?User
    {
        return $this->createQueryBuilder('u')
            ->where('u.apiToken = :apiToken')
            ->setParameter('apiToken', $apiToken)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Генерация уникального API токена
     */
    public function generateApiToken(): string
    {
        do {
            $token = bin2hex(random_bytes(32));
            $exists = $this->findByApiToken($token);
        } while ($exists !== null);

        return $token;
    }

    /**
     * Получить статистику по пользователю
     */
    public function getUserStats(int $userId): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = "
            SELECT
                COUNT(s.id) as total_links,
                SUM(s.clicks) as total_clicks,
                AVG(s.clicks) as avg_clicks_per_link,
                MAX(s.clicks) as max_clicks,
                MIN(s.clicks) as min_clicks,
                COUNT(CASE WHEN s.is_active = 1 THEN 1 END) as active_links,
                COUNT(CASE WHEN s.expires_at < NOW() THEN 1 END) as expired_links
            FROM short_urls s
            WHERE s.user_id = :userId
        ";

        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery(['userId' => $userId]);

        return $result->fetchAssociative() ?: [];
    }

    /**
     * Получить последних активных пользователей
     */
    public function findRecentActiveUsers(int $limit = 10): array
    {
        return $this->createQueryBuilder('u')
            ->select('u.id, u.email, u.createdAt, COUNT(s.id) as link_count')
            ->leftJoin('u.shortUrls', 's')
            ->groupBy('u.id')
            ->orderBy('u.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Поиск пользователей с неактивными ссылками
     */
    public function findUsersWithInactiveLinks(int $daysInactive = 30): array
    {
        $date = new \DateTime("-{$daysInactive} days");

        return $this->createQueryBuilder('u')
            ->select('u.id, u.email, MAX(s.updatedAt) as last_activity')
            ->join('u.shortUrls', 's')
            ->groupBy('u.id')
            ->having('MAX(s.updatedAt) < :date')
            ->setParameter('date', $date)
            ->getQuery()
            ->getResult();
    }

    /**
     * Удалить неактивных пользователей (для команды очистки)
     */
    public function deleteInactiveUsers(int $daysInactive = 90): int
    {
        $date = new \DateTime("-{$daysInactive} days");

        $qb = $this->createQueryBuilder('u')
            ->delete()
            ->where('u.createdAt < :date')
            ->andWhere('NOT EXISTS (
                SELECT 1 FROM App\Entity\ShortUrl s
                WHERE s.user = u AND s.createdAt > :date
            )')
            ->setParameter('date', $date);

        return $qb->getQuery()->execute();
    }

    // Пример кастомных запросов для пагинации
    public function findPaginated(int $page = 1, int $limit = 20): array
    {
        $offset = ($page - 1) * $limit;

        return $this->createQueryBuilder('u')
            ->orderBy('u.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countAll(): int
    {
        return $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
