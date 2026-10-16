<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User: keep the sign-up attribution (utm, landing page, referrer, ad click) on the account';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD signup_attribution JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP signup_attribution');
    }
}
