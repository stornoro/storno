<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260905000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Local mirror of ANAF nomenclators (judete, localitati, strazi, organe fiscale)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE anaf_nomenclator (
            id INT AUTO_INCREMENT NOT NULL,
            kind VARCHAR(16) NOT NULL,
            parent_key VARCHAR(32) NOT NULL,
            code VARCHAR(32) NOT NULL,
            name VARCHAR(255) NOT NULL,
            name_normalized VARCHAR(255) NOT NULL,
            extra JSON DEFAULT NULL,
            synced_at DATETIME NOT NULL,
            UNIQUE INDEX uniq_nom_kind_parent_code (kind, parent_key, code),
            INDEX idx_nom_kind_parent_name (kind, parent_key, name_normalized),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE anaf_nomenclator');
    }
}
