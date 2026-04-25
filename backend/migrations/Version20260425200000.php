<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260425200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add product_category table and product.category_id FK for POS grid grouping';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE product_category (
                id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\',
                company_id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\',
                name VARCHAR(100) NOT NULL,
                color VARCHAR(7) DEFAULT NULL,
                sort_order INT NOT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX idx_product_category_company_sort (company_id, sort_order),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );
        $this->addSql('ALTER TABLE product_category ADD CONSTRAINT FK_PRODUCT_CATEGORY_COMPANY FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE product ADD category_id CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT FK_PRODUCT_CATEGORY FOREIGN KEY (category_id) REFERENCES product_category (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_PRODUCT_CATEGORY ON product (category_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP FOREIGN KEY FK_PRODUCT_CATEGORY');
        $this->addSql('DROP INDEX IDX_PRODUCT_CATEGORY ON product');
        $this->addSql('ALTER TABLE product DROP category_id');

        $this->addSql('ALTER TABLE product_category DROP FOREIGN KEY FK_PRODUCT_CATEGORY_COMPANY');
        $this->addSql('DROP TABLE product_category');
    }
}
