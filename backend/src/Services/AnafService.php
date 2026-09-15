<?php

declare(strict_types=1);

namespace App\Services;

use App\Model\Anaf\CompanyInfo;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AnafService
{
    const API_URL = 'https://webservicesp.anaf.ro/api/PlatitorTvaRest/v9/tva';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {}

    public function findCompany(string $cif): ?CompanyInfo
    {
        $cif = preg_replace('/\D/', '', $cif);

        try {
            $response = $this->httpClient->request('POST', self::API_URL, [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode([['cui' => (int) $cif, 'data' => date('Y-m-d')]]),
            ]);

            $data = json_decode($response->getContent(false), true);
        } catch (\Throwable $e) {
            $this->logger->error('ANAF API error', ['cif' => $cif, 'error' => $e->getMessage()]);
            return null;
        }

        if (!$data || !empty($data['notFound'])) {
            $this->logger->info('ANAF CIF lookup: not found.', ['cif' => $cif]);
            return null;
        }

        return CompanyInfo::createFromAnaf($data);
    }

    /**
     * Same as findCompanies(), but distinguishes "ANAF did not answer" (null)
     * from "ANAF answered and the CIF is unknown" (entry mapped to null).
     *
     * @param string[] $cifs
     * @return array<string, CompanyInfo|null>|null keyed by CIF digits; null when the registry was unreachable
     */
    public function findCompaniesOrNull(array $cifs): ?array
    {
        $date = date('Y-m-d');
        $digits = array_values(array_unique(array_map(fn(string $cif) => preg_replace('/\D/', '', $cif), $cifs)));
        $payload = array_map(fn(string $cif) => ['cui' => (int) $cif, 'data' => $date], $digits);

        try {
            $response = $this->httpClient->request('POST', self::API_URL, [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode($payload),
                'timeout' => 15,
            ]);
            $status = $response->getStatusCode();
            $data = json_decode($response->getContent(false), true);
        } catch (\Throwable $e) {
            $this->logger->error('ANAF API batch error', ['error' => $e->getMessage()]);
            return null;
        }

        if ($status >= 400 || !is_array($data) || (!isset($data['found']) && !isset($data['notFound']))) {
            $this->logger->warning('ANAF API batch: unexpected answer', ['status' => $status]);
            return null;
        }

        $results = array_fill_keys($digits, null);
        foreach ($data['found'] ?? [] as $entry) {
            $cui = (string) ($entry['date_generale']['cui'] ?? '');
            if ($cui !== '') {
                $results[$cui] = CompanyInfo::createFromAnaf(['found' => [$entry]]);
            }
        }

        return $results;
    }

    /**
     * Lookup multiple CIFs in a single API call (max 500 per ANAF docs).
     *
     * @param string[] $cifs
     * @return array<string, CompanyInfo|null> keyed by CIF
     */
    public function findCompanies(array $cifs): array
    {
        $date = date('Y-m-d');
        $payload = array_map(fn(string $cif) => [
            'cui' => (int) preg_replace('/\D/', '', $cif),
            'data' => $date,
        ], $cifs);

        try {
            $response = $this->httpClient->request('POST', self::API_URL, [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode($payload),
            ]);

            $data = json_decode($response->getContent(false), true);
        } catch (\Throwable $e) {
            $this->logger->error('ANAF API batch error', ['error' => $e->getMessage()]);
            return array_fill_keys(array_map(fn($c) => preg_replace('/\D/', '', $c), $cifs), null);
        }

        $results = [];

        foreach ($data['found'] ?? [] as $entry) {
            $cui = (string) $entry['date_generale']['cui'];
            $results[$cui] = CompanyInfo::createFromAnaf(['found' => [$entry]]);
        }

        foreach ($data['notFound'] ?? [] as $entry) {
            $cui = (string) ($entry['cui'] ?? $entry);
            $results[$cui] = null;
        }

        return $results;
    }
}
