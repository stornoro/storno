<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260302100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ubl_extensions JSON column to invoice and all line tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice ADD ubl_extensions JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice_line ADD ubl_extensions JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE proforma_invoice_line ADD ubl_extensions JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE recurring_invoice_line ADD ubl_extensions JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE delivery_note_line ADD ubl_extensions JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE receipt_line ADD ubl_extensions JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice DROP ubl_extensions');
        $this->addSql('ALTER TABLE invoice_line DROP ubl_extensions');
        $this->addSql('ALTER TABLE proforma_invoice_line DROP ubl_extensions');
        $this->addSql('ALTER TABLE recurring_invoice_line DROP ubl_extensions');
        $this->addSql('ALTER TABLE delivery_note_line DROP ubl_extensions');
        $this->addSql('ALTER TABLE receipt_line DROP ubl_extensions');
    }
}
