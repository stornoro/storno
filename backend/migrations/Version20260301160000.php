<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260301160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add default payment method to PDF template config';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pdf_template_config ADD default_payment_method VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pdf_template_config DROP default_payment_method');
    }
}
