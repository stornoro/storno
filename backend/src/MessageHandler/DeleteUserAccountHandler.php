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

            // 2. Delete user-level data
            $conn->executeStatement('DELETE FROM login_history WHERE user_id = ?', [$userId]);
            $conn->executeStatement('DELETE FROM api_token WHERE user_id = ?', [$userId]);
            $conn->executeStatement('DELETE FROM notification WHERE user_id = ?', [$userId]);
            $conn->executeStatement('DELETE FROM anaf_token WHERE user_id = ?', [$userId]);
            $conn->executeStatement('DELETE FROM user_billing WHERE user_id = ?', [$userId]);

            // 3. Nullify soft-delete audit references (deletedBy)
            $conn->executeStatement('UPDATE company SET deleted_by_id = NULL WHERE deleted_by_id = ?', [$userId]);
            $conn->executeStatement('UPDATE invoice SET deleted_by_id = NULL WHERE deleted_by_id = ?', [$userId]);
            $conn->executeStatement('UPDATE client SET deleted_by_id = NULL WHERE deleted_by_id = ?', [$userId]);
            $conn->executeStatement('UPDATE product SET deleted_by_id = NULL WHERE deleted_by_id = ?', [$userId]);
            $conn->executeStatement('UPDATE supplier SET deleted_by_id = NULL WHERE deleted_by_id = ?', [$userId]);

            // 4. Hard-delete the user
            $conn->executeStatement('DELETE FROM "user" WHERE id = ?', [$userId]);

            $this->logger->info('DeleteUserAccount: Cascade deletion completed.', ['userId' => $userId]);
        } finally {
            if ($filterWasEnabled) {
                $filters->enable('soft_delete');
            }
        }
    }
}
