<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\CreateShortUrl;
use App\Entity\ShortUrl;
use App\Exception\AliasExistException;
use App\Repository\ShortUrlRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ShortUrlService
{
    public function __construct(
        private ShortUrlGenerator $shortUrlGenerator,
        private QrCodeGenerator $qrCodeGenerator,
        private ShortUrlRepository $shortUrlRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function createShortLink(CreateShortUrl $shortUrlDto): ShortUrl
    {
        if ($shortUrlDto->customAlias && $this->shortUrlRepository->customAliasExists($shortUrlDto->customAlias)) {
            throw new AliasExistException(sprintf('Алиас "%s" уже существует', $shortUrlDto->customAlias));
        }

        // Создаем сущность ShortUrl
        $shortUrl = new ShortUrl();
        $shortUrl->setLongUrl($shortUrlDto->url);

        // Устанавливаем пользователя, если авторизован
        if ($shortUrlDto->user) {
            $shortUrl->setUser($shortUrlDto->user);
        }

        // Генерируем короткий код
        $shortCode = $shortUrlDto->customAlias ?? $this->shortUrlGenerator->generate();
        $shortUrl->setShortCode($shortCode);

        if ($shortUrlDto->customAlias) {
            $shortUrl->setCustomAlias($shortUrlDto->customAlias);
        }

        if ($shortUrlDto->expiresAt) {
            $shortUrl->setExpiresAt($shortUrlDto->expiresAt);
        }

        // Генерируем QR код
        $qrCodePath = $this->qrCodeGenerator->generateForUrl($shortUrl->getShortUrl());
        $shortUrl->setQrCodePath($qrCodePath);

        // Сохраняем в базу
        $this->entityManager->persist($shortUrl);
        $this->entityManager->flush();

        return $shortUrl;
    }


}
