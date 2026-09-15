<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260915090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fleet (parc auto): vehicles and expiry items with reminders (RCA, ITP, rovinietă, CASCO, tahograf, certificat digital, contracts …)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE vehicle (id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', company_id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', plate VARCHAR(20) NOT NULL, vin VARCHAR(32) DEFAULT NULL, make VARCHAR(60) DEFAULT NULL, model VARCHAR(60) DEFAULT NULL, year SMALLINT DEFAULT NULL, fuel VARCHAR(20) DEFAULT NULL, ownership VARCHAR(16) NOT NULL, driver_name VARCHAR(120) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, active TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_VEHICLE_COMPANY (company_id), INDEX idx_vehicle_company_plate (company_id, plate), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE vehicle ADD CONSTRAINT FK_VEHICLE_COMPANY FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE expiry_item (id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', company_id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', vehicle_id CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\', renewed_from_id CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\', kind VARCHAR(32) NOT NULL, label VARCHAR(160) NOT NULL, number VARCHAR(100) DEFAULT NULL, provider VARCHAR(120) DEFAULT NULL, valid_from DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\', expires_at DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', remind_days_before SMALLINT NOT NULL, notified JSON NOT NULL, notes LONGTEXT DEFAULT NULL, closed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_EXPIRY_COMPANY (company_id), INDEX IDX_EXPIRY_RENEWED_FROM (renewed_from_id), INDEX idx_expiry_company_expires (company_id, expires_at), INDEX idx_expiry_vehicle (vehicle_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE expiry_item ADD CONSTRAINT FK_EXPIRY_COMPANY FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE expiry_item ADD CONSTRAINT FK_EXPIRY_VEHICLE FOREIGN KEY (vehicle_id) REFERENCES vehicle (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE expiry_item ADD CONSTRAINT FK_EXPIRY_RENEWED_FROM FOREIGN KEY (renewed_from_id) REFERENCES expiry_item (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE expiry_item');
        $this->addSql('DROP TABLE vehicle');
    }
}
