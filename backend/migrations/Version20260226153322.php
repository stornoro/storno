<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260226153322 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE trial_balance (id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', company_id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', created_by_id CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\', updated_by_id CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\', deleted_by_id CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\', year SMALLINT NOT NULL, month SMALLINT NOT NULL, original_filename VARCHAR(255) NOT NULL, storage_path VARCHAR(500) NOT NULL, source_software VARCHAR(50) DEFAULT NULL, status VARCHAR(20) NOT NULL, total_accounts INT NOT NULL, error LONGTEXT DEFAULT NULL, processed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', deleted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_AF3DDB52979B1AD6 (company_id), INDEX IDX_AF3DDB52B03A8386 (created_by_id), INDEX IDX_AF3DDB52896DBBDE (updated_by_id), INDEX IDX_AF3DDB52C76F1F52 (deleted_by_id), INDEX idx_trial_balance_company_status (company_id, status), UNIQUE INDEX uniq_trial_balance_company_year_month (company_id, year, month), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE trial_balance_row (id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', trial_balance_id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', account_code VARCHAR(20) NOT NULL, account_name VARCHAR(255) NOT NULL, initial_debit NUMERIC(15, 2) NOT NULL, initial_credit NUMERIC(15, 2) NOT NULL, previous_debit NUMERIC(15, 2) NOT NULL, previous_credit NUMERIC(15, 2) NOT NULL, current_debit NUMERIC(15, 2) NOT NULL, current_credit NUMERIC(15, 2) NOT NULL, total_debit NUMERIC(15, 2) NOT NULL, total_credit NUMERIC(15, 2) NOT NULL, final_debit NUMERIC(15, 2) NOT NULL, final_credit NUMERIC(15, 2) NOT NULL, INDEX idx_trial_balance_row_balance (trial_balance_id), INDEX idx_trial_balance_row_account (trial_balance_id, account_code), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE trial_balance ADD CONSTRAINT FK_AF3DDB52979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id)');
        $this->addSql('ALTER TABLE trial_balance ADD CONSTRAINT FK_AF3DDB52B03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE trial_balance ADD CONSTRAINT FK_AF3DDB52896DBBDE FOREIGN KEY (updated_by_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE trial_balance ADD CONSTRAINT FK_AF3DDB52C76F1F52 FOREIGN KEY (deleted_by_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE trial_balance_row ADD CONSTRAINT FK_502041E33DD6F84 FOREIGN KEY (trial_balance_id) REFERENCES trial_balance (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE trial_balance DROP FOREIGN KEY FK_AF3DDB52979B1AD6');
        $this->addSql('ALTER TABLE trial_balance DROP FOREIGN KEY FK_AF3DDB52B03A8386');
        $this->addSql('ALTER TABLE trial_balance DROP FOREIGN KEY FK_AF3DDB52896DBBDE');
        $this->addSql('ALTER TABLE trial_balance DROP FOREIGN KEY FK_AF3DDB52C76F1F52');
        $this->addSql('ALTER TABLE trial_balance_row DROP FOREIGN KEY FK_502041E33DD6F84');
        $this->addSql('DROP TABLE trial_balance');
        $this->addSql('DROP TABLE trial_balance_row');
    }
}
