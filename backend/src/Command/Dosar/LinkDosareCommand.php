<?php

declare(strict_types=1);

namespace App\Command\Dosar;

use App\Entity\Dosar;
use App\Service\Dosar\DosarService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Ties every dosar without a client / supplier to the party with the tenant's CUI or CNP.
 * Run once after upgrading to the version that introduced the links (new dosare link themselves).
 */
#[AsCommand(name: 'app:dosare:link', description: 'Link dosare to the client / supplier matching the tenant CUI or CNP')]
final class LinkDosareCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly DosarService $service)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $linked = 0;
        foreach ($this->em->getRepository(Dosar::class)->findAll() as $dosar) {
            $before = [$dosar->getClient()?->getId(), $dosar->getSupplier()?->getId()];
            $this->service->linkParties($dosar);
            if ($before !== [$dosar->getClient()?->getId(), $dosar->getSupplier()?->getId()]) {
                $linked++;
                $output->writeln(sprintf('%s → client %s, supplier %s', $dosar->getTitle(), $dosar->getClient()?->getName() ?? '-', $dosar->getSupplier()?->getName() ?? '-'));
            }
        }
        $this->em->flush();
        $output->writeln(sprintf('%d dosare linked.', $linked));

        return Command::SUCCESS;
    }
}
