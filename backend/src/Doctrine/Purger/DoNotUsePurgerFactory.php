<?php

declare(strict_types=1);


namespace App\Doctrine\Purger;

use Doctrine\Bundle\FixturesBundle\Purger\PurgerFactory;
use Doctrine\Common\DataFixtures\Purger\ORMPurgerInterface;
use Doctrine\Common\DataFixtures\Purger\PurgerInterface;
use Doctrine\ORM\EntityManagerInterface;

class DoNotUsePurgerFactory implements PurgerFactory
{

    public function createForEntityManager(
        ?string $emName,
        EntityManagerInterface $em,
        array $excluded = [],
        bool $purgeWithTruncate = false
    ): PurgerInterface {
        return new class() implements ORMPurgerInterface
        {

            public function purge(): void
            {
                throw new \LogicException('Do not use doctrine:fixtures:load directly. Use partdb:fixtures:load instead!');
            }

            public function setEntityManager(EntityManagerInterface $em)
            {
                // TODO: Implement setEntityManager() method.
            }
        };
    }
}
