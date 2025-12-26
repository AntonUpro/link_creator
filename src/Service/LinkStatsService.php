<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ShortUrl;
use App\Entity\LinkClick;
use App\Repository\LinkClickRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

class LinkStatsService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LinkClickRepository $linkClickRepository
    ) {}

    /**
     * Сбор статистики для перехода
     */
    public function collectClickStats(ShortUrl $shortUrl, Request $request): LinkClick
    {
        $linkClick = new LinkClick();
        $linkClick->setShortUrl($shortUrl);
        $linkClick->setIpAddress($request->getClientIp());
        $linkClick->setUserAgent($request->headers->get('User-Agent'));
        $linkClick->setReferer($request->headers->get('Referer'));
        $linkClick->setCreatedAt(new \DateTimeImmutable());

        // Определяем страну по IP
        $country = $this->detectCountry($request->getClientIp());
        if ($country) {
            $linkClick->setCountry($country);
        }

        // Определяем устройство и браузер
        $deviceInfo = $this->parseUserAgent($request->headers->get('User-Agent'));
        // Можно сохранить дополнительную информацию если нужно

        $this->entityManager->persist($linkClick);

        // Обновляем счетчик кликов в shortUrl
        $shortUrl->incrementClicks();

        $this->entityManager->flush();

        return $linkClick;
    }

    /**
     * Определение страны по IP
     */
    private function detectCountry(?string $ip): ?string
    {
        if (!$ip || $ip === '127.0.0.1' || $ip === '::1') {
            return null;
        }

        // Используем кэширование для повторных запросов
        static $ipCache = [];

        if (isset($ipCache[$ip])) {
            return $ipCache[$ip];
        }

        try {
            // Первый способ: через локальную базу данных (если есть)
            // Второй способ: через внешний сервис
            $url = "http://ip-api.com/json/{$ip}?fields=countryCode";
            $response = @file_get_contents($url, false, stream_context_create([
                'http' => ['timeout' => 1]
            ]));

            if ($response) {
                $data = json_decode($response, true);
                if ($data && isset($data['countryCode'])) {
                    $ipCache[$ip] = $data['countryCode'];
                    return $data['countryCode'];
                }
            }
        } catch (\Exception $e) {
            // Игнорируем ошибки определения страны
        }

        $ipCache[$ip] = null;
        return null;
    }

    /**
     * Парсинг User-Agent для определения устройства и браузера
     */
    private function parseUserAgent(?string $userAgent): array
    {
        if (!$userAgent) {
            return ['device' => 'unknown', 'browser' => 'unknown', 'os' => 'unknown'];
        }

        $device = 'Desktop';
        $browser = 'Unknown';
        $os = 'Unknown';

        // Определение устройства
        if (preg_match('/Mobile|Android|iPhone|iPad|iPod|BlackBerry|Windows Phone/i', $userAgent)) {
            $device = 'Mobile';
        } elseif (preg_match('/Tablet|iPad/i', $userAgent)) {
            $device = 'Tablet';
        }

        // Определение браузера
        if (preg_match('/Chrome/i', $userAgent) && !preg_match('/Edg/i', $userAgent)) {
            $browser = 'Chrome';
        } elseif (preg_match('/Firefox/i', $userAgent)) {
            $browser = 'Firefox';
        } elseif (preg_match('/Safari/i', $userAgent) && !preg_match('/Chrome/i', $userAgent)) {
            $browser = 'Safari';
        } elseif (preg_match('/Edg/i', $userAgent)) {
            $browser = 'Edge';
        } elseif (preg_match('/Opera|OPR/i', $userAgent)) {
            $browser = 'Opera';
        }

        // Определение операционной системы
        if (preg_match('/Windows NT/i', $userAgent)) {
            $os = 'Windows';
        } elseif (preg_match('/Mac OS X/i', $userAgent)) {
            $os = 'macOS';
        } elseif (preg_match('/Linux/i', $userAgent)) {
            $os = 'Linux';
        } elseif (preg_match('/Android/i', $userAgent)) {
            $os = 'Android';
        } elseif (preg_match('/iPhone|iPad|iPod/i', $userAgent)) {
            $os = 'iOS';
        }

        return [
            'device' => $device,
            'browser' => $browser,
            'os' => $os,
        ];
    }

    /**
     * Получение статистики за период
     */
    public function getStatsForPeriod(
        ShortUrl $shortUrl,
        \DateTimeInterface $startDate,
        \DateTimeInterface $endDate,
        string $groupBy = 'day'
    ): array {
        return $this->linkClickRepository->getStatsForShortUrl($shortUrl, $startDate, $endDate, $groupBy);
    }

    /**
     * Получение топ стран
     */
    public function getTopCountries(ShortUrl $shortUrl, int $limit = 10): array
    {
        return $this->linkClickRepository->getTopCountries($shortUrl, $limit);
    }

    /**
     * Получение топ рефереров
     */
    public function getTopReferrers(ShortUrl $shortUrl, int $limit = 10): array
    {
        return $this->linkClickRepository->getTopReferrers($shortUrl, $limit);
    }

    /**
     * Статистика по браузерам и устройствам
     */
    public function getBrowserStats(ShortUrl $shortUrl): array
    {
        return $this->linkClickRepository->getBrowserStats($shortUrl);
    }

    /**
     * Получение уникальных посетителей
     */
    public function getUniqueVisitors(ShortUrl $shortUrl, ?\DateTimeInterface $startDate = null): int
    {
        return $this->linkClickRepository->getUniqueVisitors($shortUrl, $startDate);
    }

    /**
     * Географическое распределение кликов
     */
    public function getGeographicDistribution(ShortUrl $shortUrl): array
    {
        return $this->linkClickRepository->getGeographicDistribution($shortUrl);
    }

    /**
     * Клики за последние N дней
     */
    public function getClicksLastDays(ShortUrl $shortUrl, int $days = 30): array
    {
        return $this->linkClickRepository->getClicksLastDays($shortUrl, $days);
    }

    /**
     * Получение пиковых часов активности
     */
    public function getPeakHours(ShortUrl $shortUrl): array
    {
        return $this->linkClickRepository->getPeakHours($shortUrl);
    }

    /**
     * Получение средней частоты кликов
     */
    public function getAverageTimeBetweenClicks(ShortUrl $shortUrl): ?float
    {
        return $this->linkClickRepository->getAverageTimeBetweenClicks($shortUrl);
    }

    /**
     * Генерация отчета в формате CSV
     */
    public function generateCsvReport(ShortUrl $shortUrl, array $data): string
    {
        $output = fopen('php://temp', 'r+');

        // Заголовки CSV
        fputcsv($output, ['Дата', 'Клики', 'Уникальные посетители']);

        // Данные
        foreach ($data as $row) {
            fputcsv($output, [
                $row['period'],
                $row['clicks'],
                $row['unique_visitors'] ?? 0,
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Получение общей статистики по всем ссылкам пользователя
     */
    public function getUserOverallStats(int $userId): array
    {
        $conn = $this->entityManager->getConnection();

        $sql = "
            SELECT
                COUNT(s.id) as total_links,
                SUM(s.clicks) as total_clicks,
                AVG(s.clicks) as avg_clicks_per_link,
                MAX(s.clicks) as max_clicks,
                SUM(CASE WHEN s.is_active = 1 THEN 1 ELSE 0 END) as active_links,
                COUNT(DISTINCT DATE(s.created_at)) as active_days,
                MIN(s.created_at) as first_link_date,
                MAX(s.updated_at) as last_activity_date
            FROM short_urls s
            WHERE s.user_id = :userId
        ";

        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery(['userId' => $userId]);

        return $result->fetchAssociative() ?: [];
    }

    /**
     * Получение последних кликов с деталями
     */
    public function getRecentClicksWithDetails(ShortUrl $shortUrl, int $limit = 50): array
    {
        $clicks = $this->linkClickRepository->findByShortUrl($shortUrl, $limit);

        $result = [];
        foreach ($clicks as $click) {
            $uaInfo = $this->parseUserAgent($click->getUserAgent());

            $result[] = [
                'id' => $click->getId(),
                'ip_address' => $click->getIpAddress(),
                'country' => $click->getCountry(),
                'referer' => $click->getReferer(),
                'created_at' => $click->getCreatedAt()->format('Y-m-d H:i:s'),
                'device' => $uaInfo['device'],
                'browser' => $uaInfo['browser'],
                'os' => $uaInfo['os'],
            ];
        }

        return $result;
    }

    /**
     * Проверка на мошенническую активность
     */
    public function detectFraudActivity(ShortUrl $shortUrl, int $timeWindowMinutes = 5, int $maxClicks = 50): bool
    {
        $conn = $this->entityManager->getConnection();

        $sql = "
            SELECT COUNT(*) as click_count
            FROM link_clicks
            WHERE short_url_id = :shortUrlId
              AND created_at > DATE_SUB(NOW(), INTERVAL :minutes MINUTE)
        ";

        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery([
            'shortUrlId' => $shortUrl->getId(),
            'minutes' => $timeWindowMinutes,
        ]);

        $data = $result->fetchAssociative();

        return ($data['click_count'] ?? 0) > $maxClicks;
    }
}
