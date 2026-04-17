<?php

namespace App\Command\Notification;

use App\Enum\MessageKey;
use App\Repository\AnafTokenRepository;
use App\Repository\NotificationRepository;
use App\Service\NotificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(
    name: 'app:notifications:token-expiry',
    description: 'Send reminders for ANAF tokens expiring within 7 days',
)]
class SendTokenExpiryRemindersCommand extends Command
{
    public function __construct(
        private readonly AnafTokenRepository $tokenRepository,
        private readonly NotificationRepository $notificationRepository,
        private readonly NotificationService $notificationService,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show what would be sent without sending');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');

        $threshold = new \DateTimeImmutable('+7 days');
        $now = new \DateTimeImmutable();
        $today = new \DateTimeImmutable('today');
        $sent = 0;

        $refreshableTokens = $this->tokenRepository->findExpiringWithin($threshold);
        $nonRefreshableTokens = $this->tokenRepository->findExpiringWithoutRefreshToken($threshold);
        $allTokens = array_merge($refreshableTokens, $nonRefreshableTokens);

        if (empty($allTokens)) {
            $io->info('No tokens expiring soon.');
            return Command::SUCCESS;
        }

        foreach ($allTokens as $token) {
            $user = $token->getUser();
            if (!$user) {
                continue;
            }

            if ($token->getExpireAt() < $now) {
                continue;
            }

            $daysUntilExpiry = (int) $now->diff($token->getExpireAt())->days;
            $hasRefreshToken = $token->getRefreshToken() !== null;

            $existing = (int) $this->notificationRepository->createQueryBuilder('n')
                ->select('COUNT(n.id)')
                ->where('n.user = :user')
                ->andWhere('n.type = :type')
                ->andWhere('n.sentAt >= :todayStart')
                ->andWhere('n.sentAt < :todayEnd')
                ->andWhere('n.data LIKE :tokenId')
                ->setParameter('user', $user)
                ->setParameter('type', 'token.expiring_soon')
                ->setParameter('todayStart', $today)
                ->setParameter('todayEnd', $today->modify('+1 day'))
                ->setParameter('tokenId', '%"tokenId":"' . $token->getId()->toRfc4122() . '"%')
                ->getQuery()
                ->getSingleScalarResult();

            if ($existing > 0) {
                continue;
            }

            $locale = $user->getLocale() ?? 'ro';
            $params = ['%days%' => $daysUntilExpiry];

            if ($hasRefreshToken) {
                $translationKey = 'notification.token_expiring';
                $titleKey = MessageKey::TITLE_TOKEN_EXPIRING;
                $messageKey = MessageKey::MSG_TOKEN_EXPIRING;
            } else {
                $translationKey = 'notification.token_expiring_no_refresh';
                $titleKey = MessageKey::TITLE_TOKEN_EXPIRING_NO_REFRESH;
                $messageKey = MessageKey::MSG_TOKEN_EXPIRING_NO_REFRESH;
            }

            $title = $this->translator->trans($translationKey . '.title', [], 'notifications', $locale);
            $message = $this->translator->trans($translationKey . '.message', $params, 'notifications', $locale);

            if ($dryRun) {
                $suffix = $hasRefreshToken ? '' : ' [NO REFRESH TOKEN]';
                $io->text(sprintf('  [DRY RUN] token.expiring_soon → %s [%s]: %s%s', $user->getEmail(), $locale, $message, $suffix));
            } else {
                $this->notificationService->createNotification(
                    $user,
                    'token.expiring_soon',
                    $title,
                    $message,
                    [
                        'tokenId' => $token->getId()->toRfc4122(),
                        'hasRefreshToken' => $hasRefreshToken,
                        'titleKey' => $titleKey,
                        'messageKey' => $messageKey,
                        'messageParams' => ['days' => $daysUntilExpiry],
                    ],
                );
            }

            $sent++;
        }

        if ($dryRun) {
            $io->note(sprintf('Dry run: %d notifications would be sent.', $sent));
        } else {
            $io->success(sprintf('Sent %d notifications.', $sent));
        }

        return Command::SUCCESS;
    }
}
