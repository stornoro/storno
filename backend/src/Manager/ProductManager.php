<?php

namespace App\Manager;

use App\Entity\Company;
use App\Repository\ProductRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;

class ProductManager
{
    public function __construct(
        private readonly ProductRepository $productRepository,
    ) {}

    public function list(Company $company, int $page = 1, int $limit = 20, ?string $search = null): Paginator
    {
        return $this->productRepository->findByCompanyPaginated($company, $page, $limit, $search);
    }
}
