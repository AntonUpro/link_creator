<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ShortUrlRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\ByteString;

class ShortUrlGenerator
{
    private const CHARACTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    private const DEFAULT_LENGTH = 6;
    private const MAX_ATTEMPTS = 10;

    public function __construct(
        private ShortUrlRepository $shortUrlRepository,
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Генерация уникального короткого кода
     */
    public function generate(int $length = self::DEFAULT_LENGTH): string
    {
        $attempt = 0;

        do {
            $code = $this->generateRandomString($length);
            $exists = $this->shortUrlRepository->shortCodeExists($code);

            // Если код существует, пробуем добавить суффикс
            if ($exists && $attempt < self::MAX_ATTEMPTS) {
                $code = $this->generateWithSuffix($code, $attempt + 1);
                $exists = $this->shortUrlRepository->shortCodeExists($code);
            }

            $attempt++;
        } while ($exists && $attempt < self::MAX_ATTEMPTS);

        // Если все попытки исчерпаны, увеличиваем длину
        if ($exists) {
            $code = $this->generate($length + 1);
        }

        return $code;
    }

    /**
     * Генерация короткого кода на основе URL (хеширование)
     */
    public function generateFromUrl(string $url, int $length = self::DEFAULT_LENGTH): string
    {
        // Создаем хеш URL
        $hash = md5($url . microtime());

        // Преобразуем хеш в base62
        $base62 = $this->base62Encode($hash);

        // Берем первые $length символов
        $code = substr($base62, 0, $length);

        // Проверяем уникальность
        if ($this->shortUrlRepository->shortCodeExists($code)) {
            // Если не уникален, добавляем суффикс
            $code = $this->shortUrlRepository->getNextAvailableShortCode($code);
        }

        return $code;
    }

    /**
     * Генерация читаемого кода (слово + числа)
     */
    public function generateReadableCode(): string
    {
        $adjectives = ['quick', 'smart', 'fast', 'bold', 'cool', 'wise', 'neat', 'safe', 'sure', 'true'];
        $nouns = ['link', 'url', 'web', 'site', 'path', 'route', 'gate', 'door', 'way', 'key'];
        $numbers = random_int(100, 999);

        $code = $adjectives[array_rand($adjectives)] . '-' . $nouns[array_rand($nouns)] . '-' . $numbers;

        // Проверяем уникальность
        if ($this->shortUrlRepository->shortCodeExists($code)) {
            return $this->generateReadableCode();
        }

        return $code;
    }

    /**
     * Генерация случайной строки
     */
    private function generateRandomString(int $length): string
    {
        $charactersLength = strlen(self::CHARACTERS);
        $randomString = '';

        for ($i = 0; $i < $length; $i++) {
            $randomString .= self::CHARACTERS[random_int(0, $charactersLength - 1)];
        }

        return $randomString;
    }

    /**
     * Генерация кода с суффиксом
     */
    private function generateWithSuffix(string $baseCode, int $suffix): string
    {
        // Добавляем суффикс с разделителем
        $separator = '-';
        $maxBaseLength = self::DEFAULT_LENGTH - strlen($separator) - strlen((string)$suffix);

        if (strlen($baseCode) > $maxBaseLength) {
            $baseCode = substr($baseCode, 0, $maxBaseLength);
        }

        return $baseCode . $separator . $suffix;
    }

    /**
     * Кодирование в base62
     */
    private function base62Encode(string $data): string
    {
        $base = 62;
        $characters = self::CHARACTERS;
        $result = '';
        $number = hexdec(bin2hex($data));

        while ($number > 0) {
            $remainder = $number % $base;
            $result = $characters[$remainder] . $result;
            $number = (int)($number / $base);
        }

        return $result ?: '0';
    }

    /**
     * Декодирование из base62
     */
    private function base62Decode(string $data): string
    {
        $base = 62;
        $characters = self::CHARACTERS;
        $result = 0;
        $length = strlen($data);

        for ($i = 0; $i < $length; $i++) {
            $char = $data[$i];
            $value = strpos($characters, $char);
            $result = $result * $base + $value;
        }

        return dechex($result);
    }

    /**
     * Валидация короткого кода
     */
    public function isValidShortCode(string $code): bool
    {
        // Проверяем длину (мин 3, макс 64 символа)
        if (strlen($code) < 3 || strlen($code) > 64) {
            return false;
        }

        // Проверяем разрешенные символы: буквы, цифры, дефис, подчеркивание
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $code)) {
            return false;
        }

        // Запрещаем некоторые резервные слова
        $reservedWords = [
            'admin', 'api', 'dashboard', 'login', 'logout', 'register',
            'profile', 'settings', 'help', 'about', 'contact', 'privacy',
            'terms', 'shorten', 'qr', 'preview', 'stats', 'link'
        ];

        if (in_array(strtolower($code), $reservedWords)) {
            return false;
        }

        return true;
    }

    /**
     * Создание короткого кода для пользовательского алиаса
     */
    public function createCustomAlias(string $alias): string
    {
        // Нормализуем алиас
        $alias = strtolower($alias);
        $alias = preg_replace('/[^a-z0-9_-]/', '-', $alias);
        $alias = preg_replace('/-+/', '-', $alias);
        $alias = trim($alias, '-');

        // Проверяем длину
        if (strlen($alias) < 3) {
            throw new \InvalidArgumentException('Алиас должен содержать минимум 3 символа');
        }

        if (strlen($alias) > 64) {
            $alias = substr($alias, 0, 64);
        }

        // Проверяем уникальность
        if ($this->shortUrlRepository->customAliasExists($alias)) {
            throw new \InvalidArgumentException('Этот алиас уже занят');
        }

        return $alias;
    }

    /**
     * Генерация batch коротких кодов
     */
    public function generateBatch(int $count, int $length = self::DEFAULT_LENGTH): array
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $code = $this->generate($length);
            $codes[] = $code;
        }

        return $codes;
    }

    /**
     * Получить статистику использования кодов
     */
    public function getCodeStats(): array
    {
        $conn = $this->entityManager->getConnection();

        $sql = "
            SELECT
                LENGTH(short_code) as code_length,
                COUNT(*) as count,
                AVG(clicks) as avg_clicks
            FROM short_urls
            WHERE custom_alias IS NULL
            GROUP BY code_length
            ORDER BY code_length
        ";

        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery();

        return $result->fetchAllAssociative();
    }
}
