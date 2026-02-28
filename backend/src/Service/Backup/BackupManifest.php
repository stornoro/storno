<?php

namespace App\Service\Backup;

class BackupManifest
{
    public const VERSION = '1.0';

    public function __construct(
        public readonly string $version,
        public readonly string $generator,
        public readonly string $companyName,
        public readonly string $companyCui,
        public readonly string $createdAt,
        public readonly array $entityCounts,
        public readonly string $checksum,
        public readonly bool $includesFiles = true,
    ) {}

    public static function create(
        string $companyName,
        string $companyCui,
        array $entityCounts,
        string $checksum,
        bool $includesFiles = true,
    ): self {
        return new self(
            version: self::VERSION,
            generator: 'Storno.ro',
            companyName: $companyName,
            companyCui: $companyCui,
            createdAt: (new \DateTimeImmutable())->format('c'),
            entityCounts: $entityCounts,
            checksum: $checksum,
            includesFiles: $includesFiles,
        );
    }

    public function toJson(): string
    {
        return json_encode([
            'version' => $this->version,
            'generator' => $this->generator,
            'company' => [
                'name' => $this->companyName,
                'cui' => $this->companyCui,
            ],
            'createdAt' => $this->createdAt,
            'entityCounts' => $this->entityCounts,
            'checksum' => $this->checksum,
            'includesFiles' => $this->includesFiles,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);

        if (!$data || !isset($data['version'])) {
            throw new \InvalidArgumentException('Invalid backup manifest JSON');
        }

        return new self(
            version: $data['version'],
            generator: $data['generator'] ?? 'unknown',
            companyName: $data['company']['name'] ?? '',
            companyCui: $data['company']['cui'] ?? '',
            createdAt: $data['createdAt'] ?? '',
            entityCounts: $data['entityCounts'] ?? [],
            checksum: $data['checksum'] ?? '',
            includesFiles: $data['includesFiles'] ?? true,
        );
    }

    public function isCompatible(): bool
    {
        return version_compare($this->version, self::VERSION, '<=');
    }

    public function getTotalEntityCount(): int
    {
        return array_sum($this->entityCounts);
    }
}
