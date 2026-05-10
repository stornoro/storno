<?php

namespace App\Service;

use App\Entity\NotificationPreference;
use App\Entity\User;
use App\Repository\EmailUnsubscribeRepository;
use Doctrine\ORM\EntityManagerInterface;

final class LifecycleEmailGate
{
    public function __construct(
        private readonly EmailUnsubscribeRepository $unsubscribeRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function canSend(string $email, string $category, ?User $user = null): bool
    {
        if ($this->isUnsubscribed($email, $category)) {
            return false;
        }

        if ($user !== null) {
            if ($user->getDeletedAt() !== null || !$user->isActive()) {
                return false;
            }

            $pref = $this->entityManager->getRepository(NotificationPreference::class)->findOneBy([
                'user' => $user,
                'eventType' => $category,
            ]);

            if ($pref !== null && !$pref->isEmailEnabled()) {
                return false;
            }
        }

        return true;
    }

    private function isUnsubscribed(string $email, string $category): bool
    {
        $row = $this->unsubscribeRepository->findOneBy([
            'email' => $email,
            'category' => $category,
        ]);

        if ($row !== null) {
            return true;
        }

        $global = $this->unsubscribeRepository->findOneBy([
            'email' => $email,
            'category' => 'all',
        ]);

        return $global !== null;
    }
}
