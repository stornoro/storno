<?php

namespace App\Entity;

use App\Doctrine\Type\UuidType;
use App\Repository\DosarFileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/** A file kept in a dosar: the scanned contract, an addendum, the termination document, a signed statement. */
#[ORM\Entity(repositoryClass: DosarFileRepository::class)]
#[ORM\Table(name: 'dosar_file')]
#[ORM\Index(name: 'idx_dosar_file_dosar', columns: ['dosar_id'])]
class DosarFile
{
    public const KIND_CONTRACT = 'contract';
    public const KIND_ACT_ADITIONAL = 'act_aditional';
    public const KIND_INCETARE = 'incetare';
    public const KIND_DECLARATIE = 'declaratie';
    public const KIND_ALTELE = 'altele';
    public const KINDS = [self::KIND_CONTRACT, self::KIND_ACT_ADITIONAL, self::KIND_INCETARE, self::KIND_DECLARATIE, self::KIND_ALTELE];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['dosar_file:list'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Dosar::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Dosar $dosar = null;

    #[ORM\Column(length: 32)]
    #[Groups(['dosar_file:list'])]
    private string $kind = self::KIND_ALTELE;

    #[ORM\Column(length: 255)]
    #[Groups(['dosar_file:list'])]
    private string $name = '';

    #[ORM\Column(length: 500)]
    private string $path = '';

    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['dosar_file:list'])]
    private int $size = 0;

    #[ORM\Column(length: 100)]
    #[Groups(['dosar_file:list'])]
    private string $mime = 'application/octet-stream';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $uploadedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['dosar_file:list'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid { return $this->id; }
    public function getDosar(): ?Dosar { return $this->dosar; }
    public function setDosar(?Dosar $d): static { $this->dosar = $d; return $this; }
    public function getKind(): string { return $this->kind; }
    public function setKind(string $k): static { $this->kind = $k; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $n): static { $this->name = $n; return $this; }
    public function getPath(): string { return $this->path; }
    public function setPath(string $p): static { $this->path = $p; return $this; }
    public function getSize(): int { return $this->size; }
    public function setSize(int $s): static { $this->size = $s; return $this; }
    public function getMime(): string { return $this->mime; }
    public function setMime(string $m): static { $this->mime = $m; return $this; }
    public function getUploadedBy(): ?User { return $this->uploadedBy; }
    public function setUploadedBy(?User $u): static { $this->uploadedBy = $u; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
