<?php

namespace App\Service\Declaration\XmlGenerator;

use App\Entity\TaxDeclaration;
use App\Service\Declaration\DeclarationXmlGeneratorInterface;

/**
 * Generates the D398 (OSS VAT return, regimul UE) XML: <d398> with the header as attributes,
 * one <MS> per member state of consumption and one <SUPPLY> per rate line inside it.
 *
 * `data.rows` holds the header under ANAF's attribute names, `data.states` the MS blocks (see
 * D398Populator). The per-state totals, due_balance, grand_total_vat_due, nil_vat_return and
 * the control sum totalPlata_A (= moes_voes_imp + nil_vat_return) are recomputed from the
 * supplies so hand-edited amounts keep the validator's arithmetic. The namespace is applied
 * afterwards by DeclarationNamespaceResolver.
 */
class D398XmlGenerator implements DeclarationXmlGeneratorInterface
{
    private const HEADER_ATTRIBUTES = [
        'an_r', 'luna_r', 'd_rec', 'totalPlata_A', 'moes_voes_imp', 'e_int', 'period_start_date', 'period_end_date',
        'nil_vat_return', 'currency', 'vat_id_no', 'intermediary_id', 'name', 'grand_total_vat_due', 'vat_return_reference',
    ];
    private const OPTIONAL_ATTRIBUTES = ['e_int', 'period_start_date', 'period_end_date', 'intermediary_id', 'vat_return_reference'];
    private const SUPPLY_ATTRIBUTES = ['trade_type', 'vat_id_no_msest', 'supply_type', 'vat_rate_type', 'vat_rate', 'taxable_amount', 'vat_amount'];

    public function supportsType(string $type): bool
    {
        return $type === 'd398';
    }

    public function generate(TaxDeclaration $declaration): string
    {
        $data = $declaration->getData() ?? [];
        $company = $declaration->getCompany();
        $rows = $data['rows'] ?? [];
        $states = is_array($data['states'] ?? null) ? $data['states'] : [];

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('d398');
        $root->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');

        // MS blocks first: the header totals depend on them
        $msElements = [];
        $grandTotalDue = '0.00';
        foreach ($states as $state) {
            $code = strtoupper((string) ($state['mscon_state'] ?? ''));
            if ($code === '') {
                continue;
            }
            $totals = ['vat_total_goods_msid' => '0.00', 'vat_total_services_msid' => '0.00', 'vat_total_goods_msest' => '0.00', 'vat_total_services_msest' => '0.00'];
            $supplyElements = [];
            foreach ((array) ($state['supplies'] ?? []) as $supply) {
                $taxable = number_format((float) ($supply['taxable_amount'] ?? 0), 2, '.', '');
                if ((float) $taxable <= 0) {
                    continue;
                }
                $rate = (float) ($supply['vat_rate'] ?? 0);
                $vat = number_format(round((float) $taxable * $rate / 100, 2), 2, '.', '');
                $el = $dom->createElement('SUPPLY');
                $values = [
                    'trade_type' => (string) ($supply['trade_type'] ?? '1'),
                    'vat_id_no_msest' => (string) ($supply['vat_id_no_msest'] ?? ''),
                    'supply_type' => (string) ($supply['supply_type'] ?? '1'),
                    'vat_rate_type' => (string) ($supply['vat_rate_type'] ?? '1'),
                    'vat_rate' => rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.'),
                    'taxable_amount' => $taxable,
                    'vat_amount' => $vat,
                ];
                foreach (self::SUPPLY_ATTRIBUTES as $attr) {
                    if ($attr === 'vat_id_no_msest' && $values[$attr] === '') {
                        continue;
                    }
                    $el->setAttribute($attr, $values[$attr]);
                }
                $supplyElements[] = $el;
                $key = 'vat_total_' . ($values['supply_type'] === '2' ? 'services' : 'goods') . '_' . ($values['trade_type'] === '2' ? 'msest' : 'msid');
                $totals[$key] = bcadd($totals[$key], $vat, 2);
            }
            if (!$supplyElements) {
                continue; // an MS without SUPPLY is refused by the validator
            }
            $grandTotal = bcadd(bcadd($totals['vat_total_goods_msid'], $totals['vat_total_services_msid'], 2), bcadd($totals['vat_total_goods_msest'], $totals['vat_total_services_msest'], 2), 2);
            $dueBalance = $grandTotal;
            if ((float) $dueBalance > 0) {
                $grandTotalDue = bcadd($grandTotalDue, $dueBalance, 2);
            }
            $ms = $dom->createElement('MS');
            $ms->setAttribute('mscon_state', $code);
            $ms->setAttribute('grand_total', $grandTotal);
            foreach (['vat_total_services_msid', 'vat_total_goods_msid', 'vat_total_services_msest', 'vat_total_goods_msest'] as $attr) {
                $ms->setAttribute($attr, $totals[$attr]);
            }
            $ms->setAttribute('due_balance', $dueBalance);
            foreach ($supplyElements as $el) {
                $ms->appendChild($el);
            }
            $msElements[] = $ms;
        }

        $scheme = (string) ($rows['moes_voes_imp'] ?? '1');
        $nil = $msElements === [] ? '1' : '0';
        $header = [
            'an_r' => (string) $declaration->getYear(),
            'luna_r' => (string) $declaration->getMonth(),
            'd_rec' => '0',
            'totalPlata_A' => (string) ((int) $scheme + (int) $nil),
            'moes_voes_imp' => $scheme,
            'e_int' => '0',
            'period_start_date' => '',
            'period_end_date' => '',
            'nil_vat_return' => $nil,
            'currency' => 'EUR',
            'vat_id_no' => 'RO' . $company->getCif(),
            'intermediary_id' => '',
            'name' => mb_substr((string) ($company->getName() ?? ''), 0, 100),
            'grand_total_vat_due' => $grandTotalDue,
            'vat_return_reference' => '',
        ];
        foreach (self::HEADER_ATTRIBUTES as $attr) {
            if (in_array($attr, ['totalPlata_A', 'nil_vat_return', 'grand_total_vat_due'], true)) {
                continue; // always the recomputed value
            }
            if (isset($rows[$attr]) && $rows[$attr] !== '') {
                $header[$attr] = (string) $rows[$attr];
            }
        }
        foreach (self::HEADER_ATTRIBUTES as $attr) {
            $value = $header[$attr];
            if ($value === '' && in_array($attr, self::OPTIONAL_ATTRIBUTES, true)) {
                continue;
            }
            $root->setAttribute($attr, $value);
        }
        foreach ($msElements as $ms) {
            $root->appendChild($ms);
        }

        $dom->appendChild($root);

        return $dom->saveXML();
    }
}
