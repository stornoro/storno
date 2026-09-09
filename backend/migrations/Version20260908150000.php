<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Individual persons as companies (CNP needs BIGINT, type column) and explicit dosar links to the tenant client / supplier';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company MODIFY cif BIGINT NOT NULL COMMENT \'(DC2Type:bigint_int)\', ADD type VARCHAR(16) NOT NULL DEFAULT \'company\'');
        $this->addSql('ALTER TABLE dosar ADD client_id CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\', ADD supplier_id CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE dosar ADD CONSTRAINT FK_DOSAR_CLIENT FOREIGN KEY (client_id) REFERENCES client (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE dosar ADD CONSTRAINT FK_DOSAR_SUPPLIER FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_dosar_client ON dosar (client_id)');
        $this->addSql('CREATE INDEX idx_dosar_supplier ON dosar (supplier_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dosar DROP FOREIGN KEY FK_DOSAR_CLIENT');
        $this->addSql('ALTER TABLE dosar DROP FOREIGN KEY FK_DOSAR_SUPPLIER');
        $this->addSql('DROP INDEX idx_dosar_client ON dosar');
        $this->addSql('DROP INDEX idx_dosar_supplier ON dosar');
        $this->addSql('ALTER TABLE dosar DROP client_id, DROP supplier_id');
        $this->addSql('ALTER TABLE company MODIFY cif INT NOT NULL, DROP type');
    }
}
