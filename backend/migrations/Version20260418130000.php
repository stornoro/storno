<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260418130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Default push notifications to enabled — flip existing notification_preference rows';
    }

    public function up(Schema $schema): void
    {
        // Existing users have rows with push_enabled=0 (the previous default).
        // Now that the entity defaults to true and FCM is wired end-to-end,
        // opt them in for every event type so they actually receive pushes.
        // Anyone who explicitly opted out will need to opt out again — the
        // schema doesn't track explicit-vs-default, and the trade-off here
        // favours notification reach over preserving stale opt-outs.
        $this->addSql('UPDATE notification_preference SET push_enabled = 1 WHERE push_enabled = 0');
    }

    public function down(Schema $schema): void
    {
        // Irreversible: cannot distinguish backfilled rows from genuine opt-ins.
    }
}
