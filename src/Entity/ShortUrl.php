<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ShortUrlRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ShortUrlRepository::class)]
#[ORM\Table(name: 'short_urls')]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(columns: ['short_code'], name: 'idx_short_code')]
#[ORM\Index(columns: ['user_id'], name: 'idx_user_id')]
class ShortUrl
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'shortUrls')]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $user = null;

    #[ORM\Column(length: 2048)]
    #[Assert\NotBlank]
    #[Assert\Url]
    #[Assert\Length(max: 2048)]
    private ?string $longUrl = null;

    #[ORM\Column(name: 'short_code', length: 64, unique: true)]
    private ?string $shortCode = null;

    #[ORM\Column(name: 'custom_alias', length: 64, unique: true, nullable: true)]
    #[Assert\Length(max: 64)]
    #[Assert\Regex(
        pattern: '/^[a-zA-Z0-9_-]+$/',
        message: 'Псевдоним может содержать только буквы, цифры, дефисы и подчеркивания'
    )]
    private ?string $customAlias = null;

    #[ORM\Column]
    private int $clicks = 0;

    #[ORM\Column(name: 'qr_code_path', length: 255, nullable: true)]
    private ?string $qrCodePath = null;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $expiresAt = null;

    #[ORM\Column(name: 'is_active')]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getLongUrl(): ?string
    {
        return $this->longUrl;
    }

    public function setLongUrl(string $longUrl): static
    {
        $this->longUrl = $longUrl;

        return $this;
    }

    public function getShortCode(): ?string
    {
        return $this->shortCode;
    }

    public function setShortCode(string $shortCode): static
    {
        $this->shortCode = $shortCode;

        return $this;
    }

    public function getCustomAlias(): ?string
    {
        return $this->customAlias;
    }

    public function setCustomAlias(?string $customAlias): static
    {
        $this->customAlias = $customAlias;

        return $this;
    }

    public function getClicks(): int
    {
        return $this->clicks;
    }

    public function setClicks(int $clicks): static
    {
        $this->clicks = $clicks;

        return $this;
    }

    public function incrementClicks(): static
    {
        $this->clicks++;

        return $this;
    }

    public function getQrCodePath(): ?string
    {
        return $this->qrCodePath;
    }

    public function setQrCodePath(?string $qrCodePath): static
    {
        $this->qrCodePath = $qrCodePath;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeInterface
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeInterface $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt < new \DateTime();
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    #[ORM\PrePersist]
    public function setCreatedAtValue(): void
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getShortUrl(): string
    {
        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $code = $this->customAlias ?? $this->shortCode;

        return "https://{$domain}/{$code}";
    }

    public function isActiveAndNotExpired(): bool
    {
        return $this->isActive && !$this->isExpired();
    }
}
