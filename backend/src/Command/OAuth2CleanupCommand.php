<?php

namespace App\Command;

use App\Repository\OAuth2AccessTokenRepository;
use App\Repository\OAuth2AuthorizationCodeRepository;
use App\Repository\OAuth2RefreshTokenRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:oauth2:cleanup',
    description: 'Purge expired OAuth2 authorization codes and revoked/expired tokens',
)]
class OAuth2CleanupCommand extends Command
{
    public function __construct(
        private readonly OAuth2AuthorizationCodeRepository $authCodeRepository,
        private readonly OAuth2AccessTokenRepository $accessTokenRepository,
        private readonly OAuth2RefreshTokenRepository $refreshTokenRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Purge auth codes older than 1 hour (they expire after 10 min)
        $codesBefore = new \DateTimeImmutable('-1 hour');
        $codesDeleted = $this->authCodeRepository->purgeExpired($codesBefore);
        $io->info(sprintf('Purged %d expired authorization codes.', $codesDeleted));

        // Purge expired/revoked access tokens older than 7 days
        $tokensBefore = new \DateTimeImmutable('-7 days');
        $accessDeleted = $this->accessTokenRepository->purgeExpired($tokensBefore);
        $io->info(sprintf('Purged %d expired/revoked access tokens.', $accessDeleted));

        // Purge expired/revoked refresh tokens older than 7 days
        $refreshDeleted = $this->refreshTokenRepository->purgeExpired($tokensBefore);
        $io->info(sprintf('Purged %d expired/revoked refresh tokens.', $refreshDeleted));

        $io->success('OAuth2 cleanup completed.');

        return Command::SUCCESS;
    }
}
