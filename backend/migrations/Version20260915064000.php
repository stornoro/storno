<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260915064000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Partner verification snapshot (ANAF / VIES) and partner rules (status, credit limit, affiliated) on client and supplier';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client ADD vat_status_checked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD vat_registered TINYINT(1) DEFAULT NULL, ADD vat_on_collection TINYINT(1) DEFAULT NULL, ADD vat_on_collection_from DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\', ADD vat_on_collection_to DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\', ADD inactive TINYINT(1) DEFAULT NULL, ADD efactura_registered TINYINT(1) DEFAULT NULL, ADD verification_notes VARCHAR(500) DEFAULT NULL, ADD affiliated TINYINT(1) DEFAULT 0 NOT NULL, ADD status VARCHAR(20) DEFAULT \'active\' NOT NULL, ADD credit_limit NUMERIC(15, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE supplier ADD vat_status_checked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD vat_registered TINYINT(1) DEFAULT NULL, ADD vat_on_collection TINYINT(1) DEFAULT NULL, ADD vat_on_collection_from DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\', ADD vat_on_collection_to DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\', ADD inactive TINYINT(1) DEFAULT NULL, ADD efactura_registered TINYINT(1) DEFAULT NULL, ADD verification_notes VARCHAR(500) DEFAULT NULL, ADD affiliated TINYINT(1) DEFAULT 0 NOT NULL, ADD vies_valid TINYINT(1) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client DROP vat_status_checked_at, DROP vat_registered, DROP vat_on_collection, DROP vat_on_collection_from, DROP vat_on_collection_to, DROP inactive, DROP efactura_registered, DROP verification_notes, DROP affiliated, DROP status, DROP credit_limit');
        $this->addSql('ALTER TABLE supplier DROP vat_status_checked_at, DROP vat_registered, DROP vat_on_collection, DROP vat_on_collection_from, DROP vat_on_collection_to, DROP inactive, DROP efactura_registered, DROP verification_notes, DROP affiliated, DROP vies_valid');
    }
}
