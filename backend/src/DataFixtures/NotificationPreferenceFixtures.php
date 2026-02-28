<?php

namespace App\DataFixtures;

use App\Entity\NotificationPreference;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class NotificationPreferenceFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $preferences = [
            // user-1 (admin, John Doe - org-1)
            [
                'user' => 'user-1',
                'eventType' => 'invoice.validated',
                'email' => true,
                'inApp' => true,
                'push' => false,
                'telegram' => false,
                'whatsapp' => false,
            ],
            [
                'user' => 'user-1',
                'eventType' => 'invoice.rejected',
                'email' => true,
                'inApp' => true,
                'push' => true,
                'telegram' => false,
                'whatsapp' => false,
            ],
            [
                'user' => 'user-1',
                'eventType' => 'payment.received',
                'email' => true,
                'inApp' => true,
                'push' => false,
                'telegram' => false,
                'whatsapp' => false,
            ],
            [
                'user' => 'user-1',
                'eventType' => 'sync.error',
                'email' => true,
                'inApp' => true,
                'push' => true,
                'telegram' => false,
                'whatsapp' => false,
            ],
            [
                'user' => 'user-1',
                'eventType' => 'invoice.overdue',
                'email' => true,
                'inApp' => true,
                'push' => false,
                'telegram' => false,
                'whatsapp' => false,
            ],
            // user-5 (freelancer, Ion Popescu - org-3)
            [
                'user' => 'user-5',
                'eventType' => 'invoice.validated',
                'email' => true,
                'inApp' => true,
                'push' => false,
                'telegram' => false,
                'whatsapp' => false,
            ],
            [
                'user' => 'user-5',
                'eventType' => 'payment.received',
                'email' => true,
                'inApp' => true,
                'push' => false,
                'telegram' => false,
                'whatsapp' => false,
            ],
        ];

        foreach ($preferences as $data) {
            $preference = (new NotificationPreference())
                ->setUser($this->getReference($data['user'], User::class))
                ->setEventType($data['eventType'])
                ->setEmailEnabled($data['email'])
                ->setInAppEnabled($data['inApp'])
                ->setPushEnabled($data['push'])
                ->setTelegramEnabled($data['telegram'])
                ->setWhatsappEnabled($data['whatsapp']);

            $manager->persist($preference);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
        ];
    }
}
