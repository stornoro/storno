<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260304084906 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recurring_invoice ADD last_invoice_id CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\', ADD last_document_type VARCHAR(20) DEFAULT NULL');

        // Backfill from invoices
        $this->addSql('UPDATE recurring_invoice ri JOIN invoice i ON i.number = ri.last_invoice_number AND i.company_id = ri.company_id SET ri.last_invoice_id = i.id, ri.last_document_type = \'invoice\' WHERE ri.last_invoice_number IS NOT NULL AND ri.last_invoice_id IS NULL AND ri.document_type = \'invoice\'');

        // Backfill from proforma invoices
        $this->addSql('UPDATE recurring_invoice ri JOIN proforma_invoice p ON p.number = ri.last_invoice_number AND p.company_id = ri.company_id SET ri.last_invoice_id = p.id, ri.last_document_type = \'proforma\' WHERE ri.last_invoice_number IS NOT NULL AND ri.last_invoice_id IS NULL AND ri.document_type = \'proforma\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recurring_invoice DROP last_invoice_id, DROP last_document_type');
    }
}
