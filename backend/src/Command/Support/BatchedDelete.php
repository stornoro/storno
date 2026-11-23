<?php

namespace App\Command\Support;

use Doctrine\DBAL\Connection;

/**
 * Deletes rows in small `DELETE ... LIMIT n` statements so no single statement holds locks on a
 * large table for long. MySQL / MariaDB only (DELETE with LIMIT).
 */
final class BatchedDelete
{
    public const DEFAULT_BATCH_SIZE = 5000;

    /**
     * @param string               $deleteSql statement without LIMIT, for example "DELETE FROM t WHERE created_at < :cutoff"
     * @param array<string, mixed> $params
     * @param callable(int): void|null $onBatch called with the number of rows each batch deleted
     *
     * @return int total rows deleted
     */
    public static function run(Connection $connection, string $deleteSql, array $params, int $batchSize = self::DEFAULT_BATCH_SIZE, ?callable $onBatch = null): int
    {
        $total = 0;
        do {
            $deleted = (int) $connection->executeStatement($deleteSql . ' LIMIT ' . max(1, $batchSize), $params);
            $total += $deleted;
            if ($deleted > 0 && $onBatch !== null) {
                $onBatch($deleted);
            }
            if ($deleted === $batchSize) {
                usleep(50_000); // let replicas and other writers in between batches
            }
        } while ($deleted === $batchSize);

        return $total;
    }
}
