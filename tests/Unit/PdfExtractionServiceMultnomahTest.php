<?php

namespace Tests\Unit;

use App\Services\PdfExtractionService;
use PHPUnit\Framework\TestCase;

class PdfExtractionServiceMultnomahTest extends TestCase
{
    private function multnomahPurchaseOrderFixture(): string
    {
        return <<<'TEXT'
MULTNOMAH COUNTY OREGON
Bill to and ship to addresses are the same unless otherwise specified below.
Contract Purchase Order
Supplier Address
URBAN LEAGUE OF PORTLAND
10 N RUSSELL ST
PORTLAND, OR 97227-1619
United States of America
Change Order POID.000036442
Date 08/14/2025
Supplier No. 22708
Buyer/Phone Department of County Human
Services (DCHS)
/(503) 9888239
Due Date 06/30/2026
Shipping Terms DESTINATION
Ship To:
Kennery Barrera
209 SW 4th Avenue
Portland, OR 97204-0000
United States of America
kennery.barrera@multco.us
Line Item/Description Quantity UoM Unit Price Net Amount
1 Family Unification Program
Validity Dates: July 1, 2025 - June 30, 2026
- - $186,001.00
Total $261,032.50
TEXT;
    }

    public function test_extract_for_contract_multnomah_county_purchase_order(): void
    {
        $svc = new PdfExtractionService;
        $out = $svc->extractForContract($this->multnomahPurchaseOrderFixture(), 'POID.000036442 export');

        $this->assertSame('POID.000036442', $out['agreement_number']);
        $this->assertSame('URBAN LEAGUE OF PORTLAND', $out['recipient_name']);
        $this->assertSame('10 N RUSSELL ST', $out['recipient_street_address']);
        $this->assertSame('PORTLAND, OR 97227-1619', $out['recipient_city_state_zip']);
        $this->assertNull($out['recipient_email'] ?? null);

        $this->assertSame('Multnomah County Oregon', $out['company_name']);
        $this->assertSame('209 SW 4th Avenue', $out['company_street_address']);
        $this->assertSame('Portland, OR 97204-0000', $out['company_city_state_zip']);
        $this->assertSame('Kennery Barrera', $out['company_grant_administrator']);
        $this->assertSame('kennery.barrera@multco.us', $out['company_email']);
        $this->assertSame('503-988-8239', $out['company_telephone']);

        $this->assertSame('July 1, 2025', $out['effective_date']);
        $this->assertSame('June 30, 2026', $out['expiry_date']);
        $this->assertSame('08/14/2025', $out['template_date']);
        $this->assertSame('261,032.50', $out['grant_amount']);

        $this->assertStringContainsString('Department of County Human', $out['company_division'] ?? '');
        $this->assertStringContainsString('Services (DCHS)', $out['company_division'] ?? '');
    }

    public function test_extract_agreement_number_poid_from_filename_only(): void
    {
        $svc = new PdfExtractionService;
        $out = $svc->extractForContract('Some unrelated grant text without multnomah markers.', 'POID.000036442 2026-04-22');

        $this->assertSame('POID.000036442', $out['agreement_number']);
    }
}
