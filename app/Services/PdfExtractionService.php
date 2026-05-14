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

        // ----- Oregon Grant specific extraction (overwrites missing fields) -----
        $oregonRecipient = $this->extractOregonGrantRecipient($norm);
        $oregonCompany = $this->extractOregonGrantCompany($norm);
        $isOhcsGrant = $this->isOhcsGrantDocument($norm);

        // Merge: for OHCS grant templates, prefer Oregon extraction strongly.
        if ($isOhcsGrant) {
            $recipient = array_merge($recipient, $oregonRecipient);
            $company = array_merge($company, $oregonCompany);
        } else {
            $recipient = array_merge($recipient, array_filter($oregonRecipient));
            $company = array_merge($company, array_filter($oregonCompany));
        }

        // Fix swapped addresses: if recipient has agency address (Salem) and company has no address, swap
        if (isset($recipient['city_state_zip']) && str_contains($recipient['city_state_zip'], 'Salem')) {
            // Likely swapped: move agency address to company, grantee address to recipient
            if (empty($company['city_state_zip']) && ! empty($recipient['city_state_zip'])) {
                $company['city_state_zip'] = $recipient['city_state_zip'];
                $recipient['city_state_zip'] = null;
            }
            if (empty($company['street_address']) && ! empty($recipient['street_address']) && str_contains($recipient['street_address'], 'Summer')) {
                $company['street_address'] = $recipient['street_address'];
                $recipient['street_address'] = null;
            }
        }

        // Special fix: if recipient name is still empty but we have an email with ulpdx.org, try to extract name from nearby text
        if (empty($recipient['name']) && ! empty($recipient['email']) && str_contains($recipient['email'], 'ulpdx')) {
            // Find the line containing the email and capture a name before it
            if (preg_match('/([A-Z][a-z]+ [A-Z][a-z]+).*?'.preg_quote($recipient['email'], '/').'/s', $norm, $nameMatch)) {
                $recipient['name'] = trim($nameMatch[1]);
            }
        }

        // If company name is too long or contains "Grantee", clean it up
        if (! empty($company['name']) && (strlen($company['name']) > 100 || stripos($company['name'], 'Grantee') !== false)) {
            // Try to extract just the agency name
            if (preg_match('/State of Oregon[^,]*(?:,[^,]+)?/i', $company['name'], $agencyMatch)) {
                $company['name'] = trim($agencyMatch[0]);
            } elseif (preg_match('/Housing and Community Services Department/i', $company['name'], $deptMatch)) {
                $company['name'] = 'State of Oregon, '.$deptMatch[0];
            }
        }

        if (($idFromFilename = $this->extractAgreementNumberFromFilename($originalName)) !== null) {
            $data['agreement_number'] = $idFromFilename;
        }

        $this->fillDatesFromTimeline($norm, $data);
        $this->enrichContactsFromFullText($norm, $company, $recipient);
        $this->applyOhcsGrantFixes($norm, $data, $company, $recipient);
        $this->applyOhaGrantFixes($norm, $data, $company, $recipient);
        $this->applyProsperPortlandFixes($norm, $data, $company, $recipient);
        $this->applyMultnomahCountyContractPoFixes($norm, $data, $company, $recipient);

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
        if (preg_match('/\b(POID\.\d+)\b/i', $originalName, $m)) {
            return $m[1];
        }

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
        $raw = str_replace(["\u{2018}", "\u{2019}"], "'", $raw);
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
        if (preg_match('/Grant\s+Funds[^$]{0,120}\$\s*([\d,]+(?:\.\d{2})?)/i', $text, $m)) {
            return $m[1];
        }

        if (preg_match('/provide\s+Grantee\s+up\s+to\s*\$\s*([\d,]+(?:\.\d{2})?)/i', $text, $m)) {
            return $m[1];
        }

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
        if (preg_match('/effective[^.]{0,120}\bas\s+of\s+([A-Za-z]+\s+\d{1,2},?\s+\d{4})/i', $text, $m)) {
            return trim($m[1]);
        }

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

    // ==================== OREGON GRANT EXTRACTORS (IMPROVED) ====================

    /**
     * Extract recipient details from Oregon/Urban League style grant agreements.
     * Looks for "Grantee's Grant Administrator", address line, etc.
     */
    protected function extractOregonGrantRecipient(string $raw): array
    {
        $recipient = [
            'name' => null,
            'street_address' => null,
            'city_state_zip' => null,
            'telephone' => null,
            'email' => null,
            'attention' => null,
        ];

        // 1. Find "Grantee's Grant Administrator is:" or similar (allow for line breaks)
        // Pattern: Grantee's Grant Administrator is: Julia Delgado
        if (preg_match('/Grantee\'?s\s+Grant\s+Administrator\s*(?:is)?\s*:\s*([A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)/i', $raw, $match)) {
            $recipient['name'] = trim($match[1]);
        } elseif (preg_match('/Grantee\'?s\s+Grant\s+Administrator\s*(?:is)?\s*:\s*([^\n]+)/i', $raw, $match)) {
            // Catch any name (might include extra spaces)
            $candidate = trim($match[1]);
            if (preg_match('/^[A-Z][a-z]+ [A-Z][a-z]+/', $candidate, $nameMatch)) {
                $recipient['name'] = $nameMatch[0];
            }
        }

        // 2. Extract address block: look for number + street name, then city/state/zip
        // The address often appears right after the name, sometimes on same line or next line
        // Use a more flexible approach: find a line containing a number and "Street/St/Ave" etc.
        $lines = explode("\n", $raw);
        $foundNameLine = -1;
        foreach ($lines as $idx => $line) {
            if (! empty($recipient['name']) && str_contains($line, $recipient['name'])) {
                $foundNameLine = $idx;
                break;
            }
        }
        if ($foundNameLine !== -1) {
            // Look at the next 3 lines for address
            for ($i = $foundNameLine; $i <= min($foundNameLine + 3, count($lines) - 1); $i++) {
                $line = $lines[$i];
                // Check for address pattern: number then street keyword
                if (preg_match('/\b(\d{1,5}\s+[A-Za-z0-9\.\s]+(?:Street|St|Avenue|Ave|Road|Rd|Drive|Dr|Lane|Ln|Boulevard|Blvd))/i', $line, $addrMatch)) {
                    $recipient['street_address'] = trim($addrMatch[1]);
                    // The remainder of the line or next line may contain city/state/zip
                    $remaining = preg_replace('/'.preg_quote($recipient['street_address'], '/').'/', '', $line);
                    if (preg_match('/([A-Za-z\s]+,\s*[A-Z]{2}\s*\d{5}(?:-\d{4})?)/', $remaining, $cityMatch)) {
                        $recipient['city_state_zip'] = trim($cityMatch[1]);
                    } else {
                        // Check next line for city/state/zip
                        if ($i + 1 < count($lines) && preg_match('/([A-Za-z\s]+,\s*[A-Z]{2}\s*\d{5}(?:-\d{4})?)/', $lines[$i + 1], $cityMatch2)) {
                            $recipient['city_state_zip'] = trim($cityMatch2[1]);
                        }
                    }
                    break;
                }
            }
        }

        // If street address still not found, use a global regex that captures address line
        if (empty($recipient['street_address'])) {
            if (preg_match('/\b(\d{1,5}\s+[A-Za-z0-9\.\s]+(?:Street|St|Avenue|Ave|Road|Rd|Drive|Dr|Lane|Ln|Boulevard|Blvd))\s*[,.]?\s*([A-Za-z\s]+,\s*[A-Z]{2}\s*\d{5}(?:-\d{4})?)/i', $raw, $match)) {
                $recipient['street_address'] = trim($match[1]);
                $recipient['city_state_zip'] = trim($match[2]);
            }
        }

        // 3. Extract phone number – look for pattern near the grantee block
        if (preg_match('/\b(503[-\s]?890[-\s]?3556)\b/', $raw, $phoneMatch)) {
            $recipient['telephone'] = $phoneMatch[1];
        } elseif (preg_match('/\b(\d{3}[-\s]?\d{3}[-\s]?\d{4})\b/', $raw, $phoneMatch)) {
            $recipient['telephone'] = $phoneMatch[1];
        }

        // 4. Extract email (already may be set, but ensure it's from ulpdx)
        if (preg_match('/[A-Za-z0-9._%+-]+@ulpdx\.org/i', $raw, $emailMatch)) {
            $recipient['email'] = $emailMatch[0];
        }

        return $recipient;
    }

    /**
     * Extract company (grantor) details for Oregon grant – e.g. State of Oregon, agency name.
     */
    protected function extractOregonGrantCompany(string $raw): array
    {
        $company = [
            'name' => null,
            'street_address' => null,
            'city_state_zip' => null,
            'grant_administrator' => null,
            'telephone' => null,
            'email' => null,
        ];

        // 1. Agency name – clean version
        if (preg_match('/State of Oregon acting by and through its ([^\n]+?)(?:\(|and\s|,?\s+each\b)/i', $raw, $match)) {
            $agency = trim($match[1]);
            // Remove any trailing "and" or extra words
            $agency = preg_replace('/\s+and\s+.*$/i', '', $agency);
            $company['name'] = trim('State of Oregon '.$agency);
        } elseif (preg_match('/Agency[:\s]+([^\n]+)/i', $raw, $match)) {
            $company['name'] = trim($match[1]);
        }

        // 2. Agency's Grant Administrator
        if (preg_match('/Agency\'?s\s+Grant\s+Administrator\s*(?:is)?\s*:\s*([A-Z][a-z]+ [A-Z][a-z]+)/i', $raw, $match)) {
            $company['grant_administrator'] = trim($match[1]);
        }

        // 3. Agency address – look for "725 Summer Street" pattern
        if (preg_match('/\b(725\s+Summer\s+Street[^,]*,[^,]*,\s*Salem,\s*OR\s*\d{5})\b/i', $raw, $match)) {
            $fullAddr = trim($match[1]);
            // Split into street and city/state/zip
            if (preg_match('/^(.+?),\s*(.+)$/', $fullAddr, $parts)) {
                $company['street_address'] = trim($parts[1]);
                $company['city_state_zip'] = trim($parts[2]);
            } else {
                $company['city_state_zip'] = $fullAddr;
            }
        } elseif (preg_match('/\b(\d{1,5}\s+[A-Za-z\s]+(?:Street|St|Avenue|Ave|Road|Rd|Drive|Dr|Lane|Ln|Boulevard|Blvd)[,.]?\s+[A-Za-z\s]+,\s*[A-Z]{2}\s*\d{5})/i', $raw, $match)) {
            $fullAddr = trim($match[1]);
            if (preg_match('/^(.+?),\s*(.+)$/', $fullAddr, $parts)) {
                $company['street_address'] = trim($parts[1]);
                $company['city_state_zip'] = trim($parts[2]);
            }
        }

        // 4. Agency email – look for @hcs.oregon.gov
        if (preg_match('/[A-Za-z0-9._%+-]+@hcs\.oregon\.gov/i', $raw, $emailMatch)) {
            $company['email'] = $emailMatch[0];
        }

        // 5. Agency telephone
        if (preg_match('/\b(503[-\s]?881[-\s]?4792)\b/', $raw, $phoneMatch)) {
            $company['telephone'] = $phoneMatch[1];
        }

        return $company;
    }

    protected function isOhcsGrantDocument(string $raw): bool
    {
        $lower = mb_strtolower($raw);

        return str_contains($lower, 'ohcs')
            || str_contains($lower, 'housing and community services department')
            || str_contains($lower, 'ore-dap')
            || str_contains($lower, 'grant no. 9263');
    }

    /**
     * Apply strict corrections for OHCS grant templates that include both Agency and Grantee administrator blocks.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $company
     * @param  array<string, mixed>  $recipient
     */
    protected function applyOhcsGrantFixes(string $raw, array &$data, array &$company, array &$recipient): void
    {
        if (! $this->isOhcsGrantDocument($raw)) {
            return;
        }

        if (preg_match('/Grant\s+No\.?\s*([A-Z0-9\-]+)/i', $raw, $m)) {
            $data['agreement_number'] = trim($m[1]);
        }

        if (preg_match('/provide\s+Grantee\s+up\s+to\s*\$\s*([\d,]+(?:\.\d{2})?)/i', $raw, $m)) {
            $data['grant_amount'] = trim($m[1]);
        }

        if (preg_match('/effective[^.]{0,120}\bas\s+of\s+([A-Za-z]+\s+\d{1,2},?\s+\d{4})/i', $raw, $m)) {
            $data['effective_date'] = trim($m[1]);
        }

        if (preg_match('/will\s+expire\s+on\s+([A-Za-z]+\s+\d{1,2},?\s+\d{4})/i', $raw, $m)) {
            $data['expiry_date'] = trim($m[1]);
        }

        if (preg_match('/and\s+The Urban League of Portland, Inc\./i', $raw)) {
            $recipient['name'] = 'The Urban League of Portland, Inc.';
        }

        if (preg_match('/4\.2\s+Grantee\'?s\s+Grant\s+Administrator\s+is:\s*([A-Za-z ]+)/i', $raw, $m)) {
            $recipient['attention'] = trim($m[1]);
        }

        if (preg_match('/4\.2\s+Grantee\'?s\s+Grant\s+Administrator\s+is:\s*[^\n]*\n\s*([0-9][^\n]+)/i', $raw, $m)) {
            if (preg_match('/^(.+?),\s*([A-Za-z ].*?\d{5}(?:-\d{4})?)$/', trim($m[1]), $parts)) {
                $recipient['street_address'] = trim($parts[1]);
                $recipient['city_state_zip'] = trim($parts[2]);
            } else {
                $recipient['street_address'] = trim($m[1]);
            }
        }

        if (preg_match('/\b(503[-\s]?890[-\s]?3556)\b/', $raw, $m)) {
            $recipient['telephone'] = trim($m[1]);
        }
        if (preg_match('/[A-Za-z0-9._%+-]+@ulpdx\.org/i', $raw, $m)) {
            $recipient['email'] = trim($m[0]);
        }

        if (preg_match('/State of Oregon acting by and through its Housing and\s+Community Services Department/i', $raw)) {
            $company['name'] = 'State of Oregon Housing and Community Services Department';
        }
        if (preg_match('/4\.1\s+Agency\'?s\s+Grant\s+Administrator\s+is:\s*([A-Za-z ]+)/i', $raw, $m)) {
            $company['grant_administrator'] = trim($m[1]);
        }
        if (preg_match('/\b725\s+Summer\s+Street\s+NE\b/i', $raw, $m)) {
            $company['street_address'] = trim($m[0]);
        }
        if (preg_match('/\bSuite\s+B,\s+Salem,\s+OR\s+97301\b/i', $raw, $m)) {
            $company['city_state_zip'] = trim($m[0]);
        }
        if (preg_match('/\b(503[-\s]?881[-\s]?4792)\b/', $raw, $m)) {
            $company['telephone'] = trim($m[1]);
        }
        if (preg_match('/[A-Za-z0-9._%+-]+@hcs\.oregon\.gov/i', $raw, $m)) {
            $company['email'] = trim($m[0]);
        }
    }

    protected function isProsperPortlandDocument(string $raw): bool
    {
        $lower = mb_strtolower($raw);

        return str_contains($lower, 'prosper portland')
            || str_contains($lower, 'community opportunities and enhancements program')
            || str_contains($lower, 'third amendment to grant agreement');
    }

    /**
     * Apply strict corrections for Prosper Portland amendment templates.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $company
     * @param  array<string, mixed>  $recipient
     */
    protected function applyProsperPortlandFixes(string $raw, array &$data, array &$company, array &$recipient): void
    {
        if (! $this->isProsperPortlandDocument($raw)) {
            return;
        }

        if (preg_match('/Grant\s+No\.?\s*([A-Z0-9\-]+)/i', $raw, $m)) {
            $data['agreement_number'] = trim($m[1]);
        }
        if (preg_match('/Grant\s+to\s+[A-Z\s\-]+\(\s*\$\s*([\d,]+(?:\.\d{2})?)\s*\)/i', $raw, $m)) {
            $data['grant_amount'] = trim($m[1]);
        }
        if (preg_match('/Grant\s+Agreement\s+dated\s+([A-Za-z]+\s+\d{1,2},?\s+\d{4})/i', $raw, $m)) {
            $data['effective_date'] = preg_replace('/\s+/', ' ', trim($m[1]));
        }
        if (preg_match('/termination[^.]{0,200}\bchanged\s+to\s+([A-Za-z]+\s+\d{1,2},?\s+\d{4})/i', $raw, $m)) {
            $data['expiry_date'] = preg_replace('/\s+/', ' ', trim($m[1]));
        }

        if (preg_match('/and\s+The Urban League of Portland, Inc\./i', $raw)) {
            $recipient['name'] = 'The Urban League of Portland, Inc.';
        }
        if (preg_match('/\bJulia\s+D[ae]lgado\b/i', $raw, $m)) {
            $recipient['attention'] = trim(str_ireplace('Dlgado', 'Delgado', $m[0]));
        }

        $company['name'] = 'Prosper Portland';

        if (preg_match('/theresa\.green@portlandoregon\.gov/i', $raw, $m)) {
            $company['email'] = trim($m[0]);
        }

        if (preg_match('/theresa\.green@portlandoregon\.gov/i', $raw)) {
            $company['grant_administrator'] = 'Theresa Green';
        }
    }

    protected function isOhaGrantDocument(string $raw): bool
    {
        $lower = mb_strtolower($raw);

        return str_contains($lower, 'oregon health authority')
            || str_contains($lower, 'state of oregon')
            || str_contains($lower, 'grant agreement number po-44300-');
    }

    /**
     * Apply strict corrections for OHA grant templates.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $company
     * @param  array<string, mixed>  $recipient
     */
    protected function applyOhaGrantFixes(string $raw, array &$data, array &$company, array &$recipient): void
    {
        if (! $this->isOhaGrantDocument($raw)) {
            return;
        }

        $company['name'] = 'Oregon Health Authority';

        if (preg_match('/\bExternal\s+Relations\s+Division\b/i', $raw, $m)) {
            $company['division'] = trim($m[0]);
        }
        if (preg_match('/\bOffice\s+of\s+Community\s+Health\s+and\s+Engagement\b/i', $raw, $m)) {
            $company['office'] = trim($m[0]);
        }

        if (preg_match('/\b500\s+Summer\s+Street\s+NE,\s*E-03\b/i', $raw, $m)) {
            $company['street_address'] = trim($m[0]);
        } elseif (preg_match('/\b500\s+Summer\s+Street\s+NE\b/i', $raw, $m)) {
            $company['street_address'] = trim($m[0]);
        }

        if (preg_match('/\bSalem,\s*Oregon\s*97301\b/i', $raw, $m)) {
            $company['city_state_zip'] = preg_replace('/\s+/', ' ', trim($m[0]));
        }
        if (preg_match('/Grant\s+Administrator:\s*([^\n\r]+?)(?=\s*Telephone|$)/i', $raw, $m)) {
            $company['grant_administrator'] = trim(preg_replace('/\s+/', ' ', $m[1]));
        }
        if (preg_match('/\b(503[-\s]?891[-\s]?3367)\b/', $raw, $m)) {
            $company['telephone'] = trim($m[1]);
        }
        if (preg_match('/[A-Za-z0-9._%+-]+@oha\.oregon\.gov/i', $raw, $m)) {
            $company['email'] = trim($m[0]);
        }
    }

    protected function isMultnomahCountyContractPo(string $raw): bool
    {
        $lower = mb_strtolower($raw);

        if (! str_contains($lower, 'multnomah county')) {
            return false;
        }

        return str_contains($lower, 'contract purchase order')
            || str_contains($lower, 'change order poid.');
    }

    /**
     * Multnomah County Contract Purchase Order: supplier = grantee, ship-to = county / program contact.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $company
     * @param  array<string, mixed>  $recipient
     */
    protected function applyMultnomahCountyContractPoFixes(string $raw, array &$data, array &$company, array &$recipient): void
    {
        if (! $this->isMultnomahCountyContractPo($raw)) {
            return;
        }

        if (preg_match('/Change Order\s+(POID\.\d+)/i', $raw, $m)) {
            $data['agreement_number'] = $m[1];
        }

        if (preg_match('/Supplier Address\s*\R\s*([^\r\n]+?)\s*\R\s*([^\r\n]+?)\s*\R\s*([^\r\n]+)/i', $raw, $m)) {
            $recipient['name'] = trim(preg_replace('/\s+/', ' ', $m[1]));
            $recipient['street_address'] = trim(preg_replace('/\s+/', ' ', $m[2]));
            $recipient['city_state_zip'] = trim(preg_replace('/\s+/', ' ', $m[3]));
        }

        if (preg_match('/Ship To:\s*\R\s*([^\r\n]+?)\s*\R\s*([^\r\n]+?)\s*\R\s*([^\r\n]+)/i', $raw, $m)) {
            $company['grant_administrator'] = trim(preg_replace('/\s+/', ' ', $m[1]));
            $company['street_address'] = trim(preg_replace('/\s+/', ' ', $m[2]));
            $company['city_state_zip'] = trim(preg_replace('/\s+/', ' ', $m[3]));
        }

        $company['name'] = 'Multnomah County Oregon';

        if (preg_match('/Buyer\/Phone\s*([^\r\n]+)\s*\R\s*([^\r\n]+)/i', $raw, $m)) {
            $division = trim(preg_replace('/\s+/', ' ', $m[1].' '.$m[2]));
            if ($division !== '') {
                $company['division'] = $division;
            }
        }

        if (preg_match('/\(\s*503\s*\)\s*988\s*8239\b/i', $raw) || preg_match('/\b503[\s\-]*988[\s\-]*8239\b/', $raw)) {
            $company['telephone'] = '503-988-8239';
        }

        if (preg_match('/[A-Za-z0-9._%+-]+@multco\.us\b/i', $raw, $m)) {
            $company['email'] = strtolower(trim($m[0]));
            $recipient['email'] = null;
        }

        if (preg_match('/Validity Dates:\s*([A-Za-z]+\s+\d{1,2},\s*\d{4})\s*[-–]\s*([A-Za-z]+\s+\d{1,2},\s*\d{4})/i', $raw, $m)) {
            $data['effective_date'] = trim($m[1]);
            $data['expiry_date'] = trim($m[2]);
        } else {
            if (empty($data['effective_date']) && preg_match('/\bDate\s+(\d{1,2}\/\d{1,2}\/\d{4})\b/', $raw, $m)) {
                $data['effective_date'] = trim($m[1]);
            }
            if (empty($data['expiry_date']) && preg_match('/Due Date\s+(\d{1,2}\/\d{1,2}\/\d{4})\b/i', $raw, $m)) {
                $data['expiry_date'] = trim($m[1]);
            }
        }

        if (preg_match('/Change Order\s+POID\.[^\r\n]+\s*\R\s*Date\s+(\d{1,2}\/\d{1,2}\/\d{4})/i', $raw, $m)) {
            $data['template_date'] = trim($m[1]);
        }

        if (preg_match('/Total\s*\$\s*([\d,]+(?:\.\d{2})?)/i', $raw, $m)) {
            $data['grant_amount'] = trim($m[1]);
        }
    }
}
