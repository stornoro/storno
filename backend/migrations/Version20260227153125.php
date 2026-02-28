<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260227153125 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE email_unsubscribe (id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', company_id CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\', email VARCHAR(255) NOT NULL, unsubscribed_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', category VARCHAR(50) NOT NULL, INDEX IDX_B3AC4CB9979B1AD6 (company_id), UNIQUE INDEX unique_email_company (email, company_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE email_unsubscribe ADD CONSTRAINT FK_B3AC4CB9979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id)');
        $this->addSql('ALTER TABLE company ADD website VARCHAR(255) DEFAULT NULL, ADD capital_social VARCHAR(50) DEFAULT NULL, ADD vat_on_collection TINYINT(1) NOT NULL, ADD oss TINYINT(1) NOT NULL, ADD vat_in VARCHAR(20) DEFAULT NULL, ADD eori_code VARCHAR(20) DEFAULT NULL, ADD representative VARCHAR(255) DEFAULT NULL, ADD representative_role VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE proforma_invoice ADD expired_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE email_unsubscribe DROP FOREIGN KEY FK_B3AC4CB9979B1AD6');
        $this->addSql('DROP TABLE email_unsubscribe');
        $this->addSql('ALTER TABLE company DROP website, DROP capital_social, DROP vat_on_collection, DROP oss, DROP vat_in, DROP eori_code, DROP representative, DROP representative_role');
        $this->addSql('ALTER TABLE proforma_invoice DROP expired_at');
    }
}
