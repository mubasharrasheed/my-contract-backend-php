<?php

namespace App\Services;

use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser;

/**
 * Plain text from contract uploads (PDF, legacy Word .doc, Word .docx) for pattern-based extraction.
 */
class ContractFileTextExtractor
{
    public function extract(string $absolutePath, string $extension): string
    {
        $ext = strtolower(ltrim($extension, '.'));

        return match ($ext) {
            'pdf' => $this->extractPdf($absolutePath),
            'doc', 'docx' => $this->extractWord($absolutePath),
            default => throw new \InvalidArgumentException('Unsupported file type: '.$ext),
        };
    }

    protected function extractPdf(string $path): string
    {
        // 1. Always try Smalot first (original behaviour)
        $parser = new Parser;
        $pdf = $parser->parseFile($path);
        $smalotText = $this->normalizePdfText($pdf->getText() ?? '');

        // 2. Try pdftotext if binary is configured
        $binary = env('PDFTOTEXT_BINARY', '');
        $pdftotextText = null;
        if ($binary !== '' && is_file($binary)) {
            $pdftotextText = $this->tryPdftotext($path);
            if (is_string($pdftotextText) && $pdftotextText !== '') {
                $pdftotextText = $this->normalizePdfText($pdftotextText);
            } else {
                $pdftotextText = null;
            }
        }

        // 3. Decision: if pdftotext is available AND contains address info, use it immediately.
        if ($pdftotextText !== null && $this->containsAddressInformation($pdftotextText)) {
            return $pdftotextText;
        }

        // 4. Otherwise fall back to the original scoring logic
        if ($pdftotextText !== null) {
            $preferAlt = $this->pdfStructureScore($pdftotextText) > $this->pdfStructureScore($smalotText) + 1
                      || $this->pdfTextQuality($pdftotextText) > $this->pdfTextQuality($smalotText) + 0.08;
            if ($preferAlt) {
                return $pdftotextText;
            }
        }

        return $smalotText;
    }

    /**
     * Check if the given text contains typical company/address information.
     * Used to decide whether pdftotext is doing a better job than Smalot.
     */
    protected function containsAddressInformation(string $text): bool
    {
        $patterns = [
            '/\b\d{5}(?:-\d{4})?\b/',                 // ZIP code (5 or 9 digits)
            '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', // email
            '/\b(?:street|st|avenue|ave|road|rd|drive|dr|lane|ln|boulevard|blvd)\b/i',
            '/\b(?:inc|llc|ltd|corp|corporation|co\.?)\b/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Prefer pdftotext when it yields more dollars, dates, and email-shaped tokens (some PDFs look “letter-heavy” but are garbled).
     */
    protected function pdfStructureScore(string $text): int
    {
        $score = 0;
        $score += 8 * (preg_match_all('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $text) ?: 0);
        $score += 4 * (preg_match_all('/\$\s*[\d,]+\.?\d*/', $text) ?: 0);
        $score += 2 * (preg_match_all('/\b(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},?\s+\d{4}\b/i', $text) ?: 0);
        $score += (preg_match_all('/\b(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{4}\b/i', $text) ?: 0);

        return $score;
    }

    /**
     * Strip control characters and normalize whitespace so regex extractors see stable text.
     */
    protected function normalizePdfText(string $text): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
        $text = str_replace("\u{FFFD}", '', $text);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            if (is_string($converted) && $converted !== '') {
                $text = $converted;
            }
        }

        return $text;
    }

    /**
     * Rough signal of how readable extracted Latin text is (0–1). Used to prefer pdftotext when available.
     */
    protected function pdfTextQuality(string $text): float
    {
        $len = strlen($text);
        if ($len === 0) {
            return 0.0;
        }

        $letters = strlen(preg_replace('/[^a-zA-Z]/', '', $text));

        return $letters / $len;
    }

    /**
     * Optional Poppler pdftotext when PDFTOTEXT_BINARY is set (full path to pdftotext executable).
     * Improves some PDFs where embedded fonts break smalot/pdfparser.
     */
    protected function tryPdftotext(string $path): ?string
    {
        $binary = env('PDFTOTEXT_BINARY', '');
        if ($binary === '' || ! is_readable($path)) {
            return null;
        }

        if (! is_file($binary)) {
            return null;
        }

        $escapedBinary = escapeshellarg($binary);
        $escapedPath = escapeshellarg($path);
        $cmd = "{$escapedBinary} -layout -enc UTF-8 {$escapedPath} -";

        $output = shell_exec($cmd);
        if (! is_string($output) || $output === '') {
            return null;
        }

        return $output;
    }

    protected function extractWord(string $path): string
    {
        $phpWord = IOFactory::load($path);
        $buffer = '';

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $buffer .= $this->wordElementToText($element);
            }
        }

        return $buffer;
    }

    /**
     * @param  mixed  $element
     */
    protected function wordElementToText($element): string
    {
        if ($element instanceof Text) {
            return $element->getText();
        }

        if ($element instanceof TextBreak) {
            return "\n";
        }

        if ($element instanceof Table) {
            $out = '';
            foreach ($element->getRows() as $row) {
                foreach ($row->getCells() as $cell) {
                    foreach ($cell->getElements() as $cellElement) {
                        $out .= $this->wordElementToText($cellElement);
                    }
                    $out .= ' ';
                }
                $out .= "\n";
            }

            return $out;
        }

        if (is_object($element) && method_exists($element, 'getElements')) {
            $out = '';
            foreach ($element->getElements() as $child) {
                $out .= $this->wordElementToText($child);
            }

            return $out;
        }

        if (is_object($element) && method_exists($element, 'getText')) {
            try {
                return (string) $element->getText();
            } catch (\Throwable) {
                return '';
            }
        }

        return '';
    }
}
