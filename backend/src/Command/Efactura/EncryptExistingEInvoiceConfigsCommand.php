<?php

namespace App\Command\Efactura;

use App\Repository\CompanyEInvoiceConfigRepository;
use App\Service\Storage\CredentialEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:einvoice:encrypt-configs',
    description: 'Encrypt existing plain-text e-invoice provider configs',
)]
class EncryptExistingEInvoiceConfigsCommand extends Command
{
    public function __construct(
        private readonly CompanyEInvoiceConfigRepository $configRepository,
        private readonly CredentialEncryptor $encryptor,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $configs = $this->configRepository->createQueryBuilder('c')
            ->where('c.config IS NOT NULL')
            ->andWhere('c.encryptedConfig IS NULL')
            ->getQuery()
            ->getResult();

        if (empty($configs)) {
            $io->info('No plain-text configs to encrypt.');
            return Command::SUCCESS;
        }

        $io->text(sprintf('Found %d configs to encrypt.', count($configs)));

        $encrypted = 0;
        foreach ($configs as $config) {
            $plainConfig = $config->getConfig();
            if ($plainConfig === null || $plainConfig === []) {
                continue;
            }

            $config->setEncryptedConfig($this->encryptor->encrypt($plainConfig));
            $config->setConfig(null);
            $encrypted++;
        }

        $this->entityManager->flush();

        $io->success(sprintf('Encrypted %d configs.', $encrypted));

        return Command::SUCCESS;
    }
}
