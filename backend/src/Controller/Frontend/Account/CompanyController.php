<?php

namespace App\Controller\Frontend\Account;

use App\Entity\Company;
use App\Entity\User;
use App\Manager\CompanyManager;
use App\Manager\UserManager;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/api/account')]
#[IsGranted('ROLE_USER', statusCode: 403)]
class CompanyController extends AbstractController
{

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    #[Route(
        '/me/companies',
        name: 'frontend_api_user_companies',
        methods: ['GET'],
        defaults: [
            '_api_resource_class' => Company::class,
            '_api_operation_name' => 'user-companies',
        ]
    )]
    public function companies(Request $request, UserManager $userManager)
    {
        $user = $this->getUser();
        $page = (int) $request->query->get('page', 1);
        $order = $request->query->all('order');
        $query = $request->query->get('query');
        //
        $companies = $userManager
            ->listCompanies($page, $query, $order);

        return $companies;
    }

    #[Route(
        '/me/company/{cif}',
        methods: ['POST'],
    )]
    public function getCompany(Company $company, Request $request, CompanyManager $companyManager)
    {
        /** @var User $user */
        $user = $this->getUser();
        if (false === $user->hasCompany($company)) {
            throw new \Exception('You do not own this company');
        }
        return $this->json([
            'status' => 'ok',
            'data' => $company
        ]);
    }
    #[Route(
        '/me/company/check',
        methods: ['POST'],
    )]
    public function getCompanyDataFromAnaf(Request $request, CompanyManager $companyManager)
    {
        $companyInfo = null;
        $message = null;
        $status = 'ok';
        try {
            $content = json_decode($request->getContent(), true);
            $cif = $content['cif'];
            $companyInfo = $companyManager->getCompanyData($cif);
            if (null === $companyInfo) {
                throw new \RuntimeException('Nu am gasit nicio companie valida cu acest CIF.');
            }
        } catch (\Exception $e) {
            $this->logger->debug('[getCompanyDataFromAnaf] Error', [
                'payload' => $content ?? null,
            ]);
            $status = 'fail';
            $message = $e->getMessage();
        }

        return $this->json([
            'status' => $status,
            'data' => $companyInfo ? [
                'cif' => $companyInfo->getCif(),
                'name' => $companyInfo->getName(),
                'address' => $companyInfo->getAddress(),
                'city' => $companyInfo->getCity(),
                'state' => $companyInfo->getState(),
                'country' => $companyInfo->getCountry(),
                'sector' => $companyInfo->getSector(),
                'postalCode' => $companyInfo->getPostalCode(),
                'phone' => $companyInfo->getPhone(),
                'registrationNumber' => $companyInfo->getRegistrationNumber(),
                'caenCode' => $companyInfo->getCaenCode(),
                'vatPayer' => $companyInfo->isVatPayer(),
                'vatCode' => $companyInfo->getVatCode(),
                'registrationStatus' => $companyInfo->getRegistrationStatus(),
                'registrationDate' => $companyInfo->getRegistrationDate(),
                'eFacturaEnabled' => $companyInfo->isEFacturaEnabled(),
                'eFacturaDate' => $companyInfo->getEFacturaDate(),
                'inactive' => $companyInfo->isInactive(),
                'splitVat' => $companyInfo->isSplitVat(),
                'vatOnCollection' => $companyInfo->isVatOnCollection(),
                'legalForm' => $companyInfo->getLegalForm(),
                'fiscalAuthority' => $companyInfo->getFiscalAuthority(),
                'iban' => $companyInfo->getIban(),
            ] : null,
            'message' => $message,
        ]);
    }

    #[Route(
        '/me/company',
        methods: ['POST'],
    )]
    public function createCompany(Request $request, CompanyManager $companyManager)
    {
        $company = null;
        $message = null;
        $status = 'ok';
        try {
            $content = json_decode($request->getContent(), true);
            $cif = $content['cif'];

            $company = $companyManager->createFromAnaf($cif);

            if (!$company) {
                throw new \RuntimeException('Nu am gasit nicio companie valida cu acest CIF.');
            }
        } catch (\Exception $e) {
            $this->logger->debug('[createCompany] Error', [
                'payload' => $content ?? null,
                'message' => $e->getMessage()
            ]);
            $status = 'fail';
            $message = $e->getMessage();
        }

        return $this->json([
            'status' => $status,
            'data' => $company,
            'message' => $message
        ]);
    }

    #[Route(
        '/me/company/{cif}',
        methods: ['DELETE', 'POST'],
    )]
    public function delete(string $cif, Request $request, CompanyManager $companyManager)
    {

        $status = 'ok';
        $message = null;
        try {
            $company = $companyManager->getByCif($cif);
            $companyManager->delete($company);
        } catch (\Exception $e) {
            $status = 'fail';
            $message = $e->getMessage();
        }

        return $this->json([
            'status' => $status,
            'message' => $message
        ]);
    }
}
