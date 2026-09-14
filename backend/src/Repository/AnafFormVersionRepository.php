<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AnafFormVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AnafFormVersion> */
class AnafFormVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnafFormVersion::class);
    }

    /** @return array<string, AnafFormVersion> keyed by form code */
    public function findAllIndexed(): array
    {
        $out = [];
        foreach ($this->findBy([], ['form' => 'ASC']) as $row) {
            $out[$row->getForm()] = $row;
        }

        return $out;
    }
}
