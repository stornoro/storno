<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260225184144 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE delivery_note_line ADD line_note VARCHAR(500) DEFAULT NULL, ADD buyer_accounting_ref VARCHAR(255) DEFAULT NULL, ADD buyer_item_identification VARCHAR(255) DEFAULT NULL, ADD standard_item_identification VARCHAR(255) DEFAULT NULL, ADD cpv_code VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice_line ADD line_note VARCHAR(500) DEFAULT NULL, ADD buyer_accounting_ref VARCHAR(255) DEFAULT NULL, ADD buyer_item_identification VARCHAR(255) DEFAULT NULL, ADD standard_item_identification VARCHAR(255) DEFAULT NULL, ADD cpv_code VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE proforma_invoice_line ADD line_note VARCHAR(500) DEFAULT NULL, ADD buyer_accounting_ref VARCHAR(255) DEFAULT NULL, ADD buyer_item_identification VARCHAR(255) DEFAULT NULL, ADD standard_item_identification VARCHAR(255) DEFAULT NULL, ADD cpv_code VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE receipt_line ADD line_note VARCHAR(500) DEFAULT NULL, ADD buyer_accounting_ref VARCHAR(255) DEFAULT NULL, ADD buyer_item_identification VARCHAR(255) DEFAULT NULL, ADD standard_item_identification VARCHAR(255) DEFAULT NULL, ADD cpv_code VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE recurring_invoice_line ADD line_note VARCHAR(500) DEFAULT NULL, ADD buyer_accounting_ref VARCHAR(255) DEFAULT NULL, ADD buyer_item_identification VARCHAR(255) DEFAULT NULL, ADD standard_item_identification VARCHAR(255) DEFAULT NULL, ADD cpv_code VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE recurring_invoice_line DROP line_note, DROP buyer_accounting_ref, DROP buyer_item_identification, DROP standard_item_identification, DROP cpv_code');
        $this->addSql('ALTER TABLE proforma_invoice_line DROP line_note, DROP buyer_accounting_ref, DROP buyer_item_identification, DROP standard_item_identification, DROP cpv_code');
        $this->addSql('ALTER TABLE invoice_line DROP line_note, DROP buyer_accounting_ref, DROP buyer_item_identification, DROP standard_item_identification, DROP cpv_code');
        $this->addSql('ALTER TABLE receipt_line DROP line_note, DROP buyer_accounting_ref, DROP buyer_item_identification, DROP standard_item_identification, DROP cpv_code');
        $this->addSql('ALTER TABLE delivery_note_line DROP line_note, DROP buyer_accounting_ref, DROP buyer_item_identification, DROP standard_item_identification, DROP cpv_code');
    }
}
