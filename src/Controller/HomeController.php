<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ShortUrl;
use App\Repository\ShortUrlRepository;
use App\Service\ShortUrlGenerator;
use App\Service\QrCodeGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class HomeController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ShortUrlGenerator $shortUrlGenerator,
        private readonly QrCodeGenerator $qrCodeGenerator,
        private readonly RateLimiterFactory $anonymousLimiter,
        private readonly ShortUrlRepository $shortUrlRepository,
    ) {
    }

    /**
     * Главная страница с формой
     */
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // Получаем последние сокращенные ссылки для примера
        $recentUrls = $this->shortUrlRepository->findBy([], ['createdAt' => 'DESC'], 5);

        return $this->render('home/index2.html.twig', [
            'recent_urls' => $recentUrls,
        ]);
    }

    /**
     * Создание короткой ссылки (AJAX/API endpoint)
     */
    #[Route('/shorten', name: 'create_link', methods: ['POST'])]
    public function createLink(Request $request): JsonResponse
    {
        // Проверка rate limiting
        $limiter = $this->anonymousLimiter->create($request->getClientIp());
        $limit = $limiter->consume();

        if (!$limit->isAccepted()) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Слишком много запросов. Попробуйте позже.',
                'retry_after' => $limit->getRetryAfter()->getTimestamp(),
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        // Получаем данные из запроса
        $data = json_decode($request->getContent(), true);

        if (!$data || !isset($data['url'])) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Неверный формат запроса. Укажите URL.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $longUrl = $data['url'];
        $customAlias = $data['customAlias'] ?? null;
        $expiresAt = isset($data['expires'])
            ? new \DateTime('+' . $data['expires'] . ' days')
            : null;

        // Валидация URL
        if (!filter_var($longUrl, FILTER_VALIDATE_URL)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Некорректный URL. Укажите полный URL (с http:// или https://).',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Проверка кастомного алиаса
        if ($customAlias && $this->shortUrlRepository->customAliasExists($customAlias)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Этот псевдоним уже занят. Выберите другой.',
            ], Response::HTTP_CONFLICT);
        }

        try {
            // Создаем сущность ShortUrl
            $shortUrl = new ShortUrl();
            $shortUrl->setLongUrl($longUrl);

            // Устанавливаем пользователя, если авторизован
            if ($this->getUser()) {
                $shortUrl->setUser($this->getUser());
            }

            // Генерируем короткий код
            $shortCode = $customAlias ?? $this->shortUrlGenerator->generate();
            $shortUrl->setShortCode($shortCode);

            if ($customAlias) {
                $shortUrl->setCustomAlias($customAlias);
            }

            if ($expiresAt) {
                $shortUrl->setExpiresAt($expiresAt);
            }

            // Генерируем QR код
            $qrCodePath = $this->qrCodeGenerator->generateForUrl($shortUrl->getShortUrl());
            $shortUrl->setQrCodePath($qrCodePath);

            // Сохраняем в базу
            $this->entityManager->persist($shortUrl);
            $this->entityManager->flush();

            // Возвращаем успешный ответ
            return new JsonResponse([
                'success' => true,
                'shortUrl' => $shortUrl->getShortUrl(),
                'shortCode' => $shortCode,
                'originalUrl' => $longUrl,
                'qrCode' => $this->getQrCodeUrl($qrCodePath),
                'expiresAt' => $shortUrl->getExpiresAt()?->format('Y-m-d H:i:s'),
                'clicks' => $shortUrl->getClicks(),
                'createdAt' => $shortUrl->getCreatedAt()->format('Y-m-d H:i:s'),
            ]);

        } catch (\Exception $e) {
            // Логируем ошибку
            error_log('Error creating short URL: ' . $e->getMessage());

            return new JsonResponse([
                'success' => false,
                'error' => 'Произошла ошибка при создании короткой ссылки.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Страница с информацией о ссылке
     */
    #[Route('/link/{shortCode}', name: 'app_link_info', methods: ['GET'])]
    public function linkInfo(string $shortCode): Response
    {
        $shortUrl = $this->shortUrlRepository->findActiveByShortCode($shortCode);

        if (!$shortUrl) {
            throw $this->createNotFoundException('Ссылка не найдена');
        }

        return $this->render('home/link_info.html.twig', [
            'short_url' => $shortUrl,
        ]);
    }

    /**
     * API для проверки доступности кастомного алиаса
     */
    #[Route('/api/check-alias', name: 'app_check_alias', methods: ['POST'])]
    public function checkAlias(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $customAlias = $data['alias'] ?? null;

        if (!$customAlias) {
            return new JsonResponse([
                'available' => false,
                'error' => 'Укажите псевдоним для проверки',
            ]);
        }

        $exists = $this->shortUrlRepository->customAliasExists($customAlias);

        return new JsonResponse([
            'available' => !$exists,
            'alias' => $customAlias,
        ]);
    }

    /**
     * Получение QR кода по shortCode
     */
    #[Route('/qr/{shortCode}', name: 'app_qr_code', methods: ['GET'])]
    public function getQrCode(string $shortCode): Response
    {
        $fileName = basename($shortCode) . '.png';
        $fullPath = __DIR__ . '/../../public/uploads/qr-codes/' . $fileName;
        if (!file_exists($fullPath)) {
            throw new FileNotFoundException("QR code не найден: {$fileName}");
        }

        return $this->file($fullPath);
    }

    /**
     * Помощник для получения URL QR кода
     */
    private function getQrCodeUrl(string $path): string
    {
        $filename = basename($path);
        return $this->generateUrl('app_qr_code', ['shortCode' => pathinfo($filename, PATHINFO_FILENAME)]);
    }
}
