<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ShortUrl;
use App\Entity\LinkClick;
use App\Repository\ShortUrlRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;

class RedirectController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ShortUrlRepository $shortUrlRepository
    ) {}

    /**
     * Редирект по короткой ссылке
     */
    #[Route('/{shortCode}',
        name: 'app_redirect',
        methods: ['GET'],
        requirements: ['shortCode' => '[a-zA-Z0-9_-]+'],
        priority: 1 // Высокий приоритет для коротких маршрутов
    )]
    public function redirectToUrl(string $shortCode, Request $request): Response
    {
        // Ищем ссылку
        $shortUrl = $this->shortUrlRepository->findOneBy(['shortCode' => $shortCode]);

        // Если ссылка не найдена
        if (null === $shortUrl) {
            throw new NotFoundHttpException('Ссылка не найдена');
        }

        // Если ссылка не активна
        if (! $shortUrl->isActive()) {
            throw new GoneHttpException('Эта ссылка больше не активна');
        }

        // Проверяем не истекла ли ссылка
        if ($shortUrl->isExpired()) {
            $shortUrl->setIsActive(false);
            $this->entityManager->flush();

            throw new GoneHttpException('Срок действия ссылки истек');
        }

        // Собираем статистику
        $this->collectStats($shortUrl, $request);

        // Увеличиваем счетчик кликов
        $shortUrl->incrementClicks();
        $this->entityManager->flush();

        // Редирект на оригинальный URL
        return new RedirectResponse($shortUrl->getLongUrl(), Response::HTTP_MOVED_PERMANENTLY);
    }

    /**
     * Сбор статистики по переходу
     */
    private function collectStats(ShortUrl $shortUrl, Request $request): void
    {
        $linkClick = new LinkClick();
        $linkClick->setShortUrl($shortUrl);
        $linkClick->setIpAddress($request->getClientIp());
        $linkClick->setUserAgent($request->headers->get('User-Agent'));
        $linkClick->setReferer($request->headers->get('Referer'));

        // Определяем страну по IP (упрощенный вариант)
        if ($ip = $request->getClientIp()) {
            $country = $this->getCountryFromIp($ip);
            $linkClick->setCountry($country);
        }

        $this->entityManager->persist($linkClick);
    }

    /**
     * Получение страны по IP (упрощенная реализация)
     */
    private function getCountryFromIp(string $ip): ?string
    {
        // Для локальных IP возвращаем null
        if ($ip === '127.0.0.1' || $ip === '::1') {
            return 'LOCAL';
        }

        // Используем внешний сервис для определения страны
        try {
            $response = @file_get_contents("http://ip-api.com/json/{$ip}?fields=countryCode");
            if ($response) {
                $data = json_decode($response, true);
                return $data['countryCode'] ?? null;
            }
        } catch (\Exception $e) {
            // В случае ошибки просто возвращаем null
        }

        return null;
    }

    /**
     * Preview страница для ссылки (без редиректа)
     */
    #[Route('/preview/{shortCode}', name: 'app_preview', methods: ['GET'])]
    public function preview(string $shortCode): Response
    {
        $shortUrl = $this->shortUrlRepository->findActiveByShortCode($shortCode);

        if (!$shortUrl) {
            throw $this->createNotFoundException('Ссылка не найдена');
        }

        return $this->render('redirect/preview.html.twig', [
            'short_url' => $shortUrl,
            'long_url' => $shortUrl->getLongUrl(),
            'domain' => parse_url($shortUrl->getLongUrl(), PHP_URL_HOST),
        ]);
    }
}
