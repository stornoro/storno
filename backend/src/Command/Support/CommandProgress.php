<?php

namespace App\Command\Support;

use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * Progress on stderr for long-running commands. A progress bar when stderr is
 * a terminal, a plain line every few seconds otherwise (cron, logs).
 */
final class CommandProgress
{
    private const LINE_INTERVAL_SECONDS = 30;

    private OutputInterface $err;
    private ?ProgressBar $bar = null;
    private int $done = 0;
    private int $lastLineAt;
    /** @var array<string, int> */
    private array $counters = [];

    public function __construct(OutputInterface $output, private readonly string $label, private readonly ?int $total = null)
    {
        $this->err = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $this->lastLineAt = time();
    }

    /** One-line summary of what the command is about to do, written at once. */
    public function start(string $summary): void
    {
        $this->err->writeln(sprintf('[%s] %s', $this->label, $summary));

        if ($this->isTerminal()) {
            $this->bar = new ProgressBar($this->err, $this->total ?? 0);
            $this->bar->setFormat($this->total !== null
                ? ' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %message%'
                : ' %current% [%bar%] %elapsed:6s% %message%');
            $this->bar->setMessage('');
            $this->bar->start();
        }
    }

    public function count(string $counter, int $by = 1): void
    {
        $this->counters[$counter] = ($this->counters[$counter] ?? 0) + $by;
    }

    public function advance(int $by = 1): void
    {
        $this->done += $by;
        if ($this->bar) {
            $this->bar->setMessage($this->counterText());
            $this->bar->advance($by);

            return;
        }
        if (time() - $this->lastLineAt >= self::LINE_INTERVAL_SECONDS) {
            $this->lastLineAt = time();
            $this->err->writeln($this->line());
        }
    }

    public function finish(): void
    {
        if ($this->bar) {
            $this->bar->setMessage($this->counterText());
            $this->bar->finish();
            $this->err->writeln('');

            return;
        }
        $this->err->writeln($this->line() . ' (done)');
    }

    /** @return array<string, int> */
    public function counters(): array
    {
        return $this->counters;
    }

    private function line(): string
    {
        return sprintf('[%s] %d%s %s', $this->label, $this->done, $this->total !== null ? '/' . $this->total : '', $this->counterText());
    }

    private function counterText(): string
    {
        $parts = [];
        foreach ($this->counters as $name => $value) {
            $parts[] = $name . '=' . $value;
        }

        return implode(' ', $parts);
    }

    private function isTerminal(): bool
    {
        return $this->err instanceof StreamOutput && stream_isatty($this->err->getStream());
    }
}
