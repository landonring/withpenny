<?php

namespace App\Services\Statements;

class PdfTextExtractor
{
    /**
     * @return array{text:string,method:string,ocr_used:bool}
     */
    public function extract(string $path): array
    {
        $text = $this->extractViaSpatie($path);
        $method = 'spatie_pdf_to_text';

        if (trim($text) === '') {
            $text = $this->extractViaPdftotext($path);
            $method = trim($text) === '' ? 'unavailable' : 'pdftotext';
        }

        $ocrUsed = false;
        if (mb_strlen(trim($text)) < 180) {
            $ocrText = $this->extractViaOcr($path);
            if (trim($ocrText) !== '') {
                $text = trim($text) === ''
                    ? trim($ocrText)
                    : trim($text."\n".$ocrText);
                $method = $method === 'unavailable' ? 'tesseract_ocr' : $method.'+tesseract_ocr';
                $ocrUsed = true;
            }
        }

        return [
            'text' => trim($text),
            'method' => $method,
            'ocr_used' => $ocrUsed,
        ];
    }

    private function extractViaSpatie(string $path): string
    {
        if (! class_exists(\Spatie\PdfToText\Pdf::class)) {
            return '';
        }

        try {
            return (string) \Spatie\PdfToText\Pdf::getText($path);
        } catch (\Throwable) {
            return '';
        }
    }

    private function extractViaPdftotext(string $path): string
    {
        $binary = $this->findBinary('pdftotext');
        if ($binary === null) {
            return '';
        }

        $command = escapeshellarg($binary).' '.escapeshellarg($path).' - 2>/dev/null';

        return (string) shell_exec($command);
    }

    private function extractViaOcr(string $path): string
    {
        $pdftoppm = $this->findBinary('pdftoppm');
        $tesseract = $this->findBinary('tesseract');
        if ($pdftoppm === null || $tesseract === null) {
            return '';
        }

        $tempDir = storage_path('app/private/tmp/statement-ocr-'.uniqid('', true));
        if (! @mkdir($tempDir, 0775, true) && ! is_dir($tempDir)) {
            return '';
        }

        $prefix = $tempDir.'/page';
        $renderCommand = escapeshellarg($pdftoppm).' -png '.escapeshellarg($path).' '.escapeshellarg($prefix).' 2>/dev/null';
        shell_exec($renderCommand);

        $images = glob($prefix.'-*.png') ?: [];
        natsort($images);

        $chunks = [];
        foreach ($images as $image) {
            $ocrCommand = escapeshellarg($tesseract).' '.escapeshellarg($image).' stdout 2>/dev/null';
            $chunk = trim((string) shell_exec($ocrCommand));
            if ($chunk !== '') {
                $chunks[] = $chunk;
            }
        }

        foreach ($images as $image) {
            @unlink($image);
        }
        @rmdir($tempDir);

        return implode("\n", $chunks);
    }

    private function findBinary(string $name): ?string
    {
        $path = trim((string) shell_exec('command -v '.escapeshellarg($name).' 2>/dev/null'));

        return $path !== '' ? $path : null;
    }
}
