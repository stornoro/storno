<?php

declare(strict_types=1);

namespace App\Tests\Api\Import;

final class ImportCashRegisterTest extends ImportSourceTestCase
{
    public function testDayFileBecomesReceiptsWithADailySummary(): void
    {
        $job = $this->runImport('cash_register', 'receipts', 'a4200-day.xml', ['cashRegisterName' => 'Casa 1']);

        $this->assertSame(3, $job['createdCount'], 'three fiscal receipts');
        $this->assertSame(1, $job['updatedCount'], 'the Z report feeds the summary only');
        $this->assertSame(3, $job['summary']['receiptsCreated']);
        $this->assertSame(1, $job['summary']['zReports']);
        $this->assertSame('408.50', $job['summary']['total']);
        $day = $job['summary']['days'][0];
        $this->assertSame('2026-09-07', $day['date']);
        $this->assertSame(3, $day['receipts']);
        $this->assertSame('221.00', $day['cash']);
        $this->assertSame('187.50', $day['card']);
        $this->assertSame('12', $day['zReports'][0]['number']);
        $this->assertSame(3, $day['zReports'][0]['receiptsDeclared']);

        $receipts = $this->db()->fetchAllAssociative(
            'SELECT number, fiscal_number, device_serial, status, issue_date, subtotal, vat_total, total, payment_method, cash_payment, card_payment, customer_cif, cash_register_name FROM receipt WHERE import_job_id = :j ORDER BY number',
            ['j' => $job['id']],
        );
        $this->assertCount(3, $receipts);
        $first = $receipts[0];
        $this->assertSame('AB00000001-Z12-B1', $first['number']);
        $this->assertSame('AB000000012026090710120000120001', $first['fiscal_number']);
        $this->assertSame('AB00000001', $first['device_serial']);
        $this->assertSame('issued', $first['status']);
        $this->assertSame('2026-09-07', $first['issue_date']);
        $this->assertSame('121.00', $first['total']);
        $this->assertSame('21.00', $first['vat_total']);
        $this->assertSame('100.00', $first['subtotal']);
        $this->assertSame('cash', $first['payment_method']);
        $this->assertSame('Casa 1', $first['cash_register_name']);

        $third = $receipts[2];
        $this->assertSame('AB00000001-Z12-B3', $third['number']);
        $this->assertSame('mixed', $third['payment_method']);
        $this->assertSame('100.00', $third['cash_payment']);
        $this->assertSame('132.00', $third['card_payment']);
        $this->assertSame('12345678', $third['customer_cif']);
        $lines = $this->db()->fetchAllAssociative(
            'SELECT rl.description, rl.vat_rate, rl.vat_amount, rl.line_total FROM receipt_line rl INNER JOIN receipt r ON rl.receipt_id = r.id WHERE r.import_job_id = :j AND r.number = :n ORDER BY rl.position',
            ['j' => $job['id'], 'n' => 'AB00000001-Z12-B3'],
        );
        $this->assertCount(2, $lines, 'one line per VAT level of the receipt');
        $this->assertSame('21.00', $lines[0]['vat_rate']);
        $this->assertSame('100.00', $lines[0]['line_total']);
        $this->assertSame('11.00', $lines[1]['vat_rate']);
        $this->assertSame('100.00', $lines[1]['line_total']);

        // Same file again: every receipt is already there
        $again = $this->runImport('cash_register', 'receipts', 'a4200-day.xml');
        $this->assertSame(0, $again['createdCount']);
        $this->assertSame(3, $again['skippedCount']);

        $this->revert($job['id']);
        $this->assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM receipt WHERE import_job_id = :j', ['j' => $job['id']]));
        $this->revert($again['id']);
    }

    public function testZipOfDayFilesIsAcceptedOnlyForTheCashRegister(): void
    {
        $dir = sys_get_temp_dir();
        $zipPath = $dir . '/a4200-' . uniqid() . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFile(self::FIXTURES . 'a4200-day.xml', 'zi-1.xml');
        $zip->close();
        copy($zipPath, $dir . '/luna.zip');

        $this->client->request('POST', '/api/v1/import/upload', ['importType' => 'receipts', 'source' => 'cash_register'], ['file' => new \Symfony\Component\HttpFoundation\File\UploadedFile($dir . '/luna.zip', 'luna.zip', null, null, true)], $this->buildHeaders($this->headers));
        $this->assertResponseStatusCodeSame(201);
        $job = $this->decodeResponse()['job'];
        $this->assertSame('a4200_zip', $job['fileFormat']);
        $this->assertSame(4, $job['totalRows']);

        copy($zipPath, $dir . '/luna2.zip');
        $this->client->request('POST', '/api/v1/import/upload', ['importType' => 'clients', 'source' => 'generic'], ['file' => new \Symfony\Component\HttpFoundation\File\UploadedFile($dir . '/luna2.zip', 'luna2.zip', null, null, true)], $this->buildHeaders($this->headers));
        $this->assertResponseStatusCodeSame(422);
        unlink($zipPath);
    }
}
