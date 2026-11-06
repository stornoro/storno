<?php

namespace App\MessageHandler;

use App\Message\DeleteCompanyDataMessage;
use App\Message\DeleteUserAccountMessage;
use App\Repository\CompanyRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
class DeleteUserAccountHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly CompanyRepository $companyRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(DeleteUserAccountMessage $message): void
    {
        // Disable soft-delete filter to access the soft-deleted user and their data
        $filters = $this->em->getFilters();
        $filterWasEnabled = $filters->isEnabled('soft_delete');
        if ($filterWasEnabled) {
            $filters->disable('soft_delete');
        }

        try {
            $user = $this->userRepository->find(Uuid::fromString($message->userId));
            if (!$user) {
                $this->logger->warning('DeleteUserAccount: User not found.', ['id' => $message->userId]);
                return;
            }

            $userId = $message->userId;
            $conn = $this->em->getConnection();

            $this->logger->info('DeleteUserAccount: Starting cascade deletion.', ['userId' => $userId]);

            // 1. Find all organizations where user is the ONLY member (owner)
            //    These organizations (and their companies) should be fully deleted.
            $orgRows = $conn->fetchAllAssociative(
                'SELECT om.organization_id FROM organization_membership om
                 WHERE om.user_id = ?',
                [$userId]
            );

            foreach ($orgRows as $row) {
                $orgId = $row['organization_id'];

                // Check if user is the sole member
                $memberCount = (int) $conn->fetchOne(
                    'SELECT COUNT(*) FROM organization_membership WHERE organization_id = ?',
                    [$orgId]
                );

                // Get all companies in this organization
                $companyRows = $conn->fetchAllAssociative(
                    'SELECT id FROM company WHERE organization_id = ?',
                    [$orgId]
                );

                if ($memberCount <= 1) {
                    // User is sole member — dispatch deletion for each company
                    foreach ($companyRows as $companyRow) {
                        $this->messageBus->dispatch(new DeleteCompanyDataMessage($companyRow['id']));
                    }

                    // Delete the organization itself after companies are queued
                    $conn->executeStatement('DELETE FROM organization_membership WHERE organization_id = ?', [$orgId]);
                    $conn->executeStatement('DELETE FROM organization WHERE id = ?', [$orgId]);
                } else {
                    // Other members exist — just remove this user's membership
                    $conn->executeStatement(
                        'DELETE FROM organization_membership WHERE organization_id = ? AND user_id = ?',
                        [$orgId, $userId]
                    );
                }
            }

            // 2. Billing profile hangs off the user (user.user_billing_id), not
            //    the other way round: detach it first, then drop the orphaned row.
            $userTable = $conn->getDatabasePlatform()->quoteSingleIdentifier('user');
            $billingId = $conn->fetchOne("SELECT user_billing_id FROM {$userTable} WHERE id = ?", [$userId]);
            if ($billingId) {
                $conn->executeStatement("UPDATE {$userTable} SET user_billing_id = NULL WHERE id = ?", [$userId]);
                $conn->executeStatement('DELETE FROM user_billing WHERE id = ?', [$billingId]);
            }

            // 3. Every other foreign key that points at the user, discovered from
            //    the schema so new tables cannot break deletion again. Audit
            //    references (created_by, updated_by, deleted_by, ...) are nullable
            //    and get detached; per-user rows (tokens, devices, passkeys,
            //    preferences, ...) have a NOT NULL user_id and are removed.
            $schema = $conn->createSchemaManager();
            foreach ($schema->listTableNames() as $tableName) {
                // `user` itself is included: user.deleted_by_id points back at users.
                $columns = null;
                foreach ($schema->listTableForeignKeys($tableName) as $fk) {
                    if ($fk->getForeignTableName() !== 'user') {
                        continue;
                    }
                    $columns ??= $schema->listTableColumns($tableName);
                    $quotedTable = $conn->getDatabasePlatform()->quoteSingleIdentifier($tableName);
                    foreach ($fk->getLocalColumns() as $column) {
                        $quotedColumn = $conn->getDatabasePlatform()->quoteSingleIdentifier($column);
                        $nullable = isset($columns[$column]) ? !$columns[$column]->getNotnull() : true;
                        if ($tableName === 'user' && $column === 'id') {
                            continue;
                        }
                        if ($nullable) {
                            $conn->executeStatement("UPDATE {$quotedTable} SET {$quotedColumn} = NULL WHERE {$quotedColumn} = ?", [$userId]);
                        } else {
                            $conn->executeStatement("DELETE FROM {$quotedTable} WHERE {$quotedColumn} = ?", [$userId]);
                        }
                    }
                }
            }

            // 4. Hard-delete the user. `user` is reserved on both MySQL and
            // Postgres, so let DBAL quote it the way the active platform
            // expects (backticks on MySQL, double quotes on Postgres).
            $conn->executeStatement("DELETE FROM {$userTable} WHERE id = ?", [$userId]);

            $this->logger->info('DeleteUserAccount: Cascade deletion completed.', ['userId' => $userId]);
        } finally {
            if ($filterWasEnabled) {
                $filters->enable('soft_delete');
            }
        }
    }
}
