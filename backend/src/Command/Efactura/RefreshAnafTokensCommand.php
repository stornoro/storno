<?php

namespace App\Command\Efactura;

use App\Repository\AnafTokenRepository;
use App\Service\Anaf\AnafTokenResolver;
use App\Service\NotificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:anaf:refresh-tokens',
    description: 'Refresh ANAF OAuth tokens that are expiring soon',
)]
class RefreshAnafTokensCommand extends Command
{
    public function __construct(
        private readonly AnafTokenRepository $tokenRepository,
        private readonly AnafTokenResolver $tokenResolver,
        private readonly NotificationService $notificationService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $threshold = new \DateTimeImmutable('+4 hours');
        $tokens = $this->tokenRepository->findExpiringWithin($threshold);

        if (empty($tokens)) {
            $io->info('No tokens need refreshing.');
            return Command::SUCCESS;
        }

        $io->text(sprintf('Found %d tokens to refresh.', count($tokens)));

        $refreshed = 0;
        $failed = 0;

        foreach ($tokens as $token) {
            $userName = $token->getUser()?->getEmail() ?? 'unknown';
            $label = $token->getLabel() ? sprintf(' [%s]', $token->getLabel()) : '';
            $success = $this->tokenResolver->refreshToken($token);

            if ($success) {
                $refreshed++;
                $io->text(sprintf('  Refreshed token for %s%s', $userName, $label));
            } else {
                $failed++;
                $io->warning(sprintf('  Failed to refresh token for %s%s — user needs to re-authorize', $userName, $label));

                if ($token->getUser()) {
                    try {
                        $this->notificationService->createNotification(
                            $token->getUser(),
                            'token.refresh_failed',
                            'Eroare reînnoire token ANAF',
                            'Reînnoirea automată a token-ului ANAF a eșuat. Vă rugăm să vă re-autorizați.',
                            ['tokenId' => $token->getId()->toRfc4122()],
                        );
                    } catch (\Throwable) {
                        // Don't let notification failures break the command
                    }
                }
            }
        }

        $io->success(sprintf('Refreshed: %d, Failed: %d', $refreshed, $failed));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
