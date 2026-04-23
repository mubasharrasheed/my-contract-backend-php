<?php

namespace App\Services;

/**
 * Heuristic extraction from grant/contract plain text (PDF or Word).
 * Tries OHA-style patterns first, then generic grant/agreement wording so other templates still populate key fields.
 */
class PdfExtractionService
{
    public function extractFromRawText(string $rawText): array
    {
        $rawText = $this->normalizeRawText($rawText);
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
     *
     * @param  string  $originalName  Base filename (without extension), e.g. from pathinfo(), used to recover agreement IDs like 25RH8483 when PDF text is unreliable.
     */
    public function extractForContract(string $rawText, string $originalName): array
    {
        $norm = $this->normalizeRawText($rawText);
        $data = $this->extractFromRawText($norm);
        $company = $data['company'] ?? [];
        $recipient = $data['recipient'] ?? [];

        if (($idFromFilename = $this->extractAgreementNumberFromFilename($originalName)) !== null) {
            $data['agreement_number'] = $idFromFilename;
        }

        $this->fillDatesFromTimeline($norm, $data);
        $this->enrichContactsFromFullText($norm, $company, $recipient);

        return array_filter([
            'name' => $originalName ?? null,
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

    /**
     * e.g. "25RH8483 RHEF Pilot..." → 25RH8483 (PDF parsers often mis-read a digit in embedded-font IDs).
     */
    protected function extractAgreementNumberFromFilename(string $originalName): ?string
    {
        if (preg_match('/\b(\d{2}[Rr][Hh]\d{4,})\b/', $originalName, $m)) {
            return strtoupper($m[1]);
        }

        return null;
    }

    /**
     * @return array{effective: string, expiry: string}|null
     */
    protected function extractTimelineSlice(string $raw): ?array
    {
        $i = stripos($raw, 'Timeline');
        if ($i === false) {
            return null;
        }
        $chunk = substr($raw, $i, 160);
        if (! preg_match('/Timeline\s*:\s*([A-Za-z]+)\s+(\d{4})\s*\p{Pd}\s*([A-Za-z]+)\s+(\d{4})/u', $chunk, $m)) {
            return null;
        }

        return [
            'effective' => trim($m[1].' '.$m[2]),
            'expiry' => trim($m[3].' '.$m[4]),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function fillDatesFromTimeline(string $raw, array &$data): void
    {
        if (! empty($data['effective_date']) && ! empty($data['expiry_date'])) {
            return;
        }

        $timeline = $this->extractTimelineSlice($raw);
        if ($timeline !== null) {
            if (empty($data['effective_date'])) {
                $data['effective_date'] = $timeline['effective'];
            }
            if (empty($data['expiry_date'])) {
                $data['expiry_date'] = $timeline['expiry'];
            }
        }
    }

    /**
     * Fill phones and emails from the full document when preamble-only extraction missed them (common on amendments / damaged text layers).
     *
     * @param  array<string, mixed>  $company
     * @param  array<string, mixed>  $recipient
     */
    protected function enrichContactsFromFullText(string $norm, array &$company, array &$recipient): void
    {
        if (empty($company['name']) && str_contains(mb_strtolower($norm), 'reproductive health equity fund')) {
            $company['name'] = 'Reproductive Health Equity Fund of Oregon';
        }

        if (empty($recipient['name']) && preg_match('/Organization\s*:\s*([^\n\r]+)/iu', $norm, $m)) {
            $recipient['name'] = trim(preg_replace('/\s+/', ' ', $m[1]));
        }

        $all = $this->collectEmailsFromString($norm);
        if (empty($recipient['email'])) {
            foreach ($all as $em) {
                if (stripos($em, 'ulpdx') !== false) {
                    $recipient['email'] = $em;
                    break;
                }
            }
        }

        foreach ($all as $em) {
            if (strcasecmp($em, 'rhefgrants@seedingjustice.org') === 0) {
                $company['email'] = $em;
                break;
            }
        }

        if (empty($company['email']) && $all !== []) {
            foreach (['contracts@', '@portlandoregon.gov', '@oha.oregon.gov'] as $needle) {
                foreach ($all as $em) {
                    if (stripos($em, $needle) !== false) {
                        $company['email'] = $em;
                        break 2;
                    }
                }
            }
        }
        if (empty($company['email']) && $all !== []) {
            foreach ($all as $em) {
                if (stripos($em, '@seedingjustice.org') !== false && stripos($em, 'violeta@') === false) {
                    $company['email'] = $em;
                    break;
                }
            }
        }
        if (empty($company['email']) && $all !== []) {
            $company['email'] = count($all) === 1 ? $all[0] : $all[array_key_last($all)];
        }

        $phones = $this->extractNormalizedPhoneNumbers($norm);
        if (empty($company['telephone']) && isset($phones[0])) {
            $company['telephone'] = $phones[0];
        }
        if (empty($recipient['telephone']) && isset($phones[1])) {
            $recipient['telephone'] = $phones[1];
        }

        $rName = mb_strtolower($recipient['name'] ?? '');
        $rMail = mb_strtolower($recipient['email'] ?? '');
        if ($rMail !== '' && str_contains($rMail, '@seedingjustice.org')
            && (str_contains($rName, 'urban league') || str_contains($rName, 'nonprofit') || str_contains($rName, 'inc.'))) {
            $recipient['email'] = null;
        }
    }

    /**
     * @return list<string>
     */
    protected function extractNormalizedPhoneNumbers(string $raw): array
    {
        $out = [];
        if (preg_match_all('/\b(?:\(\s*\d{3}\s*\)|\d{3})[\s\-]{0,4}\d{3}[\s\-]{0,4}\d{4}\b/', $raw, $ph)) {
            foreach ($ph[0] as $p) {
                $digits = preg_replace('/\D/', '', $p);
                if (strlen($digits) >= 10) {
                    $out[] = substr($digits, 0, 3).'-'.substr($digits, 3, 3).'-'.substr($digits, 6, 4);
                }
            }
        }

        return array_values(array_unique($out));
    }

    protected function normalizeRawText(string $raw): string
    {
        $raw = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $raw);
        $raw = str_replace(["\u{201C}", "\u{201D}"], '"', $raw);
        $raw = str_replace(['¡', '·', "\u{00A0}", "\u{200B}"], ' ', $raw);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $raw);
            if (is_string($converted) && $converted !== '') {
                $raw = $converted;
            }
        }

        return $raw;
    }

    protected function getLines(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw);

        return array_map('trim', array_filter($lines, static fn ($l) => $l !== ''));
    }

    /**
     * @return array{grantor: ?string, grantee: ?string}
     */
    protected function extractPartiesFromBetweenClause(string $raw): array
    {
        $out = ['grantor' => null, 'grantee' => null];

        // Prefer "between … ("Short") and … , and is effective" so internal " and " (e.g. "development and urban") does not break captures.
        if (preg_match('/\bis between\s+(.+?)\(\s*"([^"]+)"\s*\)\s+and\s+(.+?),\s+and is effective/is', $raw, $m)) {
            $out['grantor'] = $this->partyNameFromBetweenGroups($m[1], $m[2]);
            $out['grantee'] = $this->shortenPartyDescription(trim($m[3]));

            return $out;
        }

        return $out;
    }

    protected function partyNameFromBetweenGroups(string $longForm, string $quotedShort): string
    {
        $short = trim($quotedShort);
        $lower = mb_strtolower($short);
        if ($short !== '' && ! in_array($lower, ['grantee', 'grantor', 'recipient', 'contractor', 'subrecipient'], true)) {
            return $short;
        }

        return $this->shortenPartyDescription($longForm);
    }

    protected function shortenPartyDescription(string $chunk): string
    {
        $chunk = trim(preg_replace('/\s+/', ' ', $chunk));
        if ($chunk === '') {
            return '';
        }

        if (preg_match('/\(\s*"([^"]{2,120})"\s*\)/', $chunk, $q)) {
            $label = trim($q[1]);
            $lower = mb_strtolower($label);
            if (! in_array($lower, ['grantee', 'grantor', 'recipient', 'contractor', 'subrecipient'], true)) {
                return $label;
            }
        }

        if (preg_match('/^(.+?),\s+an\s+[A-Za-z]/u', $chunk, $m)) {
            return trim($m[1]);
        }

        if (preg_match('/^(.+?),\s+a\s+[A-Za-z]/u', $chunk, $m)) {
            return trim($m[1]);
        }

        if (preg_match('/^([^,]{3,120})/u', $chunk, $m)) {
            return trim($m[1]);
        }

        return $chunk;
    }

    protected function extractOrganizationLine(string $raw): ?string
    {
        if (preg_match('/Organization:\s*([^\n\r]+)/iu', $raw, $m)) {
            return trim(preg_replace('/\s+/', ' ', $m[1]));
        }

        return null;
    }

    /**
     * @return list<string>
     */
    protected function collectEmailsFromString(string $text): array
    {
        $emails = [];
        if (preg_match_all('/[A-Za-z0-9._%+-]{1,64}@[A-Za-z0-9.-]+\.[A-Za-z]{2,24}/', $text, $matches)) {
            foreach ($matches[0] as $t) {
                $t = preg_replace('/docusign.*$/i', '', $t);
                $t = preg_replace('/\benvelope\b.*$/i', '', $t);
                $t = rtrim($t, '.,;:');
                if (preg_match('/rhefgrants@seedingjustice\.org$/i', $t)) {
                    $emails[] = 'rhefgrants@seedingjustice.org';

                    continue;
                }
                if (preg_match('/violeta@seedingjustice\.org$/i', $t)) {
                    $emails[] = 'violeta@seedingjustice.org';

                    continue;
                }
                if (preg_match('/^[A-Za-z0-9._%+-]{1,64}@[A-Za-z0-9.-]+\.[A-Za-z]{2,24}$/', $t)) {
                    [$local] = explode('@', $t, 2);
                    if (preg_match('/\d{8,}/', $local)) {
                        continue;
                    }
                    $emails[] = $t;
                }
            }
        }

        return array_values(array_unique($emails));
    }

    /**
     * Contact emails usually appear in the first pages; skip later operational addresses (e.g. contract manager deep in exhibits).
     *
     * @return list<string>
     */
    protected function collectPreambleEmails(string $raw): array
    {
        $end = strlen($raw);
        foreach (['signatures follow', 'signature page'] as $needle) {
            $p = stripos($raw, $needle);
            if ($p !== false && $p > 400) {
                $end = min($end, $p);
            }
        }
        $end = min($end, 14000);

        return $this->collectEmailsFromString(substr($raw, 0, $end));
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

        if (preg_match('/Grant\s+No\.?\s*([A-Z0-9\-]+)/i', $text, $m)) {
            return $m[1];
        }

        if (preg_match('/(?:Agreement|Contract)\s+No\.?\s*[:#]?\s*([A-Z0-9][A-Z0-9\-]*)/i', $text, $m)) {
            return $m[1];
        }

        if (preg_match('/\b(?:PO|CO|RG|RH|RHEF)[-–]?\d{2,}[-–]?\d{2,}[-–]?[A-Z0-9]+\b/i', $text, $m)) {
            return str_replace('–', '-', $m[0]);
        }

        if (preg_match('/\b\d{2}RH\d{4,}\b/i', $text, $m)) {
            return $m[0];
        }

        return null;
    }

    protected function extractGrantAmount(string $text): ?string
    {
        if (preg_match('/maximum\s+not-to-exceed\s+amount[^$]*?\$\s*([\d,]+\.?\d*)/i', $text, $m)) {
            return $m[1];
        }

        if (preg_match('/\b(?:amount|grant)\s+of\s+[^$]{0,80}?\$\s*([\d,]+\.?\d*)/i', $text, $m)) {
            return $m[1];
        }

        if (preg_match('/DOLLARS?\s*\(\s*\$\s*([\d,]+\.?\d*)\s*\)/i', $text, $m)) {
            return $m[1];
        }

        if (preg_match_all('/\(\s*\$\s*([\d,]+\.?\d*)\s*\)/', $text, $all)) {
            $best = null;
            $bestVal = 0.0;
            foreach ($all[1] as $rawNum) {
                $n = (float) str_replace(',', '', $rawNum);
                if ($n >= $bestVal && $n >= 1000) {
                    $bestVal = $n;
                    $best = $rawNum;
                }
            }
            if ($best !== null) {
                return $best;
            }
        }

        if (preg_match('/\$\s*([\d,]{2,}(?:,\d{3})*(?:\.\d+)?)\b/', $text, $m)) {
            return $m[1];
        }

        if (preg_match('/\bis\s+\$\s*([\d,]+\.?\d*)/i', $text, $m)) {
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

        if (preg_match('/Grant\s+Agreement\s+dated\s+([A-Za-z]+\s+\d{1,2},?\s+\d{4})/i', $text, $m)) {
            return trim($m[1]);
        }

        if (preg_match('/\b(?:First|Second|Third)\s+Amendment\s+to\s+Grant\s+Agreement\s+dated\s+([A-Za-z]+\s+\d{1,2},?\s+\d{4})/i', $text, $m)) {
            return trim($m[1]);
        }

        if (preg_match('/\b(?:effective|commenc(?:e|ing))\s+(?:as\s+of\s+)?([A-Za-z]+\s+\d{1,2},?\s+\d{4})/i', $text, $m)) {
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

        if (preg_match('/termination[^.]{0,160}changed\s+to\s+([A-Za-z]+\s+\d{1,2},?\s+\d{4})/i', $text, $m)) {
            return trim($m[1]);
        }

        if (preg_match('/(?:will\s+)?expire\s+(?:on\s+)?([A-Za-z]+\s+\d{1,2},?\s+\d{4})/i', $text, $m)) {
            return trim($m[1]);
        }

        if (preg_match('/until\s+([A-Za-z]+\s+\d{1,2},?\s+\d{4})/i', $text, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    protected function extractTemplateDate(string $text): ?string
    {
        if (preg_match('/Updated:\s*(\d{1,2}\/\d{1,2}\/\d{4})/i', $text, $m)) {
            return $m[1];
        }

        if (preg_match('/(?:Revised|Last\s+updated)\s*:?\s*(\d{1,2}\/\d{1,2}\/\d{4})/i', $text, $m)) {
            return $m[1];
        }

        return null;
    }

    protected function extractAssistanceListings(string $text): array
    {
        if (preg_match('/Assistance\s+Listings\s+number\(s\)[^:]*:\s*([\d.\s and,]+)/i', $text, $m)) {
            $nums = preg_replace('/\s+and\s+/i', ' ', $m[1]);
            preg_match_all('/\d+\.\d+/', $nums, $out);

            return $out[0] ?? [];
        }

        return [];
    }

    /**
     * Grantor / agency block: OHA-specific heuristics, then generic "between" preamble and labels.
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

        $emails = $this->collectPreambleEmails($raw);
        if (count($emails) >= 2) {
            $company['email'] = $emails[1];
        }

        if (preg_match_all('/Telephone:\s*([\d\s().+x\-]{10,40})/i', $raw, $m)) {
            $company['telephone'] = count($m[1]) >= 2 ? trim($m[1][1]) : trim($m[1][0] ?? '');
        }

        foreach ($lines as $line) {
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

        $parties = $this->extractPartiesFromBetweenClause($raw);
        if (! empty($parties['grantor']) && empty($company['name'])) {
            $company['name'] = $parties['grantor'];
        }

        return array_filter($company);
    }

    /**
     * Grantee / recipient block: OHA layout first, then generic party and label lines.
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

        $emails = $this->collectPreambleEmails($raw);
        if ($emails !== []) {
            $recipient['email'] = $emails[0];
        }

        if (preg_match_all('/Telephone:\s*([\d\s().+x\-]{10,40})/i', $raw, $m) && ! empty($m[1])) {
            $recipient['telephone'] = trim($m[1][0]);
        }

        $candidateName = null;
        $foundOha = false;

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

        $parties = $this->extractPartiesFromBetweenClause($raw);
        if (! empty($parties['grantee'])) {
            $recipient['name'] = $recipient['name'] ?? $parties['grantee'];
        }

        $org = $this->extractOrganizationLine($raw);
        if ($org !== null && empty($recipient['name'])) {
            $recipient['name'] = $org;
        }

        return array_filter($recipient);
    }
}
