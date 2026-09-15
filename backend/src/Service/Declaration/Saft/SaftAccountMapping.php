<?php

declare(strict_types=1);

namespace App\Service\Declaration\Saft;

/**
 * The fixed document → ledger mapping behind the D406 (SAF-T) file.
 *
 * Storno keeps documents, not a double-entry ledger, so the GeneralLedgerEntries section is
 * derived from the invoices and payments of the period with one fixed set of accounts from
 * the general chart of accounts (PlanConturiBalSocCom). Every amount is in RON.
 *
 *   Sales invoice      D 4111 gross          C 707 (services) / C 7015 (goods) net, per line
 *                                            C 4427 VAT, per VAT rate
 *   Purchase invoice   D 604 (goods) / D 628 (services) net, per line   C 401 gross
 *                      D 4426 VAT, per rate (a company not registered for VAT keeps the VAT
 *                      in the expense: the line is booked gross and no 4426 is written)
 *   Payment received   D 5121 (bank) / D 5311 (cash)     C 4111
 *   Payment made       D 401                             C 5121 / C 5311
 *   Credit notes / storno: the same accounts with the amounts on the opposite side, so every
 *   DebitAmount / CreditAmount stays ≥ 0.
 *
 * Opening balances are unknown to Storno (0) and the closing balances are the movements of
 * the period; the populator reports that in `data.warnings` so an accountant knows what to
 * complete before filing.
 */
final class SaftAccountMapping
{
    public const CUSTOMERS = '4111';
    public const SUPPLIERS = '401';
    public const REVENUE_SERVICES = '707';
    public const REVENUE_GOODS = '7015';
    public const EXPENSE_GOODS = '604';
    public const EXPENSE_SERVICES = '628';
    public const VAT_DEDUCTIBLE = '4426';
    public const VAT_COLLECTED = '4427';
    public const BANK = '5121';
    public const CASH = '5311';

    /** AccountType values of the schema: Activ, Pasiv, Bifunctional. */
    public const ACCOUNTS = [
        self::CUSTOMERS => ['description' => 'Clienti', 'type' => 'Activ'],
        self::SUPPLIERS => ['description' => 'Furnizori', 'type' => 'Pasiv'],
        self::REVENUE_SERVICES => ['description' => 'Venituri din prestari de servicii', 'type' => 'Pasiv'],
        self::REVENUE_GOODS => ['description' => 'Venituri din vanzarea produselor finite / marfurilor', 'type' => 'Pasiv'],
        self::EXPENSE_GOODS => ['description' => 'Cheltuieli privind materialele nestocate / marfurile', 'type' => 'Activ'],
        self::EXPENSE_SERVICES => ['description' => 'Alte cheltuieli cu serviciile executate de terti', 'type' => 'Activ'],
        self::VAT_DEDUCTIBLE => ['description' => 'TVA deductibila', 'type' => 'Activ'],
        self::VAT_COLLECTED => ['description' => 'TVA colectata', 'type' => 'Pasiv'],
        self::BANK => ['description' => 'Conturi la banci in lei', 'type' => 'Bifunctional'],
        self::CASH => ['description' => 'Casa in lei', 'type' => 'Activ'],
    ];

    /** Journals of the derived ledger: id (≤ 18 chars), description, type (≤ 9 chars). */
    public const JOURNALS = [
        'sales' => ['id' => 'VANZARI', 'description' => 'Jurnal de vanzari (facturi emise)', 'type' => 'VZ'],
        'purchases' => ['id' => 'CUMPARARI', 'description' => 'Jurnal de cumparari (facturi primite)', 'type' => 'CP'],
        'bank' => ['id' => 'BANCA', 'description' => 'Registru de banca (incasari si plati prin banca)', 'type' => 'BK'],
        'cash' => ['id' => 'CASA', 'description' => 'Registru de casa (incasari si plati in numerar)', 'type' => 'CS'],
    ];

    /**
     * The mapping as rows for the documentation / the web page.
     *
     * @return list<array{document: string, debit: string, credit: string, note: string}>
     */
    public static function rows(): array
    {
        return [
            ['document' => 'Factura emisa (client)', 'debit' => self::CUSTOMERS, 'credit' => self::REVENUE_SERVICES . ' / ' . self::REVENUE_GOODS, 'note' => 'net per line: 707 for services, 7015 for goods'],
            ['document' => 'Factura emisa (TVA)', 'debit' => self::CUSTOMERS, 'credit' => self::VAT_COLLECTED, 'note' => 'one line per VAT rate'],
            ['document' => 'Factura primita (furnizor)', 'debit' => self::EXPENSE_GOODS . ' / ' . self::EXPENSE_SERVICES, 'credit' => self::SUPPLIERS, 'note' => 'net per line: 604 for goods, 628 for services; gross when the company is not registered for VAT'],
            ['document' => 'Factura primita (TVA)', 'debit' => self::VAT_DEDUCTIBLE, 'credit' => self::SUPPLIERS, 'note' => 'one line per VAT rate, only when registered for VAT'],
            ['document' => 'Incasare', 'debit' => self::BANK . ' / ' . self::CASH, 'credit' => self::CUSTOMERS, 'note' => '5311 for cash payments, 5121 otherwise'],
            ['document' => 'Plata', 'debit' => self::SUPPLIERS, 'credit' => self::BANK . ' / ' . self::CASH, 'note' => '5311 for cash payments, 5121 otherwise'],
            ['document' => 'Storno / nota de credit', 'debit' => '-', 'credit' => '-', 'note' => 'same accounts, amounts on the opposite side'],
        ];
    }

    public static function revenueAccount(bool $isService): string
    {
        return $isService ? self::REVENUE_SERVICES : self::REVENUE_GOODS;
    }

    public static function expenseAccount(bool $isService): string
    {
        return $isService ? self::EXPENSE_SERVICES : self::EXPENSE_GOODS;
    }

    public static function treasuryAccount(bool $cash): string
    {
        return $cash ? self::CASH : self::BANK;
    }
}
