<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Payment: the cash receipt (chitanță) issued for a cash payment — series, number, date';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment ADD receipt_series_id CHAR(36) DEFAULT NULL, ADD receipt_number VARCHAR(50) DEFAULT NULL, ADD receipt_date DATE DEFAULT NULL, ADD receipt_issued_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D5B5C4C2E FOREIGN KEY (receipt_series_id) REFERENCES document_series (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_6D28840D5B5C4C2E ON payment (receipt_series_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY FK_6D28840D5B5C4C2E');
        $this->addSql('DROP INDEX IDX_6D28840D5B5C4C2E ON payment');
        $this->addSql('ALTER TABLE payment DROP receipt_series_id, DROP receipt_number, DROP receipt_date, DROP receipt_issued_at');
    }
}
