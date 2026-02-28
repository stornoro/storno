<?php

namespace App\Entity;

use App\Doctrine\Type\UuidType;
use App\Repository\AnafTokenRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AnafTokenRepository::class)]
#[ORM\HasLifecycleCallbacks]
class AnafToken
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private ?Uuid $id = null;

    #[ORM\Column(type: "text")]
    private ?string $token = null;

    #[ORM\Column(type: "text", nullable: true)]
    private ?string $refreshToken = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $expireAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\ManyToOne(inversedBy: 'anafTokens', cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false)]
    #[Ignore]
    private ?User $user = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(nullable: true)]
    private ?array $validatedCifs = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    #[ORM\PrePersist]
    public function prePersist()
    {
        if (!$this->createdAt) {
            $this->createdAt = new \DateTimeImmutable();
        }
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function setToken(string $token): static
    {
        $this->token = $token;

        return $this;
    }

    public function getExpireAt(): ?\DateTimeImmutable
    {
        return $this->expireAt;
    }

    public function setExpireAt(\DateTimeImmutable $expireAt): static
    {
        $this->expireAt = $expireAt;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
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

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(?\DateTimeImmutable $lastUsedAt): static
    {
        $this->lastUsedAt = $lastUsedAt;

        return $this;
    }

    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function setRefreshToken(?string $refreshToken): self
    {
        $this->refreshToken = $refreshToken;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getValidatedCifs(): ?array
    {
        return $this->validatedCifs;
    }

    public function setValidatedCifs(?array $validatedCifs): static
    {
        $this->validatedCifs = $validatedCifs;

        return $this;
    }

    public function addValidatedCif(int $cif): static
    {
        $cifs = $this->validatedCifs ?? [];
        if (!in_array($cif, $cifs, true)) {
            $cifs[] = $cif;
            $this->validatedCifs = $cifs;
        }

        return $this;
    }

    public function removeValidatedCif(int $cif): static
    {
        $cifs = $this->validatedCifs ?? [];
        $this->validatedCifs = array_values(array_filter($cifs, fn(int $c) => $c !== $cif));

        return $this;
    }

    public function hasValidatedCif(int $cif): bool
    {
        return in_array($cif, $this->validatedCifs ?? [], true);
    }

    public function isExpired(): bool
    {
        if (!$this->expireAt) {
            return true;
        }

        return $this->expireAt < new \DateTimeImmutable();
    }
}
