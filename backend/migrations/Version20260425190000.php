<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260425190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add refund_of self-reference to receipt for POS refund flow';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE receipt ADD refund_of_id CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE receipt ADD CONSTRAINT FK_RECEIPT_REFUND_OF FOREIGN KEY (refund_of_id) REFERENCES receipt (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_RECEIPT_REFUND_OF ON receipt (refund_of_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE receipt DROP FOREIGN KEY FK_RECEIPT_REFUND_OF');
        $this->addSql('DROP INDEX IDX_RECEIPT_REFUND_OF ON receipt');
        $this->addSql('ALTER TABLE receipt DROP refund_of_id');
    }
}
