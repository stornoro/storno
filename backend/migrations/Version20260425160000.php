<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260425160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create cash_movement table for manual cash register entries (deposits, withdrawals, miscellaneous)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE cash_movement (
                id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\',
                company_id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\',
                created_by_id CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\',
                updated_by_id CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\',
                movement_date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
                kind VARCHAR(20) NOT NULL,
                direction VARCHAR(5) NOT NULL,
                amount NUMERIC(14, 2) NOT NULL,
                currency VARCHAR(3) NOT NULL,
                description LONGTEXT DEFAULT NULL,
                document_number VARCHAR(50) DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX idx_cashmove_company_date (company_id, movement_date),
                INDEX IDX_CASHMOVE_CREATED_BY (created_by_id),
                INDEX IDX_CASHMOVE_UPDATED_BY (updated_by_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );
        $this->addSql('ALTER TABLE cash_movement ADD CONSTRAINT FK_CASHMOVE_COMPANY FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE cash_movement ADD CONSTRAINT FK_CASHMOVE_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE cash_movement ADD CONSTRAINT FK_CASHMOVE_UPDATED_BY FOREIGN KEY (updated_by_id) REFERENCES user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cash_movement DROP FOREIGN KEY FK_CASHMOVE_COMPANY');
        $this->addSql('ALTER TABLE cash_movement DROP FOREIGN KEY FK_CASHMOVE_CREATED_BY');
        $this->addSql('ALTER TABLE cash_movement DROP FOREIGN KEY FK_CASHMOVE_UPDATED_BY');
        $this->addSql('DROP TABLE cash_movement');
    }
}
