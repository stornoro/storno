<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Second pass of the SPV date repair: days 01-09 produced "years" like 0210
 * (raw 02092026… read as year 0209, month 20 → 0210-08), which the first pass
 * (>= 2100) did not catch. Same deterministic mapping: ddmm = year - 1, yyyy = 2000 + day.
 */
final class Version20260904240000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Repair SPV document dates with years below 1900 (day-first parse, days 01-09)';
    }

    public function up(Schema $schema): void
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, anaf_created_at FROM spv_document WHERE anaf_created_at IS NOT NULL AND anaf_created_at < '1900-01-01'"
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
            $this->connection->update('spv_document', ['anaf_created_at' => sprintf('%04d-%02d-%02d %s', $year, $month, $day, $bad->format('H:i:s'))], ['id' => $row['id']]);
        }
    }

    public function down(Schema $schema): void
    {
    }
}
