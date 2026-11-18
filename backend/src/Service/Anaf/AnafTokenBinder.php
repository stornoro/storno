<?php

namespace App\Service\Anaf;

use App\Entity\AnafToken;
use App\Entity\Company;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Binds an ANAF token to one company (exclusive mode, see AnafTokenResolver::resolveEntity()).
 *
 * Called by the link callbacks once ANAF has confirmed that the token has rights on the CIF of
 * the company. The caller flushes.
 */
class AnafTokenBinder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function bind(Company $company, AnafToken $anafToken): void
    {
        $previous = $company->getAnafToken();

        $anafToken->setExclusive(true);
        $company->setAnafToken($anafToken);
        $company->setAnafTokenExclusive(true);

        if ($company->getSpvAccessError() !== null) {
            $company->clearSpvAccessError();
        }

        // The token this one replaces served this company alone, so nothing else can use it.
        if ($previous !== null && $previous !== $anafToken && $previous->isExclusive()) {
            $previous->getUser()?->removeAnafToken($previous);
            $this->entityManager->remove($previous);
        }
    }
}
