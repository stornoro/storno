<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260315173918 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tax_declaration table for tax declaration module';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE tax_declaration (id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', company_id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', created_by_id CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\', updated_by_id CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\', deleted_by_id CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\', type VARCHAR(20) NOT NULL, status VARCHAR(30) NOT NULL, year SMALLINT NOT NULL, month SMALLINT NOT NULL, period_type VARCHAR(20) NOT NULL, data JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', metadata JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', anaf_upload_id VARCHAR(255) DEFAULT NULL, xml_path VARCHAR(500) DEFAULT NULL, recipisa_path VARCHAR(500) DEFAULT NULL, error_message LONGTEXT DEFAULT NULL, submitted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', accepted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', deleted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_F51DD3CD979B1AD6 (company_id), INDEX IDX_F51DD3CDB03A8386 (created_by_id), INDEX IDX_F51DD3CD896DBBDE (updated_by_id), INDEX IDX_F51DD3CDC76F1F52 (deleted_by_id), INDEX idx_declaration_company_type (company_id, type), INDEX idx_declaration_status (status), INDEX idx_declaration_period (company_id, year, month), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE tax_declaration ADD CONSTRAINT FK_F51DD3CD979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id)');
        $this->addSql('ALTER TABLE tax_declaration ADD CONSTRAINT FK_F51DD3CDB03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE tax_declaration ADD CONSTRAINT FK_F51DD3CD896DBBDE FOREIGN KEY (updated_by_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE tax_declaration ADD CONSTRAINT FK_F51DD3CDC76F1F52 FOREIGN KEY (deleted_by_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE invoice DROP signature_content');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tax_declaration DROP FOREIGN KEY FK_F51DD3CD979B1AD6');
        $this->addSql('ALTER TABLE tax_declaration DROP FOREIGN KEY FK_F51DD3CDB03A8386');
        $this->addSql('ALTER TABLE tax_declaration DROP FOREIGN KEY FK_F51DD3CD896DBBDE');
        $this->addSql('ALTER TABLE tax_declaration DROP FOREIGN KEY FK_F51DD3CDC76F1F52');
        $this->addSql('DROP TABLE tax_declaration');
        $this->addSql('ALTER TABLE invoice ADD signature_content LONGTEXT DEFAULT NULL');
    }
}
