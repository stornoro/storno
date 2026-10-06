<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Calendar subscriptions: one iCalendar feed per member of an organization (expiries + fiscal deadlines)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE calendar_feed (id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', user_id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', organization_id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', version INT DEFAULT 1 NOT NULL, include_expiries TINYINT(1) DEFAULT 1 NOT NULL, include_fiscal TINYINT(1) DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_fetched_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_71D6C7FFA76ED395 (user_id), INDEX IDX_71D6C7FF32C8A3DE (organization_id), UNIQUE INDEX uniq_calendar_feed_member (user_id, organization_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE calendar_feed ADD CONSTRAINT FK_CALENDAR_FEED_USER FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE calendar_feed ADD CONSTRAINT FK_CALENDAR_FEED_ORGANIZATION FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar_feed DROP FOREIGN KEY FK_CALENDAR_FEED_USER');
        $this->addSql('ALTER TABLE calendar_feed DROP FOREIGN KEY FK_CALENDAR_FEED_ORGANIZATION');
        $this->addSql('DROP TABLE calendar_feed');
    }
}
