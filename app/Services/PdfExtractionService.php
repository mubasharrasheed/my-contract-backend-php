<?php

namespace App\Services;

/**
 * Extract structured data from OHA-style Grant Agreement PDFs (and similar).
 * Parses raw text from smalot/pdfparser into company (grantor) and recipient.
 */
class PdfExtractionService
{
    public function extractFromRawText(string $rawText): array
    {
        $text = preg_replace('/\s+/', ' ', $rawText);
        $lines = $this->getLines($rawText);

        return [
            'agreement_number' => $this->extractAgreementNumber($text, $lines),
            'grant_amount' => $this->extractGrantAmount($text),
            'effective_date' => $this->extractEffectiveDate($text),
            'expiry_date' => $this->extractExpiryDate($text),
            'template_date' => $this->extractTemplateDate($text),
            'company' => $this->extractCompany($rawText, $lines),
            'recipient' => $this->extractRecipient($rawText, $lines),
            'assistance_listings' => $this->extractAssistanceListings($text),
            'extracted_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Return flat array suitable for Contract::create() (excluding user_id).
     */
    public function extractForContract(string $rawText): array
    {
        $data = $this->extractFromRawText($rawText);
        $company = $data['company'] ?? [];
        $recipient = $data['recipient'] ?? [];

        return array_filter([
            'agreement_number' => $data['agreement_number'] ?? null,
            'grant_amount' => $data['grant_amount'] ?? null,
            'effective_date' => $data['effective_date'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
            'template_date' => $data['template_date'] ?? null,
            'assistance_listings' => $data['assistance_listings'] ?? null,
            'recipient_name' => $recipient['name'] ?? null,
            'recipient_street_address' => $recipient['street_address'] ?? null,
            'recipient_city_state_zip' => $recipient['city_state_zip'] ?? null,
            'recipient_attention' => $recipient['attention'] ?? null,
            'recipient_telephone' => $recipient['telephone'] ?? null,
            'recipient_email' => $recipient['email'] ?? null,
            'company_name' => $company['name'] ?? null,
            'company_division' => $company['division'] ?? null,
            'company_office' => $company['office'] ?? null,
            'company_street_address' => $company['street_address'] ?? null,
            'company_city_state_zip' => $company['city_state_zip'] ?? null,
            'company_grant_administrator' => $company['grant_administrator'] ?? null,
            'company_telephone' => $company['telephone'] ?? null,
            'company_email' => $company['email'] ?? null,
        ]);
    }

    protected function getLines(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        return array_map('trim', array_filter($lines));
    }

    protected function extractAgreementNumber(string $text, array $lines): ?string
    {
        if (preg_match('/Grant\s+Agreement\s+Number\s+([A-Z0-9\-]+)/i', $text, $m)) {
            return $m[1];
        }
        foreach ($lines as $line) {
            if (preg_match('/^Grant\s+Agreement\s+Number\s+(.+)$/i', $line, $m)) {
                return trim($m[1]);
            }
        }
        return null;
    }

    protected function extractGrantAmount(string $text): ?string
    {
        if (preg_match('/maximum\s+not-to-exceed\s+amount[^$]*?\$\s*([\d,]+\.?\d*)/i', $text, $m)) {
            return $m[1];
        }
        if (preg_match('/is\s+\$([\d,]+\.?\d*)/', $text, $m)) {
            return $m[1];
        }
        return null;
    }

    protected function extractEffectiveDate(string $text): ?string
    {
        if (preg_match('/may\s+start\s+on\s+([A-Za-z]+\s+\d{1,2},?\s+\d{4})/i', $text, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/effective\s+on\s+([A-Za-z]+\s+\d{1,2},?\s+\d{4})/i', $text, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    protected function extractExpiryDate(string $text): ?string
    {
        if (preg_match('/expire\s+on\s+([A-Za-z]+\s+\d{1,2},?\s+\d{4})/i', $text, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/expires?\s+on\s+([A-Za-z]+\s+\d{1,2},?\s+\d{4})/i', $text, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    protected function extractTemplateDate(string $text): ?string
    {
        if (preg_match('/Updated:\s*(\d{1,2}\/\d{1,2}\/\d{4})/i', $text, $m)) {
            return $m[1];
        }
        return null;
    }

    protected function extractAssistanceListings(string $text): array
    {
        if (preg_match('/Assistance\s+Listings\s+number\(s\)[^:]*:\s*([\d.\s and,]+)/i', $text, $m)) {
            $nums = preg_replace('/\s+and\s+/', ' ', $m[1]);
            preg_match_all('/\d+\.\d+/', $nums, $out);
            return $out[0] ?? [];
        }
        return [];
    }

    /**
     * OHA (grantor) block: typically after "Recipient" block, lines like
     * "External Relations Division", "Office of ...", address, "Grant Administrator:", "Telephone:", "E-mail address:"
     */
    protected function extractCompany(string $raw, array $lines): array
    {
        $company = [
            'name' => null,
            'division' => null,
            'office' => null,
            'street_address' => null,
            'city_state_zip' => null,
            'grant_administrator' => null,
            'telephone' => null,
            'email' => null,
        ];

        $rawLower = mb_strtolower($raw);
        if (str_contains($rawLower, 'oregon health authority')) {
            $company['name'] = 'Oregon Health Authority';
        }

        if (preg_match('/Grant\s+Administrator:\s*([^\n\r]+?)(?=\s*Telephone|$)/i', $raw, $m)) {
            $company['grant_administrator'] = trim(preg_replace('/\s+/', ' ', $m[1]));
        }
        // Company contact is the second occurrence (after Recipient block)
        if (preg_match_all('/E-mail\s+address:\s*([a-zA-Z0-9@._-]+)/i', $raw, $m)) {
            $company['email'] = count($m[1]) >= 2 ? trim($m[1][1]) : trim($m[1][0] ?? '');
        }
        if (preg_match_all('/Telephone:\s*(\d{3}-\d{3}-\d{4}[^\s\r\n]*)/', $raw, $m)) {
            $company['telephone'] = count($m[1]) >= 2 ? trim($m[1][1]) : trim($m[1][0] ?? '');
        }

        foreach ($lines as $i => $line) {
            if (preg_match('/^External\s+.+Division$/i', $line)) {
                $company['division'] = $line;
            }
            if (preg_match('/^Office\s+of\s+.+$/i', $line) && empty($company['office'])) {
                $company['office'] = $line;
            }
            if (preg_match('/^\d+\s+[A-Za-z\s]+(?:Street|St|Ave|Avenue|NE|NW|SE|SW|N\.?E\.?)\s*,?\s*$/i', $line) && empty($company['street_address'])) {
                $company['street_address'] = $line;
            }
            if (preg_match('/^(Salem|Portland|Eugene),\s*(Oregon|OR)\s*\d{5}/i', $line)) {
                $company['city_state_zip'] = $line;
            }
        }

        return array_filter($company);
    }

    /**
     * Recipient block: name, street, city/state/zip, Attention, Telephone, E-mail.
     * In OHA PDFs the recipient block appears right after "OHA" and before the "Recipient" label.
     */
    protected function extractRecipient(string $raw, array $lines): array
    {
        $recipient = [
            'name' => null,
            'street_address' => null,
            'city_state_zip' => null,
            'attention' => null,
            'telephone' => null,
            'email' => null,
        ];

        if (preg_match('/Attention:\s*([^\n\r]+?)(?=\s*Telephone|$)/i', $raw, $m)) {
            $recipient['attention'] = trim(preg_replace('/\s+/', ' ', $m[1]));
        }
        // Recipient contact is the first occurrence (before company block)
        if (preg_match_all('/E-mail\s+address:\s*([a-zA-Z0-9@._-]+)/i', $raw, $m) && ! empty($m[1])) {
            $recipient['email'] = trim($m[1][0]);
        }
        if (preg_match_all('/Telephone:\s*(\d{3}-\d{3}-\d{4}[^\s\r\n]*)/', $raw, $m) && ! empty($m[1])) {
            $recipient['telephone'] = trim($m[1][0]);
        }

        $foundOha = false;
        $candidateName = null;
        $candidateStreet = null;
        $candidateCity = null;

        foreach ($lines as $line) {
            if (preg_match('/^OHA\s*$/i', $line)) {
                $foundOha = true;
                continue;
            }
            if ($foundOha && empty($recipient['name'])) {
                if (preg_match('/^\d+\s+[A-Za-z0-9\s.,]+(?:Street|St|Ave|Avenue|Boulevard|Blvd|Way|Drive|Dr)/i', $line)) {
                    $recipient['street_address'] = $line;
                } elseif (preg_match('/^(Portland|Salem|Eugene|Beaverton),[^\d]*(\d{5}(-\d{4})?)/i', $line)) {
                    $recipient['city_state_zip'] = $line;
                } elseif (! str_contains(mb_strtolower($line), 'recipient') && strlen($line) > 3 && ! preg_match('/^\d+$/', $line)) {
                    if (empty($recipient['name']) && empty($recipient['street_address'])) {
                        $candidateName = $line;
                    }
                }
            }
            if (preg_match('/^Recipient\s*$/i', $line) && $candidateName !== null) {
                $recipient['name'] = $candidateName;
                break;
            }
        }

        if (empty($recipient['name']) && $candidateName !== null) {
            $recipient['name'] = $candidateName;
        }

        return array_filter($recipient);
    }
}
