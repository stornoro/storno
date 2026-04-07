<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260407150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop duplicate e_invoice_submission table (real table is einvoice_submission)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS e_invoice_submission');
    }

    public function down(Schema $schema): void
    {
        // No rollback needed — the table was a duplicate created by mistake
    }
}
