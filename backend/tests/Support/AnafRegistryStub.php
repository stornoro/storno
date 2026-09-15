<?php

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Scripted answers for ANAF's public registry (PlatitorTvaRest) in the test
 * environment: wired as the response factory of the MockHttpClient that
 * `App\Services\AnafService` receives (config/services_test.yaml). State is
 * static so it survives the kernel reboot the KernelBrowser performs between
 * requests. Without a script every CUI is "not found" and nothing leaves the
 * machine.
 */
final class AnafRegistryStub
{
    /** @var array<string, array<string, mixed>> CUI digits → `found` entry */
    private static array $entries = [];
    private static bool $available = true;
    private static int $calls = 0;

    public static function reset(): void
    {
        self::$entries = [];
        self::$available = true;
        self::$calls = 0;
    }

    /** ANAF answers with this entry for the CUI (merged over a plausible default). */
    public static function found(string $cui, array $overrides = []): void
    {
        $cui = preg_replace('/\D/', '', $cui);
        self::$entries[$cui] = array_replace_recursive(self::defaultEntry($cui), $overrides);
    }

    public static function notFound(string $cui): void
    {
        unset(self::$entries[preg_replace('/\D/', '', $cui)]);
    }

    /** Simulate the registry being unreachable. */
    public static function unavailable(bool $unavailable = true): void
    {
        self::$available = !$unavailable;
    }

    public static function calls(): int
    {
        return self::$calls;
    }

    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        self::$calls++;
        if (!self::$available) {
            return new MockResponse('', ['error' => 'ANAF registry stub: unavailable']);
        }

        $body = json_decode((string) ($options['body'] ?? '[]'), true) ?: [];
        $found = [];
        $notFound = [];
        foreach ($body as $item) {
            $cui = (string) ($item['cui'] ?? '');
            if (isset(self::$entries[$cui])) {
                $found[] = self::$entries[$cui];
            } else {
                $notFound[] = (int) $cui;
            }
        }

        return new MockResponse(json_encode(['cod' => 200, 'message' => 'SUCCESS', 'found' => $found, 'notFound' => $notFound]), [
            'http_code' => 200,
            'response_headers' => ['Content-Type' => 'application/json'],
        ]);
    }

    public static function defaultEntry(string $cui): array
    {
        return [
            'date_generale' => [
                'cui' => (int) $cui, 'denumire' => 'PARTENER TEST SRL', 'adresa' => 'STR. TEST 1', 'nrRegCom' => 'J40/2/2020',
                'telefon' => '', 'codPostal' => '010101', 'stare_inregistrare' => 'INREGISTRAT din data 01.01.2020',
                'statusRO_e_Factura' => true, 'data_inreg_Reg_RO_e_Factura' => '2024-01-01', 'iban' => '', 'organFiscalCompetent' => '',
                'forma_juridica' => '', 'forma_de_proprietate' => '', 'forma_organizare' => '', 'cod_CAEN' => '6201', 'data_inregistrare' => '2020-01-01',
            ],
            'adresa_sediu_social' => ['scod_JudetAuto' => 'B', 'sdenumire_Localitate' => 'Bucuresti Sectorul 1', 'scod_Postal' => '010101'],
            'inregistrare_scop_Tva' => ['scpTVA' => true, 'perioade_TVA' => [['data_inceput_ScpTVA' => '2020-01-01', 'data_sfarsit_ScpTVA' => ' ']]],
            'inregistrare_RTVAI' => ['statusTvaIncasare' => false, 'dataInceputTvaInc' => ' ', 'dataSfarsitTvaInc' => ' '],
            'stare_inactiv' => ['statusInactivi' => false, 'dataInactivare' => ' ', 'dataReactivare' => ' ', 'dataRadiere' => ' '],
            'inregistrare_SplitTVA' => ['statusSplitTVA' => false],
        ];
    }
}
