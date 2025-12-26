<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ShortUrl;
use App\Service\ShortUrlGenerator;
use App\Service\QrCodeGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\RateLimiter\RateLimiterFactory;

#[Route('/api/v1')]
class ApiController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ShortUrlGenerator $shortUrlGenerator,
        private QrCodeGenerator $qrCodeGenerator,
        private RateLimiterFactory $apiLimiter
    ) {}

    /**
     * Создание короткой ссылки через API
     */
    #[Route('/shorten', name: 'api_shorten', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function createShortUrl(Request $request): JsonResponse
    {
        // Rate limiting для API
        $limiter = $this->apiLimiter->create($this->getUser()->getUserIdentifier());
        $limit = $limiter->consume();

        if (!$limit->isAccepted()) {
            return new JsonResponse([
                'error' => 'Rate limit exceeded',
                'retry_after' => $limit->getRetryAfter()->getTimestamp(),
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $data = json_decode($request->getContent(), true);

        // Валидация
        if (!isset($data['url']) || empty($data['url'])) {
            return new JsonResponse([
                'error' => 'Missing required parameter: url',
            ], Response::HTTP_BAD_REQUEST);
        }

        $longUrl = $data['url'];
        $customAlias = $data['custom_alias'] ?? null;
        $expiresIn = $data['expires_in'] ?? null; // в днях

        // Валидация URL
        if (!filter_var($longUrl, FILTER_VALIDATE_URL)) {
            return new JsonResponse([
                'error' => 'Invalid URL format',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $shortUrl = new ShortUrl();
            $shortUrl->setLongUrl($longUrl);
            $shortUrl->setUser($this->getUser());

            $shortCode = $customAlias ?? $this->shortUrlGenerator->generate();
            $shortUrl->setShortCode($shortCode);

            if ($customAlias) {
                $shortUrl->setCustomAlias($customAlias);
            }

            if ($expiresIn) {
                $expiresAt = new \DateTime('+' . $expiresIn . ' days');
                $shortUrl->setExpiresAt($expiresAt);
            }

            // Генерация QR кода
            $qrCodePath = $this->qrCodeGenerator->generateForUrl($shortUrl->getShortUrl());
            $shortUrl->setQrCodePath($qrCodePath);

            $this->entityManager->persist($shortUrl);
            $this->entityManager->flush();

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'id' => $shortUrl->getId(),
                    'short_url' => $shortUrl->getShortUrl(),
                    'short_code' => $shortCode,
                    'long_url' => $shortUrl->getLongUrl(),
                    'qr_code_url' => $this->generateUrl('app_qr_code', ['shortCode' => $shortCode]),
                    'expires_at' => $shortUrl->getExpiresAt()?->format('c'),
                    'clicks' => $shortUrl->getClicks(),
                    'created_at' => $shortUrl->getCreatedAt()->format('c'),
                ]
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Failed to create short URL',
                'message' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Получение информации о ссылке
     */
    #[Route('/links/{shortCode}', name: 'api_link_info', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getLinkInfo(string $shortCode): JsonResponse
    {
        $shortUrl = $this->entityManager->getRepository(ShortUrl::class)
            ->findOneBy(['shortCode' => $shortCode, 'user' => $this->getUser()]);

        if (!$shortUrl) {
            return new JsonResponse([
                'error' => 'Link not found',
            ], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'data' => [
                'id' => $shortUrl->getId(),
                'short_url' => $shortUrl->getShortUrl(),
                'long_url' => $shortUrl->getLongUrl(),
                'clicks' => $shortUrl->getClicks(),
                'is_active' => $shortUrl->isActive(),
                'expires_at' => $shortUrl->getExpiresAt()?->format('c'),
                'created_at' => $shortUrl->getCreatedAt()->format('c'),
                'updated_at' => $shortUrl->getUpdatedAt()->format('c'),
            ]
        ]);
    }

    /**
     * Получение списка ссылок пользователя
     */
    #[Route('/links', name: 'api_user_links', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getUserLinks(Request $request): JsonResponse
    {
        $page = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 20);
        $offset = ($page - 1) * $limit;

        $repository = $this->entityManager->getRepository(ShortUrl::class);
        $links = $repository->findUserLinks($this->getUser()->getId(), $limit, $offset);
        $total = $repository->count(['user' => $this->getUser()]);

        $data = array_map(function (ShortUrl $shortUrl) {
            return [
                'id' => $shortUrl->getId(),
                'short_url' => $shortUrl->getShortUrl(),
                'long_url' => $shortUrl->getLongUrl(),
                'clicks' => $shortUrl->getClicks(),
                'is_active' => $shortUrl->isActive(),
                'expires_at' => $shortUrl->getExpiresAt()?->format('c'),
                'created_at' => $shortUrl->getCreatedAt()->format('c'),
            ];
        }, $links);

        return new JsonResponse([
            'data' => $data,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit),
            ]
        ]);
    }

    /**
     * Обновление ссылки
     */
    #[Route('/links/{shortCode}', name: 'api_update_link', methods: ['PUT', 'PATCH'])]
    #[IsGranted('ROLE_USER')]
    public function updateLink(string $shortCode, Request $request): JsonResponse
    {
        $shortUrl = $this->entityManager->getRepository(ShortUrl::class)
            ->findOneBy(['shortCode' => $shortCode, 'user' => $this->getUser()]);

        if (!$shortUrl) {
            return new JsonResponse([
                'error' => 'Link not found',
            ], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['is_active'])) {
            $shortUrl->setIsActive((bool) $data['is_active']);
        }

        if (isset($data['expires_in'])) {
            $expiresAt = new \DateTime('+' . $data['expires_in'] . ' days');
            $shortUrl->setExpiresAt($expiresAt);
        }

        $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'data' => [
                'id' => $shortUrl->getId(),
                'is_active' => $shortUrl->isActive(),
                'expires_at' => $shortUrl->getExpiresAt()?->format('c'),
            ]
        ]);
    }

    /**
     * Удаление ссылки
     */
    #[Route('/links/{shortCode}', name: 'api_delete_link', methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    public function deleteLink(string $shortCode): JsonResponse
    {
        $shortUrl = $this->entityManager->getRepository(ShortUrl::class)
            ->findOneBy(['shortCode' => $shortCode, 'user' => $this->getUser()]);

        if (!$shortUrl) {
            return new JsonResponse([
                'error' => 'Link not found',
            ], Response::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($shortUrl);
        $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Link deleted successfully',
        ]);
    }

    /**
     * Статистика по ссылке
     */
    #[Route('/links/{shortCode}/stats', name: 'api_link_stats', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getLinkStats(string $shortCode, Request $request): JsonResponse
    {
        $shortUrl = $this->entityManager->getRepository(ShortUrl::class)
            ->findOneBy(['shortCode' => $shortCode, 'user' => $this->getUser()]);

        if (!$shortUrl) {
            return new JsonResponse([
                'error' => 'Link not found',
            ], Response::HTTP_NOT_FOUND);
        }

        // Параметры фильтрации
        $startDate = $request->query->get('start_date');
        $endDate = $request->query->get('end_date');
        $groupBy = $request->query->get('group_by', 'day'); // day, week, month

        // Получаем статистику из репозитория LinkClick
        $stats = $this->entityManager->getRepository(LinkClick::class)
            ->getStatsForShortUrl($shortUrl, $startDate, $endDate, $groupBy);

        return new JsonResponse([
            'data' => [
                'total_clicks' => $shortUrl->getClicks(),
                'daily_stats' => $stats,
                'top_countries' => $this->getTopCountries($shortUrl),
                'top_referrers' => $this->getTopReferrers($shortUrl),
                'browser_stats' => $this->getBrowserStats($shortUrl),
            ]
        ]);
    }
}
