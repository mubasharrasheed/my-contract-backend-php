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
        $parser = new Parser;
        $pdf = $parser->parseFile($path);
        $text = $this->normalizePdfText($pdf->getText() ?? '');

        $binary = env('PDFTOTEXT_BINARY', '');
        $alt = ($binary !== '' && is_file($binary)) ? $this->tryPdftotext($path) : null;
        $alt = is_string($alt) && $alt !== '' ? $this->normalizePdfText($alt) : null;

        if ($alt !== null) {
            $preferAlt = $this->pdfStructureScore($alt) > $this->pdfStructureScore($text) + 1
                || $this->pdfTextQuality($alt) > $this->pdfTextQuality($text) + 0.08;

            if ($preferAlt) {
                $text = $alt;
            }
        }

        return $text;
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
