<?php

namespace App\Command\Efactura;

use App\Enum\MessageKey;
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
    description: 'Refresh ANAF OAuth tokens expiring within 5 days',
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

        $threshold = new \DateTimeImmutable('+5 days');
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
                        $tokenLabel = $token->getLabel() ?: ($token->getValidatedCifs() ? implode(', ', $token->getValidatedCifs()) : 'ANAF');
                        $this->notificationService->createNotification(
                            $token->getUser(),
                            'token.refresh_failed',
                            sprintf('%s — ANAF token refresh failed', $tokenLabel),
                            sprintf('Automatic ANAF token renewal failed for %s. Please re-authorize.', $tokenLabel),
                            [
                                'tokenId' => $token->getId()->toRfc4122(),
                                'tokenLabel' => $tokenLabel,
                                'titleKey' => MessageKey::TITLE_TOKEN_REFRESH_FAILED,
                                'titleParams' => ['token' => $tokenLabel],
                                'messageKey' => MessageKey::MSG_TOKEN_REFRESH_FAILED,
                                'messageParams' => ['token' => $tokenLabel],
                            ],
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
