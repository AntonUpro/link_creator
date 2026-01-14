<?php

declare(strict_types=1);

namespace App\Controller;

use App\Constraint\CreateLinkConstraint;
use App\Dto\CreateShortUrl;
use App\Entity\User;
use App\Exception\AliasExistException;
use App\Repository\ShortUrlRepository;
use App\Service\ShortUrlService;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class HomeController extends AbstractController
{
    public function __construct(
        private readonly RateLimiterFactory $anonymousLimiter,
        private readonly ShortUrlRepository $shortUrlRepository,
        private readonly CreateLinkConstraint $createLinkConstraint,
        private readonly ShortUrlService $shortUrlService,
        private readonly ValidatorInterface $validator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Главная страница с формой
     */
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
        $recentUrls = [];

        if ($user instanceof User) {
            // Получаем последние сокращенные ссылки для примера
            $recentUrls = $this->shortUrlRepository->findBy(['user' => $user], ['createdAt' => 'DESC'], 5);
        }

        return $this->render('home/index.html.twig', [
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

        // валидируем данные
        $error = $this->validator->validate($data, $this->createLinkConstraint->getConstraint());

        if (count($error) > 0) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Ошибка валидации данных: ' . $error->get(1)->getMessage(),
                'field' => $error->get(1)->getPropertyPath(),
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $shortUrl = $this->shortUrlService->createShortLink(new CreateShortUrl(
                url: $data['url'],
                customAlias: $data['customAlias'] ?? null,
                user: $this->getUser(),
                password: $data['password'] ?? null,
                expiresAt: isset($data['expires_at']) ? new DateTimeImmutable($data['expires_at']) : null,
            ));

        } catch (AliasExistException $exception){
            return new JsonResponse([
                'success' => false,
                'error' => $exception->getMessage(),
            ], Response::HTTP_CONFLICT);
        } catch (\Throwable $exception) {
            $this->logger->error('Ошибка при создании короткой ссылки: ' . $exception->getMessage(), [
                'exception' => $exception->getTraceAsString(),
                'previous' => $exception->getPrevious()?->getMessage(),
            ]);
            return new JsonResponse([
                'success' => false,
                'error' => 'Произошла ошибка. Попробуйте позже.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Возвращаем успешный ответ
        return new JsonResponse([
            'success' => true,
            'shortUrl' => $shortUrl->getShortUrl(),
            'shortCode' => $shortUrl->getShortCode(),
            'originalUrl' => $shortUrl->getLongUrl(),
            'qrCode' => $this->getQrCodeUrl($shortUrl->getQrCodePath()),
            'expiresAt' => $shortUrl->getExpiresAt()?->format('Y-m-d H:i:s'),
            'clicks' => $shortUrl->getClicks(),
            'createdAt' => $shortUrl->getCreatedAt()->format('Y-m-d H:i:s'),
        ]);
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
