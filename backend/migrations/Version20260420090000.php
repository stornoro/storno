<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260420090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add spv_access_error columns to company for persisting ANAF SPV access denials';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company ADD spv_access_error LONGTEXT DEFAULT NULL, ADD spv_access_error_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company DROP spv_access_error, DROP spv_access_error_at');
    }
}
