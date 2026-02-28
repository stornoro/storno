<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260225183245 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE invoice ADD tva_la_incasare TINYINT(1) DEFAULT 0 NOT NULL, ADD platitor_tva TINYINT(1) DEFAULT 0 NOT NULL, ADD plata_online TINYINT(1) DEFAULT 0 NOT NULL, ADD show_client_balance TINYINT(1) DEFAULT 0 NOT NULL, ADD client_balance_existing NUMERIC(12, 2) DEFAULT NULL, ADD client_balance_overdue NUMERIC(12, 2) DEFAULT NULL, ADD tax_point_date DATE DEFAULT NULL, ADD tax_point_date_code VARCHAR(10) DEFAULT NULL, ADD buyer_reference VARCHAR(255) DEFAULT NULL, ADD receiving_advice_reference VARCHAR(255) DEFAULT NULL, ADD despatch_advice_reference VARCHAR(255) DEFAULT NULL, ADD tender_or_lot_reference VARCHAR(255) DEFAULT NULL, ADD invoiced_object_identifier VARCHAR(255) DEFAULT NULL, ADD buyer_accounting_reference VARCHAR(255) DEFAULT NULL, ADD business_process_type VARCHAR(255) DEFAULT NULL, ADD payee_name VARCHAR(255) DEFAULT NULL, ADD payee_identifier VARCHAR(255) DEFAULT NULL, ADD payee_legal_registration_identifier VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE user DROP credits');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE invoice DROP tva_la_incasare, DROP platitor_tva, DROP plata_online, DROP show_client_balance, DROP client_balance_existing, DROP client_balance_overdue, DROP tax_point_date, DROP tax_point_date_code, DROP buyer_reference, DROP receiving_advice_reference, DROP despatch_advice_reference, DROP tender_or_lot_reference, DROP invoiced_object_identifier, DROP buyer_accounting_reference, DROP business_process_type, DROP payee_name, DROP payee_identifier, DROP payee_legal_registration_identifier');
        $this->addSql('ALTER TABLE user ADD credits INT NOT NULL');
    }
}
