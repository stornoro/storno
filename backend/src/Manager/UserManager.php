<?php

namespace App\Manager;

use App\Entity\User;
use App\Manager\Trait\UserTrait;
use App\Repository\CompanyRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

class UserManager
{
    use UserTrait;
    public function __construct(
        private readonly Security $security,
        private readonly UserRepository $userRepository,
        private readonly CompanyRepository $companyRepository,
        private readonly EntityManagerInterface $em

    ) {
        $this->user = $this->security->getUser();
    }

    public function update(User $user = null)
    {
        $this->em->flush();
    }

    public function listCompanies(int $page = 1, ?string $query = null, array $orders = [])
    {

        $userCompanies = $this->user->getCompanies();

        return $userCompanies;
    }

    public function toggleDeveloperMode()
    {
        $this->user->setProduction(!$this->user->isProduction());
        $this->update();
    }
}
