<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260513110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add payee_bank_account / payee_bank_name to invoice and backfill from supplier';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice ADD payee_bank_account VARCHAR(34) DEFAULT NULL, ADD payee_bank_name VARCHAR(255) DEFAULT NULL');
        $this->addSql(
            'UPDATE invoice i'
            . ' INNER JOIN supplier s ON s.id = i.supplier_id'
            . ' SET i.payee_bank_account = s.bank_account, i.payee_bank_name = s.bank_name'
            . ' WHERE i.payee_bank_account IS NULL AND s.bank_account IS NOT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice DROP payee_bank_account, DROP payee_bank_name');
    }
}
