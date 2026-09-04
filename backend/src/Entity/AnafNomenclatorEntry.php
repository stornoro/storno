<?php

namespace App\Entity;

use App\Repository\AnafNomenclatorRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row of ANAF's address / fiscal-office nomenclators, mirrored locally so
 * declaration forms (C168, D212, D700 …) can resolve county, locality, street
 * and fiscal-office codes instantly and offline from ANAF.
 *
 * kind: judet (parentKey ''), localitate (parentKey = judet code),
 *       strada (parentKey = "<judet>-<localitate>"), organ_fiscal (parentKey = judet code)
 */
#[ORM\Entity(repositoryClass: AnafNomenclatorRepository::class)]
#[ORM\Table(name: 'anaf_nomenclator')]
#[ORM\UniqueConstraint(name: 'uniq_nom_kind_parent_code', columns: ['kind', 'parent_key', 'code'])]
#[ORM\Index(name: 'idx_nom_kind_parent_name', columns: ['kind', 'parent_key', 'name_normalized'])]
class AnafNomenclatorEntry
{
    public const KIND_JUDET = 'judet';
    public const KIND_LOCALITATE = 'localitate';
    public const KIND_STRADA = 'strada';
    public const KIND_ORGAN_FISCAL = 'organ_fiscal';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16)]
    private string $kind = '';

    #[ORM\Column(length: 32)]
    private string $parentKey = '';

    #[ORM\Column(length: 32)]
    private string $code = '';

    #[ORM\Column(length: 255)]
    private string $name = '';

    /** Lowercase, diacritics stripped, single spaces: what searches run against. */
    #[ORM\Column(length: 255)]
    private string $nameNormalized = '';

    /** @var array<string, mixed> siruta, codPrimarie, arondare … */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $extra = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $syncedAt;

    public function __construct()
    {
        $this->syncedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getKind(): string { return $this->kind; }
    public function setKind(string $v): static { $this->kind = $v; return $this; }
    public function getParentKey(): string { return $this->parentKey; }
    public function setParentKey(string $v): static { $this->parentKey = $v; return $this; }
    public function getCode(): string { return $this->code; }
    public function setCode(string $v): static { $this->code = $v; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; $this->nameNormalized = self::normalize($v); return $this; }
    public function getNameNormalized(): string { return $this->nameNormalized; }
    /** @return array<string, mixed>|null */
    public function getExtra(): ?array { return $this->extra; }
    /** @param array<string, mixed>|null $v */
    public function setExtra(?array $v): static { $this->extra = $v; return $this; }
    public function getSyncedAt(): \DateTimeImmutable { return $this->syncedAt; }
    public function touch(): static { $this->syncedAt = new \DateTimeImmutable(); return $this; }

    /** "Bld. Iuliu Maniu" → "bld. iuliu maniu"; "Şoseaua Ştefan cel Mare" → "soseaua stefan cel mare". */
    public static function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);

        return preg_replace('/\s+/', ' ', $s) ?? $s;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['code' => $this->code, 'name' => $this->name] + ($this->extra ?? []);
    }
}
