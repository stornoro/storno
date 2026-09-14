<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Supplier → product memory for received e-Facturi (barcode, supplier code, description)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE supplier_product_mapping (id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', company_id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', supplier_id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', product_id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', barcode VARCHAR(100) DEFAULT NULL, supplier_code VARCHAR(100) DEFAULT NULL, description_key VARCHAR(255) DEFAULT NULL, hits INT NOT NULL, confirmed TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_used_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_SPM_COMPANY (company_id), INDEX IDX_SPM_PRODUCT (product_id), INDEX idx_spm_supplier_barcode (supplier_id, barcode), INDEX idx_spm_supplier_code (supplier_id, supplier_code), INDEX idx_spm_supplier_description (supplier_id, description_key), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE supplier_product_mapping ADD CONSTRAINT FK_SPM_COMPANY FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE supplier_product_mapping ADD CONSTRAINT FK_SPM_SUPPLIER FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE supplier_product_mapping ADD CONSTRAINT FK_SPM_PRODUCT FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE supplier_product_mapping');
    }
}
