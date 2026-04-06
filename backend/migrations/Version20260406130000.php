<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260406130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add telegram_link_token and telegram_link_token_expires_at to user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD telegram_link_token VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE user ADD telegram_link_token_expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_telegram_link_token ON user (telegram_link_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_telegram_link_token ON user');
        $this->addSql('ALTER TABLE user DROP telegram_link_token, DROP telegram_link_token_expires_at');
    }
}
