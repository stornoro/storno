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

    /**
     * @param array{sort?: ?string, type?: ?string, status?: ?string, usage?: ?string, categoryId?: ?string, source?: ?string} $filters
     */
    public function list(Company $company, int $page = 1, int $limit = 20, ?string $search = null, array $filters = []): Paginator
    {
        return $this->productRepository->findByCompanyPaginated($company, $page, $limit, $search, $filters);
    }
}
