<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\User;
use DateTimeInterface;

final readonly class CreateShortUrl
{
    public function __construct(
        public string $url,
        public ?string $customAlias,
        public ?User $user,
        public ?string $password,
        public ?DateTimeInterface $expiresAt,
        public bool $isActive = true,
    ) {
    }
}
