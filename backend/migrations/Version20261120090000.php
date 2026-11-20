<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Automatic overdue reminders: steps on the company, opt-out on client and invoice, last step sent on the invoice';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company ADD overdue_reminders_enabled TINYINT(1) DEFAULT 0 NOT NULL, ADD overdue_reminder_steps JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE client ADD overdue_reminders_disabled TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE invoice ADD overdue_reminders_disabled TINYINT(1) DEFAULT 0 NOT NULL, ADD last_overdue_reminder_days INT DEFAULT NULL, ADD last_overdue_reminder_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice DROP overdue_reminders_disabled, DROP last_overdue_reminder_days, DROP last_overdue_reminder_at');
        $this->addSql('ALTER TABLE client DROP overdue_reminders_disabled');
        $this->addSql('ALTER TABLE company DROP overdue_reminders_enabled, DROP overdue_reminder_steps');
    }
}
