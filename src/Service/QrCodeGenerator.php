<?php

declare(strict_types=1);

namespace App\Service;

use Endroid\QrCode\Builder\BuilderInterface;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

class QrCodeGenerator
{
    public const QR_CODES_DIRECTORY = '/public/uploads/qr-codes/';

    private string $uploadDir;
    private Filesystem $filesystem;

    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly BuilderInterface $qrCodeBuilder
    ) {
        $this->uploadDir = $this->kernel->getProjectDir() . self::QR_CODES_DIRECTORY;
        $this->filesystem = new Filesystem();

        // Создаем директорию если не существует
        if (!$this->filesystem->exists($this->uploadDir)) {
            $this->filesystem->mkdir($this->uploadDir, 0755);
        }
    }

    /**
     * Генерация QR кода для URL
     */
    public function generateForUrl(string $url, array $options = []): string
    {
        // Генерируем уникальное имя файла
        $filename = $this->generateFilename($url);
        $filepath = $this->uploadDir . $filename;

        // Создаем QR код
        $result = $this->qrCodeBuilder->build(
            writer: new PngWriter(),
            data: $url,
            encoding: new Encoding('UTF-8'),
            size: 300,
            margin: 10,
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
            foregroundColor: new Color(0, 0, 0, 0),
            backgroundColor: new Color(255, 255, 255, 0),
            validateResult: false,
        );

        // Сохраняем файл
        $result->saveToFile($filepath);

        // Возвращаем относительный путь
        return 'uploads/qr-codes/' . basename($filepath);
    }

    /**
     * Генерация имени файла на основе URL
     */
    private function generateFilename(string $url): string
    {
        $hash = md5($url . time() . random_bytes(10));
        $shortHash = substr($hash, 0, 12);

        return 'qr_' . $shortHash . '.png';
    }

    /**
     * Генерация QR кода с кастомизацией
     */
    public function generateCustomized(string $url, array $customization = []): string
    {
        $options = [
            'size' => $customization['size'] ?? 300,
            'margin' => $customization['margin'] ?? 10,
            'foreground_color' => $this->parseColor($customization['color'] ?? '#000000'),
            'background_color' => $this->parseColor($customization['bg_color'] ?? '#FFFFFF'),
            'logo_path' => $customization['logo'] ?? null,
            'label' => $customization['label'] ?? null,
            'format' => $customization['format'] ?? 'png',
        ];

        return $this->generateForUrl($url, $options);
    }

    /**
     * Парсинг цвета из HEX в RGB
     */
    private function parseColor(string $hexColor): array
    {
        $hexColor = ltrim($hexColor, '#');

        if (strlen($hexColor) === 3) {
            $hexColor = $hexColor[0] . $hexColor[0] . $hexColor[1] . $hexColor[1] . $hexColor[2] . $hexColor[2];
        }

        $r = hexdec(substr($hexColor, 0, 2));
        $g = hexdec(substr($hexColor, 2, 2));
        $b = hexdec(substr($hexColor, 4, 2));

        return [
            'r' => $r,
            'g' => $g,
            'b' => $b,
            'a' => 0,
        ];
    }

    /**
     * Генерация QR кода в base64 (для встраивания в HTML)
     */
    public function generateBase64(string $url, array $options = []): string
    {
        $filepath = $this->generateForUrl($url, $options);
        $fullPath = $this->kernel->getProjectDir() . '/public/' . $filepath;

        if (!file_exists($fullPath)) {
            throw new \RuntimeException('QR код не найден: ' . $fullPath);
        }

        $imageData = file_get_contents($fullPath);
        $base64 = base64_encode($imageData);

        // Определяем MIME тип
        $extension = pathinfo($fullPath, PATHINFO_EXTENSION);
        $mimeType = match ($extension) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            default => 'image/png',
        };

        return 'data:' . $mimeType . ';base64,' . $base64;
    }

    /**
     * Удаление старого QR кода
     */
    public function removeOldQrCode(string $filepath): void
    {
        if (!$filepath) {
            return;
        }

        $fullPath = $this->kernel->getProjectDir() . '/public/' . $filepath;

        if ($this->filesystem->exists($fullPath)) {
            $this->filesystem->remove($fullPath);
        }
    }

    /**
     * Очистка старых QR кодов (например, старше 30 дней)
     */
    public function cleanupOldQrCodes(int $days = 30): int
    {
        $directory = $this->uploadDir;
        $files = glob($directory . 'qr_*');
        $cutoffTime = time() - ($days * 24 * 60 * 60);
        $removedCount = 0;

        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTime) {
                $this->filesystem->remove($file);
                $removedCount++;
            }
        }

        return $removedCount;
    }

    /**
     * Получить размер файла QR кода
     */
    public function getFileSize(string $filepath): ?int
    {
        $fullPath = $this->kernel->getProjectDir() . '/public/' . $filepath;

        if (file_exists($fullPath)) {
            return filesize($fullPath);
        }

        return null;
    }

    /**
     * Получить информацию о QR коде
     */
    public function getQrCodeInfo(string $filepath): array
    {
        $fullPath = $this->kernel->getProjectDir() . '/public/' . $filepath;

        if (!file_exists($fullPath)) {
            return [];
        }

        return [
            'path' => $filepath,
            'size' => filesize($fullPath),
            'created' => filemtime($fullPath),
            'mime_type' => mime_content_type($fullPath),
            'dimensions' => $this->getImageDimensions($fullPath),
        ];
    }

    /**
     * Получить размеры изображения
     */
    private function getImageDimensions(string $filepath): ?array
    {
        $info = getimagesize($filepath);

        if ($info) {
            return [
                'width' => $info[0],
                'height' => $info[1],
            ];
        }

        return null;
    }
}
