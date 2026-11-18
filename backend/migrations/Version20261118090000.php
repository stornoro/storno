<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Idempotency keys unique per company (invoice, receipt); ANAF token bound to one company (exclusive links)';
    }

    public function up(Schema $schema): void
    {
        // The old unique index on idempotency_key alone has a different name per install
        // (generated or hand-written), so it is looked up instead of assumed.
        foreach (['invoice', 'receipt'] as $table) {
            foreach ($this->uniqueIndexesOnKeyAlone($table) as $index) {
                $this->addSql(sprintf('DROP INDEX `%s` ON `%s`', $index, $table));
            }
            $this->addSql(sprintf(
                'CREATE UNIQUE INDEX uniq_%1$s_company_idempotency ON `%1$s` (company_id, idempotency_key)',
                $table,
            ));
        }

        $this->addSql('ALTER TABLE anaf_token ADD exclusive TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE anaf_token_link ADD exclusive TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE company ADD anaf_token_id CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\', ADD anaf_token_exclusive TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE company ADD CONSTRAINT FK_4FBF094FD9C671F7 FOREIGN KEY (anaf_token_id) REFERENCES anaf_token (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_4FBF094FD9C671F7 ON company (anaf_token_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company DROP FOREIGN KEY FK_4FBF094FD9C671F7');
        $this->addSql('DROP INDEX IDX_4FBF094FD9C671F7 ON company');
        $this->addSql('ALTER TABLE company DROP anaf_token_id, DROP anaf_token_exclusive');
        $this->addSql('ALTER TABLE anaf_token_link DROP exclusive');
        $this->addSql('ALTER TABLE anaf_token DROP exclusive');

        // Fails if two companies already share a key. That is the state the up() migration allows.
        $this->addSql('DROP INDEX uniq_invoice_company_idempotency ON invoice');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_INVOICE_IDEMPOTENCY_KEY ON invoice (idempotency_key)');
        $this->addSql('DROP INDEX uniq_receipt_company_idempotency ON receipt');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_RECEIPT_IDEMPOTENCY_KEY ON receipt (idempotency_key)');
    }

    /**
     * @return string[] names of the unique indexes that cover idempotency_key and nothing else
     */
    private function uniqueIndexesOnKeyAlone(string $table): array
    {
        return $this->connection->fetchFirstColumn(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0
             GROUP BY INDEX_NAME
             HAVING COUNT(*) = 1 AND MAX(COLUMN_NAME) = \'idempotency_key\'',
            [$table],
        );
    }
}
