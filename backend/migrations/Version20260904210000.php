<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Repair spv_document.anaf_created_at rows ingested with the wrong date layout.
 *
 * ANAF SPV sends DDMMYYYYHHMMSS; the first parser read it as YYYYMMDDHHMMSS, so
 * "31082026094216" became year 3108, month 20 (overflowed to 3109-08), day 26.
 * The mapping is deterministic: ddmm = year - 1, yyyy = 2000 + day, time intact.
 */
final class Version20260904210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Repair SPV document dates parsed as year-first instead of day-first';
    }

    public function up(Schema $schema): void
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, anaf_created_at FROM spv_document WHERE anaf_created_at IS NOT NULL AND anaf_created_at >= '2100-01-01'"
        );
        foreach ($rows as $row) {
            $bad = new \DateTimeImmutable((string) $row['anaf_created_at']);
            $ddmm = (int) $bad->format('Y') - 1;
            $day = intdiv($ddmm, 100);
            $month = $ddmm % 100;
            $year = 2000 + (int) $bad->format('d');
            if (!checkdate($month, $day, $year)) {
                $this->connection->update('spv_document', ['anaf_created_at' => null], ['id' => $row['id']]);
                continue;
            }
            $fixed = sprintf('%04d-%02d-%02d %s', $year, $month, $day, $bad->format('H:i:s'));
            $this->connection->update('spv_document', ['anaf_created_at' => $fixed], ['id' => $row['id']]);
        }
    }

    public function down(Schema $schema): void
    {
        // Irreversible data repair.
    }
}
