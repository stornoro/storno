<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260316180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add certificate_path and certificate_password columns to anaf_token for mTLS SPV authentication';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE anaf_token ADD certificate_path VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE anaf_token ADD certificate_password TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE anaf_token DROP certificate_path');
        $this->addSql('ALTER TABLE anaf_token DROP certificate_password');
    }
}
