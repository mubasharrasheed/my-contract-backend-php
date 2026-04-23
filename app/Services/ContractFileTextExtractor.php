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
            default => throw new \InvalidArgumentException('Unsupported file type: ' . $ext),
        };
    }

    protected function extractPdf(string $path): string
    {
        $parser = new Parser();
        $pdf = $parser->parseFile($path);

        return $pdf->getText() ?? '';
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
