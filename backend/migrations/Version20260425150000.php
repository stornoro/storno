<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260425150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add type, opening_balance and opening_balance_date to bank_account; allow nullable iban for cash accounts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE bank_account ADD type VARCHAR(10) DEFAULT 'bank' NOT NULL");
        $this->addSql('ALTER TABLE bank_account ADD opening_balance NUMERIC(14, 2) DEFAULT NULL');
        $this->addSql("ALTER TABLE bank_account ADD opening_balance_date DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)'");
        $this->addSql('ALTER TABLE bank_account MODIFY iban VARCHAR(34) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bank_account DROP COLUMN type');
        $this->addSql('ALTER TABLE bank_account DROP COLUMN opening_balance');
        $this->addSql('ALTER TABLE bank_account DROP COLUMN opening_balance_date');
        $this->addSql('ALTER TABLE bank_account MODIFY iban VARCHAR(34) NOT NULL');
    }
}
