<?php

declare(strict_types=1);

namespace App\Command\AppVersion;

use App\Entity\AppVersionOverride;
use App\Entity\AuditLog;
use App\Message\BroadcastVersionGateMessage;
use App\Repository\AppVersionOverrideRepository;
use App\Service\AppVersion\MobileLatestVersionResolver;
use App\Service\AppVersion\StoreVersionProbe;
use App\Service\VersionGateService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Keeps the mobile update gate in sync with the app stores without a human
 * editing config/version.yaml after every release.
 *
 * For each platform: store version (iTunes lookup / Google Play) is compared
 * with the highest version real devices reported through telemetry in the
 * last 30 days. The smaller of the two becomes the new "latest" once it is
 * above the current effective value; the store version alone is never
 * trusted (App Store Connect labels can differ from the binary). On change
 * the same broadcast the admin page uses notifies outdated users.
 */
#[AsCommand(name: 'app:mobile:sync-store-versions', description: 'Advertise the newest published mobile version (from the stores, confirmed by device telemetry) and notify outdated users')]
class SyncStoreVersionsCommand extends Command
{
    private const PLATFORMS = ['ios', 'android'];
    private const REPORT_WINDOW_DAYS = 30;

    public function __construct(
        private readonly StoreVersionProbe $probe,
        private readonly MobileLatestVersionResolver $resolver,
        private readonly VersionGateService $versionGate,
        private readonly AppVersionOverrideRepository $overrides,
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only print what would change')
            ->addOption('no-notify', null, InputOption::VALUE_NONE, 'Update the gate without pushing notifications to outdated users');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $notify = !$input->getOption('no-notify');
        $changed = 0;

        foreach (self::PLATFORMS as $platform) {
            $config = $this->versionGate->effectiveConfigFor($platform);
            if ($config === null) {
                continue;
            }
            $store = $platform === 'ios' ? $this->probe->iosVersion() : $this->probe->androidVersion();
            $reported = $this->highestReportedVersion($platform);
            $decision = $this->resolver->resolve($store, $reported, (string) ($config['latest'] ?? '0.0.0'), (string) ($config['min'] ?? '0.0.0'));

            $output->writeln(sprintf(
                '%s: store=%s devices=%s effective=%s -> %s (%s)',
                $platform,
                $store ?? '-',
                $reported ?? '-',
                $config['latest'] ?? '-',
                $decision['latest'] ?? 'no change',
                $decision['reason'],
            ));
            if ($decision['latest'] === null || $dryRun) {
                continue;
            }

            $override = $this->overrides->findByPlatform($platform) ?? new AppVersionOverride($platform);
            $before = $override->getLatestOverride();
            $override->setLatestOverride($decision['latest']);
            $override->setUpdatedBy(null);
            $override->touch();
            $this->em->persist($override);

            $audit = new AuditLog();
            $audit->setAction('auto-update');
            $audit->setEntityType('AppVersionOverride');
            $audit->setEntityId($platform);
            $audit->setChanges([
                'before' => ['latest' => $before],
                'after' => ['latest' => $decision['latest']],
                'source' => ['store' => $store, 'devices' => $reported, 'reason' => $decision['reason']],
            ]);
            $audit->setUser(null);
            $this->em->persist($audit);
            $this->em->flush();
            $changed++;

            if ($notify) {
                $this->bus->dispatch(new BroadcastVersionGateMessage(platform: $platform, triggeredByUserId: 'system:sync-store-versions'));
                $output->writeln(sprintf('  notified outdated %s users', $platform));
            }
        }

        $output->writeln($changed > 0 ? sprintf('<info>%d platform(s) updated.</info>', $changed) : 'Nothing to update.');

        return Command::SUCCESS;
    }

    /**
     * Highest app_version any device on the platform reported recently.
     */
    private function highestReportedVersion(string $platform): ?string
    {
        $since = (new \DateTimeImmutable())->sub(new \DateInterval('P' . self::REPORT_WINDOW_DAYS . 'D'))->format('Y-m-d H:i:s');
        $rows = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT app_version FROM telemetry_event WHERE platform = :platform AND created_at >= :since AND app_version IS NOT NULL',
            ['platform' => $platform, 'since' => $since],
        );
        $best = null;
        foreach ($rows as $v) {
            $n = $this->resolver->normalize((string) $v);
            if ($n !== null && ($best === null || version_compare($n, $best, '>'))) {
                $best = $n;
            }
        }

        return $best;
    }
}
