<?php

declare(strict_types=1);

namespace App\Command\Spv;

use App\Entity\SpvDocument;
use App\Service\Spv\SpvDocumentSummarizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Recompute the plain-language summary of archived SPV documents (after wording improvements or for rows archived before the column existed). */
#[AsCommand(name: 'app:spv:resummarize', description: 'Rebuild the plain-language summary of SPV documents')]
final class ResummarizeSpvDocumentsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SpvDocumentSummarizer $summarizer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Rewrite every row, not only the ones without a summary');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $qb = $this->entityManager->getRepository(SpvDocument::class)->createQueryBuilder('d');
        if (!$input->getOption('all')) {
            $qb->andWhere('d.summary IS NULL');
        }
        $count = 0;
        foreach ($qb->getQuery()->toIterable() as $doc) {
            /** @var SpvDocument $doc */
            $doc->setSummary($this->summarizer->summarize($doc->getMessageType(), $doc->getDetails(), $doc->getCategory(), 'ro'));
            $doc->setSummaryEn($this->summarizer->summarize($doc->getMessageType(), $doc->getDetails(), $doc->getCategory(), 'en'));
            if (++$count % 200 === 0) {
                $this->entityManager->flush();
                $this->entityManager->clear();
            }
        }
        $this->entityManager->flush();
        $output->writeln(sprintf('%d documents summarised.', $count));

        return Command::SUCCESS;
    }
}
