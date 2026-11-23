<?php

namespace App\Command;

use App\Command\Support\BatchedDelete;
use App\Command\Support\CommandProgress;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Retention for the log tables that only grow. Each table is deleted in batches of
 * `--batch-size` rows so no statement locks the table for long.
 *
 * Left out on purpose:
 * - document_event: the invoice page shows it as the history of that invoice.
 * - email_log and email_event rows of documents (invoice, delivery note, receipt): the email
 *   history of a document is shown to users. Only lifecycle and summary emails are purged.
 */
#[AsCommand(
    name: 'app:logs:purge',
    description: 'Delete old rows from the webhook delivery, lifecycle email, telemetry, login history and processed webhook tables',
)]
class PurgeLogsCommand extends Command
{
    /** Webhook deliveries are a debugging list per endpoint and per invoice; finished ones older than this add nothing. */
    public const WEBHOOK_DELIVERY_DAYS = 90;

    /** Lifecycle and monthly summary emails (no document attached) are read by admins to check what a user was sent. A year covers a full drip cycle. */
    public const EMAIL_LOG_DAYS = 365;

    /** Telemetry feeds the mobile update gate, which looks back weeks at most. */
    public const TELEMETRY_EVENT_DAYS = 90;

    /** Sign-in history shown to the user as security information. */
    public const LOGIN_HISTORY_DAYS = 365;

    /** Stripe retries an event for days at most; the id is only needed to drop a repeat. */
    public const PROCESSED_WEBHOOK_EVENT_DAYS = 90;

    /** Email categories written without a document. Rows with an invoice, delivery note or receipt are never purged. */
    private const NON_DOCUMENT_EMAIL_CATEGORIES = [
        'first_company_created', 'first_invoice_created', 're_engagement', 'trial_ended',
        'trial_expiration', 'account_without_login', 'dunning', 'feature_drip', 'monthly_summary',
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('webhook-delivery-days', null, InputOption::VALUE_REQUIRED, 'Keep finished webhook deliveries this many days', (string) self::WEBHOOK_DELIVERY_DAYS)
            ->addOption('email-log-days', null, InputOption::VALUE_REQUIRED, 'Keep lifecycle email logs (and their events) this many days', (string) self::EMAIL_LOG_DAYS)
            ->addOption('telemetry-days', null, InputOption::VALUE_REQUIRED, 'Keep telemetry events this many days', (string) self::TELEMETRY_EVENT_DAYS)
            ->addOption('login-history-days', null, InputOption::VALUE_REQUIRED, 'Keep login history this many days', (string) self::LOGIN_HISTORY_DAYS)
            ->addOption('processed-webhook-days', null, InputOption::VALUE_REQUIRED, 'Keep processed Stripe webhook event ids this many days', (string) self::PROCESSED_WEBHOOK_EVENT_DAYS)
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Rows deleted per statement', (string) BatchedDelete::DEFAULT_BATCH_SIZE)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count the rows that would be deleted');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $conn = $this->em->getConnection();
        $batch = max(1, (int) $input->getOption('batch-size'));
        $dryRun = (bool) $input->getOption('dry-run');
        $cutoff = static fn (string $option): string => (new \DateTimeImmutable(sprintf('-%d days', max(1, (int) $input->getOption($option)))))->format('Y-m-d H:i:s');

        $categories = "'" . implode("','", self::NON_DOCUMENT_EMAIL_CATEGORIES) . "'";
        $emailLogWhere = "sent_at < :cutoff AND invoice_id IS NULL AND delivery_note_id IS NULL AND receipt_id IS NULL AND category IN ($categories)";

        // name => [table, where clause, params]. email_event goes before email_log, which it references.
        $tables = [
            'webhook_delivery' => ['webhook_delivery', "triggered_at < :cutoff AND status IN ('success', 'failed')", ['cutoff' => $cutoff('webhook-delivery-days')]],
            'email_event' => ['email_event', "email_log_id IN (SELECT id FROM email_log WHERE $emailLogWhere)", ['cutoff' => $cutoff('email-log-days')]],
            'email_log' => ['email_log', $emailLogWhere, ['cutoff' => $cutoff('email-log-days')]],
            'telemetry_event' => ['telemetry_event', 'created_at < :cutoff', ['cutoff' => $cutoff('telemetry-days')]],
            'login_history' => ['login_history', 'date < :cutoff', ['cutoff' => substr($cutoff('login-history-days'), 0, 10)]],
            'processed_webhook_event' => ['processed_webhook_event', 'processed_at < :cutoff', ['cutoff' => $cutoff('processed-webhook-days')]],
        ];

        $counts = [];
        foreach ($tables as $name => [$table, $where, $params]) {
            $counts[$name] = (int) $conn->fetchOne("SELECT COUNT(*) FROM $table WHERE $where", $params);
        }
        $totalRows = array_sum($counts);

        $progress = new CommandProgress($output, 'logs:purge', $totalRows);
        $progress->start(sprintf(
            '%d rows to %s in %d tables, %d per statement%s',
            $totalRows,
            $dryRun ? 'count' : 'delete',
            count(array_filter($counts)),
            $batch,
            $dryRun ? ' (dry-run)' : '',
        ));

        $rows = [];
        foreach ($tables as $name => [$table, $where, $params]) {
            if ($dryRun || $counts[$name] === 0) {
                $rows[] = [$name, $counts[$name]];
                continue;
            }
            $deleted = BatchedDelete::run($conn, "DELETE FROM $table WHERE $where", $params, $batch, static function (int $n) use ($progress, $name): void {
                $progress->count($name, $n);
                $progress->advance($n);
            });
            $rows[] = [$name, $deleted];
        }
        $progress->finish();

        $io->table(['Table', $dryRun ? 'Would delete' : 'Deleted'], $rows);
        $io->success($dryRun ? 'Dry run, nothing deleted.' : 'Log tables purged.');

        return Command::SUCCESS;
    }
}
