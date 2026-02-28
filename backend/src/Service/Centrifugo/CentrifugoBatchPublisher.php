<?php

namespace App\Service\Centrifugo;

/**
 * Collects multiple Centrifugo commands and sends them in a single batch HTTP request.
 *
 * Usage:
 *   $batch = $centrifugoService->createBatch();
 *   $batch->publish('channel1', ['event' => 'created']);
 *   $batch->publish('channel2', ['event' => 'updated']);
 *   $batch->broadcast(['ch3', 'ch4'], ['event' => 'notify']);
 *   $replies = $batch->send();
 */
class CentrifugoBatchPublisher
{
    /** @var list<array<string, mixed>> */
    private array $commands = [];

    public function __construct(
        private readonly CentrifugoService $centrifugo,
    ) {}

    /**
     * Add a publish command to the batch.
     */
    public function publish(string $channel, array $data): self
    {
        $this->commands[] = ['publish' => ['channel' => $channel, 'data' => $data]];
        return $this;
    }

    /**
     * Add a broadcast command (same data to multiple channels) to the batch.
     *
     * @param string[] $channels
     */
    public function broadcast(array $channels, array $data): self
    {
        if (!empty($channels)) {
            $this->commands[] = ['broadcast' => ['channels' => $channels, 'data' => $data]];
        }
        return $this;
    }

    /**
     * Send all collected commands in a single batch request.
     *
     * @param bool $parallel Process commands in parallel on Centrifugo side
     * @return array Replies from Centrifugo
     */
    public function send(bool $parallel = true): array
    {
        if (empty($this->commands)) {
            return [];
        }

        $commands = $this->commands;
        $this->commands = [];

        return $this->centrifugo->batch($commands, $parallel);
    }

    /**
     * Returns the number of collected commands.
     */
    public function count(): int
    {
        return count($this->commands);
    }

    /**
     * Discard all collected commands without sending.
     */
    public function reset(): void
    {
        $this->commands = [];
    }
}
