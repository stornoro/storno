<?php

namespace App\Manager;

use App\Entity\Company;
use App\Repository\ClientRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;

class ClientManager
{
    public function __construct(
        private readonly ClientRepository $clientRepository,
    ) {}

    public function list(Company $company, int $page = 1, int $limit = 20, ?string $search = null): Paginator
    {
        return $this->clientRepository->findByCompanyPaginated($company, $page, $limit, $search);
    }

    /**
     * @return array{data: array<array<string, mixed>>, total: int}
     */
    public function listGrouped(Company $company, int $page = 1, int $limit = 20, ?string $search = null): array
    {
        return $this->clientRepository->findByCompanyGrouped($company, $page, $limit, $search);
    }
}
