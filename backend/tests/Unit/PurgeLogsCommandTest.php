<?php

namespace App\Tests\Unit;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** app:logs:purge removes old log rows in batches and leaves document email history alone. */
class PurgeLogsCommandTest extends KernelTestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::getContainer()->get('doctrine')->getConnection();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        $this->db->executeStatement("DELETE FROM processed_webhook_event WHERE event_id LIKE 'purge-test-%'");
        $this->db->executeStatement("DELETE FROM email_log WHERE to_email = 'purge-test@example.test'");
    }

    public function testDeletesOldRowsInBatchesAndKeepsRecentOnes(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->db->insert('processed_webhook_event', ['event_id' => "purge-test-old-$i", 'event_type' => 'x', 'processed_at' => '2020-01-01 00:00:00']);
        }
        $this->db->insert('processed_webhook_event', ['event_id' => 'purge-test-new', 'event_type' => 'x', 'processed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);

        $tester = $this->runPurge(['--batch-size' => '2']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM processed_webhook_event WHERE event_id LIKE 'purge-test-%'"));
        self::assertSame('purge-test-new', $this->db->fetchOne("SELECT event_id FROM processed_webhook_event WHERE event_id LIKE 'purge-test-%'"));
    }

    public function testOnlyOldLifecycleEmailsAreDeleted(): void
    {
        $row = ['to_email' => 'purge-test@example.test', 'subject' => 's', 'status' => 'sent'];
        $this->db->insert('email_log', $row + ['id' => '00000000-0000-4000-8000-0000000000a1', 'category' => 'dunning', 'sent_at' => '2020-01-01 00:00:00']);
        $this->db->insert('email_log', $row + ['id' => '00000000-0000-4000-8000-0000000000a2', 'category' => 'dunning', 'sent_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
        $this->db->insert('email_log', $row + ['id' => '00000000-0000-4000-8000-0000000000a3', 'category' => 'invoice', 'sent_at' => '2020-01-01 00:00:00']);
        $this->db->insert('email_log', $row + ['id' => '00000000-0000-4000-8000-0000000000a4', 'category' => 'statement', 'sent_at' => '2020-01-01 00:00:00']);

        $this->runPurge([]);

        $left = $this->db->fetchFirstColumn("SELECT id FROM email_log WHERE to_email = 'purge-test@example.test' ORDER BY id");
        self::assertSame([
            '00000000-0000-4000-8000-0000000000a2', // recent
            '00000000-0000-4000-8000-0000000000a3', // document category
            '00000000-0000-4000-8000-0000000000a4', // not a lifecycle category
        ], $left);
    }

    public function testDryRunDeletesNothing(): void
    {
        $this->db->insert('processed_webhook_event', ['event_id' => 'purge-test-old', 'event_type' => 'x', 'processed_at' => '2020-01-01 00:00:00']);

        $this->runPurge(['--dry-run' => true]);

        self::assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM processed_webhook_event WHERE event_id = 'purge-test-old'"));
    }

    private function runPurge(array $input): CommandTester
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:logs:purge'));
        $tester->execute($input);

        return $tester;
    }
}
