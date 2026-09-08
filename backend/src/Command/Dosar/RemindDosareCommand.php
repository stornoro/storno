<?php

declare(strict_types=1);

namespace App\Command\Dosar;

use App\Entity\Dosar;
use App\Repository\DosarRepository;
use App\Repository\OrganizationMembershipRepository;
use App\Service\Dosar\DosarService;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Daily: make sure every taxpayer with rental-contract dosare has the Declarația unică
 * dosar for the next 25 May, then notify the company's users about dosar deadlines
 * 30, 7 and 1 days ahead and on the day (each threshold once per deadline).
 */
#[AsCommand(name: 'app:dosare:remind', description: 'Create the yearly Declarația unică dosar where rent is declared and send deadline reminders for dosare')]
final class RemindDosareCommand extends Command
{
    private const THRESHOLDS = [30, 7, 1, 0];

    public function __construct(
        private readonly DosarRepository $dosare,
        private readonly DosarService $service,
        private readonly OrganizationMembershipRepository $memberships,
        private readonly NotificationService $notifications,
        private readonly TranslatorInterface $translator,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be created and sent');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        // 1. Declarația unică dosar for everyone who rents out property
        $filingYear = $this->service->nextFilingYear();
        $created = 0;
        $companies = [];
        foreach ($this->dosare->findBy(['type' => Dosar::TYPE_RENTAL_CONTRACT]) as $rental) {
            $company = $rental->getCompany();
            if ($company === null || isset($companies[(string) $company->getId()])) {
                continue;
            }
            $companies[(string) $company->getId()] = true;
            if ($this->dosare->findOneAnnualReturn($company, $filingYear) === null) {
                $created++;
                if (!$dryRun) {
                    $this->service->ensureAnnualReturnDosar($company, $filingYear);
                } else {
                    $io->text(sprintf('  [DRY RUN] would create "Declarația unică %d" for %s', $filingYear, $company->getName() ?? $company->getId()));
                }
            }
        }

        // 2. Deadline reminders
        $sent = 0;
        foreach ($this->dosare->findWithUpcomingDeadline(30) as $dosar) {
            $days = $dosar->getDaysToDeadline();
            if ($days === null || $days < 0) {
                continue; // passed deadlines stay visible in "De rezolvat"; no daily nagging
            }
            $threshold = null;
            foreach (self::THRESHOLDS as $t) {
                if ($days <= $t && !in_array($t, $dosar->getDeadlineNotified(), true)) {
                    $threshold = $t;
                }
            }
            if ($threshold === null) {
                continue;
            }
            $company = $dosar->getCompany();
            if ($company === null) {
                continue;
            }
            foreach ($this->memberships->findActiveUsersByCompany($company) as $user) {
                $locale = $user->getLocale() ?? 'ro';
                $params = ['%company%' => $company->getName() ?? '—', '%dosar%' => $dosar->getTitle(), '%label%' => $dosar->getDeadlineLabel() ?? '', '%date%' => $dosar->getDeadlineAt()?->format('d.m.Y') ?? '', '%days%' => (string) $days];
                $title = $this->translator->trans($days === 0 ? 'notification.dosar.deadline_today.title' : 'notification.dosar.deadline.title', $params, 'notifications', $locale);
                $message = $this->translator->trans($days === 0 ? 'notification.dosar.deadline_today.message' : 'notification.dosar.deadline.message', $params, 'notifications', $locale);
                if ($dryRun) {
                    $io->text(sprintf('  [DRY RUN] → %s: %s', $user->getEmail(), $message));
                } else {
                    $this->notifications->createNotification($user, 'dosar.deadline', $title, $message, [
                        'dosarId' => $dosar->getId()?->toRfc4122(),
                        'companyId' => $company->getId()?->toRfc4122(),
                        'deadlineAt' => $dosar->getDeadlineAt()?->format(DATE_ATOM),
                        'days' => $days,
                        'url' => '/dosare/' . $dosar->getId()?->toRfc4122(),
                    ]);
                }
                $sent++;
            }
            if (!$dryRun) {
                $dosar->markDeadlineNotified($threshold);
            }
        }
        if (!$dryRun) {
            $this->em->flush();
        }
        $io->success(sprintf('%d Declarația unică dosare created, %d deadline reminders sent.', $created, $sent));

        return Command::SUCCESS;
    }
}
