<?php

declare(strict_types=1);

namespace App\Service;

use Endroid\QrCode\Builder\BuilderInterface;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Label\Font\OpenSans;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

class QrCodeGenerator
{
    private string $uploadDir;
    private Filesystem $filesystem;

    public function __construct(
        private KernelInterface $kernel,
        private BuilderInterface $qrCodeBuilder
    ) {
        $this->uploadDir = $this->kernel->getProjectDir() . '/public/uploads/qr-codes/';
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

        // Опции по умолчанию
        $defaultOptions = [
            'size' => 300,
            'margin' => 10,
            'foreground_color' => ['r' => 0, 'g' => 0, 'b' => 0, 'a' => 0],
            'background_color' => ['r' => 255, 'g' => 255, 'b' => 255, 'a' => 0],
            'logo_path' => null,
            'logo_size' => 60,
            'label' => null,
            'label_font_size' => 16,
            'format' => 'png', // png, svg, jpeg
        ];

        $options = array_merge($defaultOptions, $options);

        // Создаем QR код
        $result = $this->qrCodeBuilder
            ->data($url)
            ->encoding(new Encoding('UTF-8'))
            ->errorCorrectionLevel(ErrorCorrectionLevel::High)
            ->size($options['size'])
            ->margin($options['margin'])
            ->roundBlockSizeMode(RoundBlockSizeMode::Margin)
            ->validateResult(false);

        // Настраиваем цвета
        $result->foregroundColor(
            $options['foreground_color']['r'],
            $options['foreground_color']['g'],
            $options['foreground_color']['b'],
            $options['foreground_color']['a']
        );

        $result->backgroundColor(
            $options['background_color']['r'],
            $options['background_color']['g'],
            $options['background_color']['b'],
            $options['background_color']['a']
        );

        // Добавляем логотип если указан
        if ($options['logo_path'] && file_exists($options['logo_path'])) {
            $result->logoPath($options['logo_path']);
            $result->logoResizeToWidth($options['logo_size']);
            $result->logoPunchoutBackground(true);
        }

        // Добавляем текст если указан
        if ($options['label']) {
            $result->labelText($options['label']);
            $result->labelFont(new OpenSans($options['label_font_size']));
            $result->labelAlignment(\Endroid\QrCode\Label\LabelAlignment::Center);
        }

        // Выбираем формат
        switch ($options['format']) {
            case 'svg':
                $result->writer(new SvgWriter());
                $filepath = str_replace('.png', '.svg', $filepath);
                break;
            case 'jpeg':
                $result->writer(new \Endroid\QrCode\Writer\PngWriter());
                $filepath = str_replace('.png', '.jpg', $filepath);
                break;
            default:
                $result->writer(new PngWriter());
        }

        // Сохраняем файл
        $result->save($filepath);

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
