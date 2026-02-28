<?php

namespace App\Manager;

use App\Entity\User;
use App\Manager\Trait\UserTrait;
use App\Repository\InvoiceRepository;
use Symfony\Bundle\SecurityBundle\Security;

class StatsManager
{
    use UserTrait;
    public function __construct(
        private readonly Security $security,
        private readonly InvoiceRepository $invoiceRepository,

    ) {
        $this->user = $this->security->getUser();
    }

    public function getStatistics()
    {
        $totalGroupped = $this->invoiceRepository->getTotalInvoicesGrouppedByDirection($this->user);
        $totalLastWeek = $this->invoiceRepository->getTotalInvoices(new \DateTime('-7 days'), $this->user);
        $percentage = $this->invoiceRepository->getPercentage($this->user);
        $output = [
            'total' => $totalGroupped,
            'last_week' => $totalLastWeek,
            'percentage' => $percentage
        ];
        return $output;
    }
}
