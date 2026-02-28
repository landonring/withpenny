<?php

namespace App\Services\Statements;

use Aws\Textract\TextractClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PdfStatementParser
{
    private const BALANCE_TOLERANCE = 0.75;

    /**
     * Deterministic, staged parser entrypoint for statement PDFs.
     *
     * @return array{
     *   transactions: array<int, array<string, mixed>>,
     *   summary: array<string, mixed>,
     *   extraction_method: string,
     *   extraction_confidence: string,
     *   balance_mismatch: bool,
     *   debug?: array<string, mixed>
     * }
     */
    public function parseDocument(string $path, bool $debug = false): array
    {
        $hasEmbeddedText = $this->hasEmbeddedText($path);
        $method = $hasEmbeddedText ? 'pdf_text' : 'textract';
        $lines = $hasEmbeddedText
            ? $this->extractDigitalLines($path)
            : $this->extractTextractLines($path);

        $parsed = $this->runPipeline($lines, $method, $debug);

        Log::info('statement_parser_result', [
            'method' => $method,
            'total_lines_extracted' => $parsed['stats']['total_lines_extracted'] ?? count($lines),
            'candidate_rows_detected' => $parsed['stats']['candidate_rows_detected'] ?? 0,
            'rows_merged' => $parsed['stats']['rows_merged'] ?? 0,
            'rows_rejected' => $parsed['stats']['rows_rejected'] ?? 0,
            'rows_recovered' => $parsed['stats']['rows_recovered'] ?? 0,
            'balance_mismatch' => $parsed['balance_mismatch'],
            'extraction_confidence' => $parsed['extraction_confidence'],
            'validation' => $parsed['summary']['validation'] ?? null,
            'debug_enabled' => $debug,
        ]);

        return $parsed;
    }

    /**
     * Kept for compatibility. Uses deterministic parser on synthetic line data.
     */
    public function parseText(string $text): array
    {
        $text = str_replace(["\u{00A0}", "\u{200B}", "\u{200C}", "\u{200D}", "\u{FEFF}"], ' ', $text);
        $text = str_replace(['€', '£'], '$', $text);
        $text = str_replace(['−', '–', '—'], '-', $text);
        $text = preg_replace('/[\\x00-\\x09\\x0B\\x0C\\x0E-\\x1F\\x7F]/u', ' ', $text) ?? $text;
        if ($text === '') {
            return [];
        }

        $rawLines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $rawLines = array_values(array_filter(array_map(fn ($line) => $this->normalizeLineText((string) $line), $rawLines), fn ($line) => $line !== ''));

        if (empty($rawLines)) {
            return [];
        }

        $statementYear = StatementParser::extractStatementYear($text);
        $chunks = [];
        $current = '';

        foreach ($rawLines as $line) {
            if ($this->isLikelyDateStart($line)) {
                if ($current !== '') {
                    $chunks[] = trim($current);
                }
                $current = $line;
                continue;
            }

            if ($current !== '') {
                $current .= ' '.$line;
            }
        }

        if ($current !== '') {
            $chunks[] = trim($current);
        }

        if (empty($chunks)) {
            $chunks = $rawLines;
        }

        $rows = [];
        $seen = [];
        foreach ($chunks as $chunk) {
            $normalized = strtolower($chunk);

            if ($this->isBalanceRowText($normalized) || $this->isSummaryLikeLine($normalized) || $this->isHeaderLine($normalized)) {
                continue;
            }

            $dateToken = StatementParser::extractDateToken($chunk, StatementParser::monthPattern());
            if (! $dateToken) {
                continue;
            }

            $date = StatementParser::parseDate($dateToken, $statementYear);
            if (! $date) {
                continue;
            }

            $amounts = StatementParser::extractAmounts($chunk);
            if (empty($amounts)) {
                continue;
            }

            $signedPreferred = null;
            if (preg_match_all('/[+-]\s*\$?\d[\d,]*(?:[.,]\d{2})/', $chunk, $signedMatches) && ! empty($signedMatches[0])) {
                $signedPreferred = trim((string) $signedMatches[0][0]);
            }

            $amountRaw = $signedPreferred ?: StatementParser::pickLikelyAmount($amounts);
            if (! $amountRaw) {
                continue;
            }

            $signedAmount = StatementParser::parseAmount($amountRaw);
            $amount = abs($signedAmount);
            if ($amount <= 0) {
                continue;
            }

            $description = StatementParser::extractDescription($chunk, $dateToken, $amounts);
            $description = preg_replace('/\b(debit|credit|dr|cr)\b/i', '', $description) ?? $description;
            $description = $this->cleanMerchantText($description);
            $description = StatementParser::sanitizeDescription($description);
            if ($description === '' || $this->isBalanceRowText(strtolower($description))) {
                continue;
            }

            $type = StatementParser::determineType($signedAmount, $description, $amountRaw, $chunk);
            if (preg_match('/\b(credit|cr)\b/i', $chunk)) {
                $type = 'income';
            } elseif (preg_match('/\b(debit|dr)\b/i', $chunk)) {
                $type = 'spending';
            }

            $key = strtolower($date.'|'.number_format($amount, 2, '.', '').'|'.$type.'|'.$description);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $rows[] = [
                'id' => (string) Str::uuid(),
                'date' => $date,
                'description' => $description,
                'amount' => $amount,
                'type' => $type,
                'category' => $type === 'income' ? 'Income' : 'Misc',
                'balance' => null,
                'include' => true,
                'duplicate' => false,
            ];
        }

        usort($rows, fn ($a, $b) => strcmp((string) $a['date'], (string) $b['date']));

        return $rows;
    }

    /**
     * Kept for compatibility.
     */
    public function parse(string $path): array
    {
        return $this->parseDocument($path)['transactions'];
    }

    /**
     * Kept for compatibility with old controller behavior.
     */
    public function extractSummary(string $text): array
    {
        $lines = $this->textToSyntheticLines($text);
        $boundary = $this->stageDetectBoundaries($lines);

        [$opening, $closing] = $this->extractBalancesFromLines($lines, $boundary);
        $change = null;
        if ($opening !== null && $closing !== null) {
            $change = $closing - $opening;
        }

        return [
            'opening_balance' => $opening,
            'closing_balance' => $closing,
            'balance_change' => $change,
        ];
    }

    private function hasEmbeddedText(string $path): bool
    {
        $pdftotext = trim((string) shell_exec('command -v pdftotext'));
        if ($pdftotext === '') {
            return false;
        }

        $command = escapeshellcmd($pdftotext).' -f 1 -l 2 '.escapeshellarg($path).' - 2>/dev/null';
        $text = trim((string) shell_exec($command));
        if ($text === '') {
            return false;
        }

        return preg_match_all('/[A-Za-z0-9]/', $text) >= 40;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractDigitalLines(string $path): array
    {
        $pdftotext = trim((string) shell_exec('command -v pdftotext'));
        if ($pdftotext === '') {
            throw new \RuntimeException('pdftotext is required for structured PDF extraction.');
        }

        $command = escapeshellcmd($pdftotext).' -bbox-layout '.escapeshellarg($path).' - 2>/dev/null';
        $html = (string) shell_exec($command);
        if (trim($html) === '') {
            throw new \RuntimeException('Unable to extract structured text from this PDF.');
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $pageNodes = $xpath->query('//*[local-name()="page"]');
        if (! $pageNodes) {
            return [];
        }

        $lines = [];
        foreach ($pageNodes as $pageNode) {
            $pageNum = (int) ($pageNode->getAttribute('number') ?: 1);
            $pageWidth = (float) ($pageNode->getAttribute('width') ?: 1.0);
            $pageHeight = (float) ($pageNode->getAttribute('height') ?: 1.0);
            $pageWidth = $pageWidth > 0 ? $pageWidth : 1.0;
            $pageHeight = $pageHeight > 0 ? $pageHeight : 1.0;

            $lineNodes = $xpath->query('.//*[local-name()="line"]', $pageNode);
            if (! $lineNodes) {
                continue;
            }

            foreach ($lineNodes as $lineNode) {
                $wordNodes = $xpath->query('.//*[local-name()="word"]', $lineNode);
                $words = [];

                if ($wordNodes) {
                    foreach ($wordNodes as $wordNode) {
                        $wordText = trim((string) $wordNode->textContent);
                        if ($wordText === '') {
                            continue;
                        }

                        $wxMin = (float) ($wordNode->getAttribute('xMin') ?: 0);
                        $wxMax = (float) ($wordNode->getAttribute('xMax') ?: 0);
                        $wyMin = (float) ($wordNode->getAttribute('yMin') ?: 0);
                        $wyMax = (float) ($wordNode->getAttribute('yMax') ?: 0);

                        $words[] = [
                            'text' => $wordText,
                            'x_min' => $wxMin / $pageWidth,
                            'x_max' => $wxMax / $pageWidth,
                            'y_min' => $wyMin / $pageHeight,
                            'y_max' => $wyMax / $pageHeight,
                        ];
                    }
                }

                $text = trim(implode(' ', array_map(fn ($word) => $word['text'], $words)));
                if ($text === '') {
                    $text = trim((string) $lineNode->textContent);
                }
                if ($text === '') {
                    continue;
                }

                $xMin = (float) ($lineNode->getAttribute('xMin') ?: 0);
                $xMax = (float) ($lineNode->getAttribute('xMax') ?: 0);
                $yMin = (float) ($lineNode->getAttribute('yMin') ?: 0);
                $yMax = (float) ($lineNode->getAttribute('yMax') ?: 0);

                if ($xMin === 0.0 && ! empty($words)) {
                    $xMin = min(array_column($words, 'x_min')) * $pageWidth;
                    $xMax = max(array_column($words, 'x_max')) * $pageWidth;
                    $yMin = min(array_column($words, 'y_min')) * $pageHeight;
                    $yMax = max(array_column($words, 'y_max')) * $pageHeight;
                }

                $lines[] = [
                    'id' => (string) Str::uuid(),
                    'page' => $pageNum,
                    'text' => $text,
                    'x_min' => $xMin / $pageWidth,
                    'x_max' => $xMax / $pageWidth,
                    'y_min' => $yMin / $pageHeight,
                    'y_max' => $yMax / $pageHeight,
                    'words' => $words,
                ];
            }
        }

        usort($lines, function (array $a, array $b) {
            if ($a['page'] !== $b['page']) {
                return $a['page'] <=> $b['page'];
            }

            $yCompare = $a['y_min'] <=> $b['y_min'];
            if ($yCompare !== 0) {
                return $yCompare;
            }

            return $a['x_min'] <=> $b['x_min'];
        });

        return $lines;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractTextractLines(string $path): array
    {
        if (! class_exists(TextractClient::class)) {
            throw new \RuntimeException('AWS Textract client is not available in this environment.');
        }

        $clientConfig = [
            'version' => 'latest',
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        ];

        $accessKey = (string) env('AWS_ACCESS_KEY_ID', '');
        $secretKey = (string) env('AWS_SECRET_ACCESS_KEY', '');
        if ($accessKey !== '' && $secretKey !== '') {
            $clientConfig['credentials'] = [
                'key' => $accessKey,
                'secret' => $secretKey,
            ];
        }

        $client = new TextractClient($clientConfig);

        try {
            $result = $client->analyzeDocument([
                'Document' => [
                    'Bytes' => file_get_contents($path),
                ],
                'FeatureTypes' => ['TABLES'],
            ]);
        } catch (\Throwable $error) {
            throw new \RuntimeException('Textract extraction failed: '.$error->getMessage(), previous: $error);
        }

        $blocks = $result['Blocks'] ?? [];
        $byId = [];
        foreach ($blocks as $block) {
            $id = $block['Id'] ?? null;
            if ($id) {
                $byId[$id] = $block;
            }
        }

        $lines = [];
        foreach ($blocks as $block) {
            if (($block['BlockType'] ?? null) !== 'LINE') {
                continue;
            }

            $box = $block['Geometry']['BoundingBox'] ?? [];
            $lineWords = [];

            foreach (($block['Relationships'] ?? []) as $relationship) {
                if (($relationship['Type'] ?? '') !== 'CHILD') {
                    continue;
                }

                foreach (($relationship['Ids'] ?? []) as $childId) {
                    $child = $byId[$childId] ?? null;
                    if (! $child || ($child['BlockType'] ?? null) !== 'WORD') {
                        continue;
                    }

                    $wordBox = $child['Geometry']['BoundingBox'] ?? [];
                    $lineWords[] = [
                        'text' => trim((string) ($child['Text'] ?? '')),
                        'x_min' => (float) ($wordBox['Left'] ?? 0),
                        'x_max' => (float) (($wordBox['Left'] ?? 0) + ($wordBox['Width'] ?? 0)),
                        'y_min' => (float) ($wordBox['Top'] ?? 0),
                        'y_max' => (float) (($wordBox['Top'] ?? 0) + ($wordBox['Height'] ?? 0)),
                    ];
                }
            }

            $text = trim((string) ($block['Text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $lines[] = [
                'id' => (string) Str::uuid(),
                'page' => (int) ($block['Page'] ?? 1),
                'text' => $text,
                'x_min' => (float) ($box['Left'] ?? 0),
                'x_max' => (float) (($box['Left'] ?? 0) + ($box['Width'] ?? 0)),
                'y_min' => (float) ($box['Top'] ?? 0),
                'y_max' => (float) (($box['Top'] ?? 0) + ($box['Height'] ?? 0)),
                'words' => array_values(array_filter($lineWords, fn ($word) => $word['text'] !== '')),
            ];
        }

        usort($lines, function (array $a, array $b) {
            if ($a['page'] !== $b['page']) {
                return $a['page'] <=> $b['page'];
            }

            $yCompare = $a['y_min'] <=> $b['y_min'];
            if ($yCompare !== 0) {
                return $yCompare;
            }

            return $a['x_min'] <=> $b['x_min'];
        });

        return $lines;
    }

    /**
     * @param array<int, array<string, mixed>> $inputLines
     * @return array<string, mixed>
     */
    private function runPipeline(array $inputLines, string $method, bool $debug): array
    {
        $stageA = $this->stageNormalize($inputLines);
        $lines = $stageA['lines'];

        $stageSet = $this->executeDeterministicStages($lines);
        $usedRowCoalescing = false;

        if (($method === 'pdf_text') && count($stageSet['stageE']['rows']) === 0) {
            $coalescedLines = $this->coalesceLinesByRow($lines);
            if (! empty($coalescedLines) && count($coalescedLines) !== count($lines)) {
                $coalescedStageSet = $this->executeDeterministicStages($coalescedLines);
                if (count($coalescedStageSet['stageE']['rows']) > 0) {
                    $usedRowCoalescing = true;
                    $lines = $coalescedLines;
                    $stageSet = $coalescedStageSet;
                }
            }
        }

        $stageB = $stageSet['stageB'];
        $stageC = $stageSet['stageC'];
        $stageD = $stageSet['stageD'];
        $stageE = $stageSet['stageE'];
        $stageF = $stageSet['stageF'];
        $stageGRows = $stageSet['stageGRows'];
        $stageH = $stageSet['stageH'];
        $rowsRecovered = $stageSet['rowsRecovered'];
        $orphanRecoveryUsed = $stageSet['orphanRecoveryUsed'];
        $usedTextFallback = false;

        if (empty($stageGRows)) {
            $orderedText = implode("\n", array_map(
                fn (array $line): string => (string) ($line['text'] ?? ''),
                $lines
            ));
            $textFallbackRows = $this->parseText($orderedText);
            if (! empty($textFallbackRows)) {
                $usedTextFallback = true;
                $stageGRows = array_map(function (array $row): array {
                    return [
                        'id' => (string) Str::uuid(),
                        'date' => (string) ($row['date'] ?? ''),
                        'description' => (string) ($row['description'] ?? ''),
                        'amount' => abs((float) ($row['amount'] ?? 0)),
                        'type' => (string) ($row['type'] ?? 'spending'),
                        'category' => (string) ($row['category'] ?? 'Misc'),
                        'balance' => $row['balance'] ?? null,
                        'include' => true,
                        'duplicate' => false,
                    ];
                }, $textFallbackRows);
                $stageH = $this->stageValidateBalances($lines, $stageB, $stageGRows);
            }
        }

        $confidence = $this->resolveConfidence(
            $stageH['balance_mismatch'],
            $orphanRecoveryUsed,
            $rowsRecovered,
            $stageH['validation']['opening_balance'] !== null && $stageH['validation']['closing_balance'] !== null
        );

        $summary = [
            'opening_balance' => $stageH['validation']['opening_balance'],
            'closing_balance' => $stageH['validation']['closing_balance'],
            'balance_change' => $stageH['validation']['balance_change'],
            'validation' => $stageH['validation'],
        ];

        $result = [
            'transactions' => $this->toTransactions($stageGRows),
            'summary' => $summary,
            'extraction_method' => $method,
            'extraction_confidence' => $confidence,
            'balance_mismatch' => $stageH['balance_mismatch'],
            'stats' => [
                'total_lines_extracted' => count($lines),
                'candidate_rows_detected' => count($stageE['rows']),
                'rows_merged' => $stageF['merged_count'],
                'rows_rejected' => count($stageE['rejected']),
                'rows_recovered' => $rowsRecovered,
                'row_coalescing_used' => $usedRowCoalescing,
                'text_fallback_used' => $usedTextFallback,
            ],
        ];

        if ($debug) {
            $result['debug'] = [
                'stage_b' => $stageB,
                'stage_c' => $stageC,
                'stage_d' => $stageD,
                'rejected_rows' => $stageE['rejected'],
                'raw_rows' => $stageGRows,
                'row_coalescing_used' => $usedRowCoalescing,
                'text_fallback_used' => $usedTextFallback,
            ];
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @return array{
     *   stageB: array<string, mixed>,
     *   stageC: array<string, mixed>,
     *   stageD: array<string, mixed>,
     *   stageE: array<string, mixed>,
     *   stageF: array<string, mixed>,
     *   stageGRows: array<int, array<string, mixed>>,
     *   stageH: array<string, mixed>,
     *   rowsRecovered: int,
     *   orphanRecoveryUsed: bool
     * }
     */
    private function executeDeterministicStages(array $lines): array
    {
        $stageB = $this->stageDetectBoundaries($lines);
        $stageC = $this->stageIdentifyTransactionRegion($lines, $stageB);
        $stageD = $this->stageDetectColumns($lines, $stageB, $stageC);
        $stageE = $this->stageDetectCandidates($lines, $stageB, $stageC, $stageD);
        $stageF = $this->stageMergeWrappedRows($lines, $stageE['rows'], $stageE['used_line_ids'], $stageB, $stageC, $stageD);
        $stageGRows = $this->stageClassifyRows($stageF['rows']);
        $stageH = $this->stageValidateBalances($lines, $stageB, $stageGRows);

        $rowsRecovered = 0;
        $orphanRecoveryUsed = false;

        if ($stageH['balance_mismatch']) {
            $stageI = $this->stageSecondaryOrphanScan($lines, $stageB, $stageC, $stageD, $stageE['rejected'], $stageGRows);
            if (! empty($stageI['rows'])) {
                $rowsRecovered = count($stageI['rows']);
                $orphanRecoveryUsed = true;
                $stageGRows = $this->stageClassifyRows(array_values(array_merge($stageGRows, $stageI['rows'])));
                $stageH = $this->stageValidateBalances($lines, $stageB, $stageGRows);
            }
        }

        return [
            'stageB' => $stageB,
            'stageC' => $stageC,
            'stageD' => $stageD,
            'stageE' => $stageE,
            'stageF' => $stageF,
            'stageGRows' => $stageGRows,
            'stageH' => $stageH,
            'rowsRecovered' => $rowsRecovered,
            'orphanRecoveryUsed' => $orphanRecoveryUsed,
        ];
    }

    /**
     * Recompose fragmented table rows by page + vertical alignment.
     *
     * @param array<int, array<string, mixed>> $lines
     * @return array<int, array<string, mixed>>
     */
    private function coalesceLinesByRow(array $lines): array
    {
        $byPage = [];
        foreach ($lines as $line) {
            $page = (int) ($line['page'] ?? 1);
            $byPage[$page][] = $line;
        }

        $coalesced = [];

        foreach ($byPage as $page => $pageLines) {
            usort($pageLines, function (array $a, array $b) {
                $yCompare = ((float) ($a['y_min'] ?? 0)) <=> ((float) ($b['y_min'] ?? 0));
                if ($yCompare !== 0) {
                    return $yCompare;
                }

                return ((float) ($a['x_min'] ?? 0)) <=> ((float) ($b['x_min'] ?? 0));
            });

            $heights = [];
            foreach ($pageLines as $line) {
                $height = max(0.0005, ((float) ($line['y_max'] ?? 0)) - ((float) ($line['y_min'] ?? 0)));
                $heights[] = $height;
            }
            sort($heights);
            $mid = (int) floor(count($heights) / 2);
            $medianHeight = $heights[$mid] ?? 0.006;
            $threshold = max(0.003, min(0.04, $medianHeight * 1.2));

            $clusters = [];
            foreach ($pageLines as $line) {
                $yCenter = ((((float) ($line['y_min'] ?? 0)) + ((float) ($line['y_max'] ?? 0))) / 2);
                $placed = false;

                for ($i = count($clusters) - 1; $i >= 0; $i--) {
                    if (abs($yCenter - $clusters[$i]['center_y']) <= $threshold) {
                        $clusters[$i]['lines'][] = $line;
                        $lineCount = count($clusters[$i]['lines']);
                        $clusters[$i]['center_y'] = (($clusters[$i]['center_y'] * ($lineCount - 1)) + $yCenter) / $lineCount;
                        $placed = true;
                        break;
                    }
                }

                if (! $placed) {
                    $clusters[] = [
                        'center_y' => $yCenter,
                        'lines' => [$line],
                    ];
                }
            }

            foreach ($clusters as $cluster) {
                $clusterLines = $cluster['lines'];
                usort($clusterLines, fn ($a, $b) => ((float) ($a['x_min'] ?? 0)) <=> ((float) ($b['x_min'] ?? 0)));

                $words = [];
                foreach ($clusterLines as $line) {
                    $lineWords = $line['words'] ?? [];
                    if (! empty($lineWords)) {
                        foreach ($lineWords as $word) {
                            $words[] = [
                                'text' => (string) ($word['text'] ?? ''),
                                'x_min' => (float) ($word['x_min'] ?? 0),
                                'x_max' => (float) ($word['x_max'] ?? 0),
                                'y_min' => (float) ($word['y_min'] ?? 0),
                                'y_max' => (float) ($word['y_max'] ?? 0),
                            ];
                        }
                    } else {
                        $words[] = [
                            'text' => (string) ($line['text'] ?? ''),
                            'x_min' => (float) ($line['x_min'] ?? 0),
                            'x_max' => (float) ($line['x_max'] ?? 0),
                            'y_min' => (float) ($line['y_min'] ?? 0),
                            'y_max' => (float) ($line['y_max'] ?? 0),
                        ];
                    }
                }

                usort($words, fn ($a, $b) => ((float) ($a['x_min'] ?? 0)) <=> ((float) ($b['x_min'] ?? 0)));
                $text = trim(implode(' ', array_filter(array_map(fn ($word) => trim((string) ($word['text'] ?? '')), $words))));
                if ($text === '') {
                    continue;
                }

                $coalesced[] = [
                    'id' => (string) Str::uuid(),
                    'page' => (int) $page,
                    'text' => $text,
                    'text_lower' => strtolower($text),
                    'x_min' => min(array_map(fn ($line) => (float) ($line['x_min'] ?? 0), $clusterLines)),
                    'x_max' => max(array_map(fn ($line) => (float) ($line['x_max'] ?? 0), $clusterLines)),
                    'y_min' => min(array_map(fn ($line) => (float) ($line['y_min'] ?? 0), $clusterLines)),
                    'y_max' => max(array_map(fn ($line) => (float) ($line['y_max'] ?? 0), $clusterLines)),
                    'words' => $words,
                ];
            }
        }

        usort($coalesced, function (array $a, array $b) {
            if ($a['page'] !== $b['page']) {
                return $a['page'] <=> $b['page'];
            }

            $yCompare = ((float) ($a['y_min'] ?? 0)) <=> ((float) ($b['y_min'] ?? 0));
            if ($yCompare !== 0) {
                return $yCompare;
            }

            return ((float) ($a['x_min'] ?? 0)) <=> ((float) ($b['x_min'] ?? 0));
        });

        return $coalesced;
    }

    /**
     * @param array<int, array<string, mixed>> $inputLines
     * @return array{lines: array<int, array<string, mixed>>}
     */
    private function stageNormalize(array $inputLines): array
    {
        $normalized = [];

        foreach ($inputLines as $line) {
            $text = $this->normalizeLineText((string) ($line['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $words = [];
            foreach (($line['words'] ?? []) as $word) {
                $wordText = $this->normalizeLineText((string) ($word['text'] ?? ''));
                if ($wordText === '') {
                    continue;
                }

                $words[] = [
                    'text' => $wordText,
                    'x_min' => (float) ($word['x_min'] ?? 0),
                    'x_max' => (float) ($word['x_max'] ?? 0),
                    'y_min' => (float) ($word['y_min'] ?? 0),
                    'y_max' => (float) ($word['y_max'] ?? 0),
                ];
            }

            $normalized[] = [
                'id' => (string) ($line['id'] ?? Str::uuid()),
                'page' => (int) ($line['page'] ?? 1),
                'text' => $text,
                'text_lower' => strtolower($text),
                'x_min' => (float) ($line['x_min'] ?? 0),
                'x_max' => (float) ($line['x_max'] ?? 1),
                'y_min' => (float) ($line['y_min'] ?? 0),
                'y_max' => (float) ($line['y_max'] ?? 0),
                'words' => $words,
            ];
        }

        usort($normalized, function (array $a, array $b) {
            if ($a['page'] !== $b['page']) {
                return $a['page'] <=> $b['page'];
            }
            $yCompare = $a['y_min'] <=> $b['y_min'];
            if ($yCompare !== 0) {
                return $yCompare;
            }
            return $a['x_min'] <=> $b['x_min'];
        });

        return ['lines' => $normalized];
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    private function stageDetectBoundaries(array $lines): array
    {
        $excludedIds = [];
        $summaryIds = [];
        $headerIds = [];
        $footerIds = [];

        $summaryStart = null;
        $summaryEnd = null;

        foreach ($lines as $line) {
            $text = $line['text_lower'];

            if (str_contains($text, 'account summary')) {
                $summaryStart = ['page' => $line['page'], 'y' => $line['y_min']];
                $summaryIds[$line['id']] = true;
                $excludedIds[$line['id']] = true;
                continue;
            }

            if (str_contains($text, 'cashflow summary')) {
                $summaryEnd = ['page' => $line['page'], 'y' => $line['y_min']];
                $summaryIds[$line['id']] = true;
                $excludedIds[$line['id']] = true;
                continue;
            }

            if ($this->isHeaderLine($text)) {
                $headerIds[$line['id']] = true;
                $excludedIds[$line['id']] = true;
                continue;
            }

            if ($this->isFooterLine($text)) {
                $footerIds[$line['id']] = true;
                $excludedIds[$line['id']] = true;
                continue;
            }

            if ($this->isSummaryLikeLine($text)) {
                $summaryIds[$line['id']] = true;
                $excludedIds[$line['id']] = true;
            }
        }

        if ($summaryStart && $summaryEnd) {
            foreach ($lines as $line) {
                if ($line['page'] < $summaryStart['page'] || $line['page'] > $summaryEnd['page']) {
                    continue;
                }

                if ($line['page'] === $summaryStart['page'] && $line['y_min'] < $summaryStart['y']) {
                    continue;
                }

                if ($line['page'] === $summaryEnd['page'] && $line['y_min'] > $summaryEnd['y']) {
                    continue;
                }

                $summaryIds[$line['id']] = true;
                $excludedIds[$line['id']] = true;
            }
        }

        return [
            'excluded_ids' => $excludedIds,
            'summary_ids' => $summaryIds,
            'header_ids' => $headerIds,
            'footer_ids' => $footerIds,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @param array<string, mixed> $stageB
     * @return array<string, mixed>
     */
    private function stageIdentifyTransactionRegion(array $lines, array $stageB): array
    {
        $seedByPage = [];
        foreach ($lines as $line) {
            if (isset($stageB['excluded_ids'][$line['id']])) {
                continue;
            }

            if (! $this->lineHasDate($line['text']) || ! $this->lineHasAmount($line['text'])) {
                continue;
            }

            $seedByPage[$line['page']][] = $line;
        }

        $regions = [];
        foreach ($seedByPage as $page => $rows) {
            usort($rows, fn ($a, $b) => $a['y_min'] <=> $b['y_min']);
            $ys = array_map(fn ($row) => (float) $row['y_min'], $rows);
            $spacing = $this->medianSpacing($ys);
            $first = (float) min($ys);
            $last = (float) max($ys);

            $padding = max($spacing * 1.5, 0.01);
            $regions[(int) $page] = [
                'min_y' => max(0.0, $first - $padding),
                'max_y' => min(1.0, $last + $padding),
                'row_spacing' => $spacing,
            ];
        }

        return [
            'regions' => $regions,
            'seed_count' => array_sum(array_map('count', $seedByPage)),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @param array<string, mixed> $stageB
     * @param array<string, mixed> $stageC
     * @return array<string, mixed>
     */
    private function stageDetectColumns(array $lines, array $stageB, array $stageC): array
    {
        $datePositions = [];
        $amountPositions = [];
        $balancePositions = [];
        $debitCreditPositions = [];

        foreach ($lines as $line) {
            if (! $this->isLineInsideRegion($line, $stageC['regions'] ?? [])) {
                continue;
            }

            if (isset($stageB['excluded_ids'][$line['id']])) {
                continue;
            }

            $words = $line['words'] ?? [];
            if (empty($words)) {
                continue;
            }

            $dateWord = $this->findDateWord($words);
            if ($dateWord) {
                $datePositions[] = ($dateWord['x_min'] + $dateWord['x_max']) / 2;
            }

            $amountWords = $this->findAmountWords($words);
            if (! empty($amountWords)) {
                usort($amountWords, fn ($a, $b) => (($a['x_min'] + $a['x_max']) / 2) <=> (($b['x_min'] + $b['x_max']) / 2));
                $last = end($amountWords);
                $balancePositions[] = (($last['x_min'] + $last['x_max']) / 2);

                if (count($amountWords) >= 2) {
                    $amountWord = $amountWords[count($amountWords) - 2];
                    $amountPositions[] = (($amountWord['x_min'] + $amountWord['x_max']) / 2);
                } else {
                    $amountPositions[] = (($last['x_min'] + $last['x_max']) / 2);
                }
            }

            foreach ($words as $word) {
                $text = strtolower($word['text']);
                if (in_array($text, ['debit', 'credit', 'dr', 'cr'], true)) {
                    $debitCreditPositions[] = (($word['x_min'] + $word['x_max']) / 2);
                }
            }
        }

        $dateCenter = $this->dominantClusterCenter($datePositions, 0.04);
        $amountCenter = $this->dominantClusterCenter($amountPositions, 0.04);
        $balanceCenter = $this->dominantClusterCenter($balancePositions, 0.04);
        $debitCreditCenter = $this->dominantClusterCenter($debitCreditPositions, 0.04);

        $dateRange = $dateCenter !== null ? [$dateCenter - 0.08, $dateCenter + 0.08] : null;
        $amountRange = $amountCenter !== null ? [$amountCenter - 0.08, $amountCenter + 0.08] : null;
        $balanceRange = $balanceCenter !== null ? [$balanceCenter - 0.08, $balanceCenter + 0.08] : null;

        $descriptionRange = null;
        if ($dateRange !== null && $amountRange !== null) {
            $descriptionRange = [
                max(0.0, $dateRange[1] + 0.01),
                min(1.0, $amountRange[0] - 0.01),
            ];
        }

        return [
            'date_range' => $dateRange,
            'amount_range' => $amountRange,
            'balance_range' => $balanceRange,
            'description_range' => $descriptionRange,
            'debit_credit_center' => $debitCreditCenter,
            'statement_year' => StatementParser::extractStatementYear(implode("\n", array_map(fn ($line) => $line['text'], $lines))),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @param array<string, mixed> $stageB
     * @param array<string, mixed> $stageC
     * @param array<string, mixed> $stageD
     * @return array<string, mixed>
     */
    private function stageDetectCandidates(array $lines, array $stageB, array $stageC, array $stageD): array
    {
        $rows = [];
        $usedLineIds = [];
        $rejected = [];

        foreach ($lines as $line) {
            $reject = null;

            if (isset($stageB['excluded_ids'][$line['id']])) {
                $reject = 'excluded_summary_header_footer';
            } elseif (! $this->isLineInsideRegion($line, $stageC['regions'] ?? [])) {
                $reject = 'outside_transaction_region';
            }

            $dateToken = $this->extractDateTokenFromLine($line['text']);
            if (! $reject && $dateToken === null) {
                $reject = 'missing_date';
            }

            $amountMeta = null;
            if (! $reject) {
                $amountMeta = $this->selectAmountTokenForLine($line, $stageD);
                if ($amountMeta === null) {
                    $reject = 'missing_amount';
                }
            }

            if ($reject) {
                $rejected[] = [
                    'line_id' => $line['id'],
                    'page' => $line['page'],
                    'text' => $line['text'],
                    'reason' => $reject,
                ];
                continue;
            }

            $date = StatementParser::parseDate((string) $dateToken, $stageD['statement_year'] ?? null);
            if (! $date) {
                $rejected[] = [
                    'line_id' => $line['id'],
                    'page' => $line['page'],
                    'text' => $line['text'],
                    'reason' => 'invalid_date',
                ];
                continue;
            }

            $signedAmount = StatementParser::parseAmount($amountMeta['raw']);
            $amount = abs($signedAmount);
            if ($amount <= 0) {
                $rejected[] = [
                    'line_id' => $line['id'],
                    'page' => $line['page'],
                    'text' => $line['text'],
                    'reason' => 'invalid_amount',
                ];
                continue;
            }

            $description = $this->extractDescriptionFromStructuredLine($line, $dateToken, $amountMeta['raw'], $stageD);
            if ($description === '') {
                $rejected[] = [
                    'line_id' => $line['id'],
                    'page' => $line['page'],
                    'text' => $line['text'],
                    'reason' => 'missing_description',
                ];
                continue;
            }

            $rows[] = [
                'id' => (string) Str::uuid(),
                'source_line_id' => $line['id'],
                'page' => $line['page'],
                'y_min' => $line['y_min'],
                'date_raw' => $dateToken,
                'date' => $date,
                'description' => $description,
                'amount_raw' => $amountMeta['raw'],
                'amount' => $amount,
                'signed_amount' => $signedAmount,
                'debit_credit_token' => $this->extractDebitCreditToken($line, $stageD),
                'raw_line' => $line['text'],
                'balance_raw' => $amountMeta['balance_raw'] ?? null,
            ];
            $usedLineIds[$line['id']] = true;
        }

        return [
            'rows' => $rows,
            'used_line_ids' => $usedLineIds,
            'rejected' => $rejected,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, bool> $usedLineIds
     * @param array<string, mixed> $stageB
     * @param array<string, mixed> $stageC
     * @param array<string, mixed> $stageD
     * @return array{rows: array<int, array<string, mixed>>, merged_count: int}
     */
    private function stageMergeWrappedRows(
        array $lines,
        array $rows,
        array $usedLineIds,
        array $stageB,
        array $stageC,
        array $stageD
    ): array {
        usort($rows, function (array $a, array $b) {
            if ($a['page'] !== $b['page']) {
                return $a['page'] <=> $b['page'];
            }

            return $a['y_min'] <=> $b['y_min'];
        });

        $indexByPage = [];
        foreach ($rows as $index => $row) {
            $indexByPage[$row['page']][] = $index;
        }

        $merged = 0;
        foreach ($lines as $line) {
            if (isset($usedLineIds[$line['id']])) {
                continue;
            }
            if (isset($stageB['excluded_ids'][$line['id']])) {
                continue;
            }
            if (! $this->isLineInsideRegion($line, $stageC['regions'] ?? [])) {
                continue;
            }
            if ($this->lineHasDate($line['text']) || $this->lineHasAmount($line['text'])) {
                continue;
            }
            if (! $this->lineAlignsToDescriptionColumn($line, $stageD)) {
                continue;
            }

            $candidateIndexes = $indexByPage[$line['page']] ?? [];
            if (empty($candidateIndexes)) {
                continue;
            }

            $closestIndex = null;
            $closestGap = null;
            foreach ($candidateIndexes as $rowIndex) {
                $gap = $line['y_min'] - $rows[$rowIndex]['y_min'];
                if ($gap <= 0) {
                    continue;
                }

                $spacing = (float) (($stageC['regions'][$line['page']]['row_spacing'] ?? 0.012));
                $maxGap = max($spacing * 1.6, 0.02);
                if ($gap > $maxGap) {
                    continue;
                }

                if ($closestGap === null || $gap < $closestGap) {
                    $closestGap = $gap;
                    $closestIndex = $rowIndex;
                }
            }

            if ($closestIndex === null) {
                continue;
            }

            $appendText = $this->cleanMerchantText($line['text']);
            if ($appendText === '') {
                continue;
            }

            $rows[$closestIndex]['description'] = trim($rows[$closestIndex]['description'].' '.$appendText);
            $merged++;
        }

        return [
            'rows' => $rows,
            'merged_count' => $merged,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function stageClassifyRows(array $rows): array
    {
        $classified = [];

        foreach ($rows as $row) {
            $token = strtolower((string) ($row['debit_credit_token'] ?? ''));

            if (in_array($token, ['credit', 'cr'], true)) {
                $type = 'income';
            } elseif (in_array($token, ['debit', 'dr'], true)) {
                $type = 'spending';
            } elseif (str_contains(strtolower($row['raw_line'] ?? ''), ' debit ')) {
                $type = 'spending';
            } elseif (str_contains(strtolower($row['raw_line'] ?? ''), ' credit ')) {
                $type = 'income';
            } else {
                $type = StatementParser::determineType(
                    (float) ($row['signed_amount'] ?? 0),
                    (string) ($row['description'] ?? ''),
                    (string) ($row['amount_raw'] ?? ''),
                    (string) ($row['raw_line'] ?? '')
                );
            }

            $description = StatementParser::sanitizeDescription($this->cleanMerchantText((string) ($row['description'] ?? '')));
            if ($description === '') {
                continue;
            }

            $classified[] = [
                'id' => (string) Str::uuid(),
                'date' => (string) $row['date'],
                'description' => $description,
                'amount' => abs((float) ($row['amount'] ?? 0)),
                'type' => $type,
                'category' => $type === 'income' ? 'Income' : 'Misc',
                'balance' => $row['balance_raw'] ? abs(StatementParser::parseAmount((string) $row['balance_raw'])) : null,
                'include' => true,
                'duplicate' => false,
                'line_signature' => strtolower((string) $row['date'].'|'.number_format(abs((float) ($row['amount'] ?? 0)), 2, '.', '').'|'.$description),
            ];
        }

        $seen = [];
        $deduped = [];
        foreach ($classified as $row) {
            $key = strtolower($row['date'].'|'.number_format((float) $row['amount'], 2, '.', '').'|'.$row['type'].'|'.$row['description']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $row;
        }

        usort($deduped, fn ($a, $b) => strcmp((string) $a['date'], (string) $b['date']));

        return $deduped;
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @param array<string, mixed> $stageB
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function stageValidateBalances(array $lines, array $stageB, array $rows): array
    {
        [$opening, $closing] = $this->extractBalancesFromLines($lines, $stageB);

        $credits = 0.0;
        $debits = 0.0;
        foreach ($rows as $row) {
            if (($row['type'] ?? 'spending') === 'income') {
                $credits += (float) ($row['amount'] ?? 0);
            } else {
                $debits += (float) ($row['amount'] ?? 0);
            }
        }

        $computedEnding = null;
        $balanceMismatch = false;
        if ($opening !== null && $closing !== null) {
            $computedEnding = $opening + $credits - $debits;
            $balanceMismatch = abs($computedEnding - $closing) > self::BALANCE_TOLERANCE;
        }

        return [
            'balance_mismatch' => $balanceMismatch,
            'validation' => [
                'opening_balance' => $opening,
                'closing_balance' => $closing,
                'balance_change' => ($opening !== null && $closing !== null) ? ($closing - $opening) : null,
                'credits_total' => round($credits, 2),
                'debits_total' => round($debits, 2),
                'computed_ending_balance' => $computedEnding !== null ? round($computedEnding, 2) : null,
                'tolerance' => self::BALANCE_TOLERANCE,
                'balance_mismatch' => $balanceMismatch,
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @param array<string, mixed> $stageB
     * @param array<string, mixed> $stageC
     * @param array<string, mixed> $stageD
     * @param array<int, array<string, mixed>> $rejected
     * @param array<int, array<string, mixed>> $existingRows
     * @return array{rows: array<int, array<string, mixed>>}
     */
    private function stageSecondaryOrphanScan(
        array $lines,
        array $stageB,
        array $stageC,
        array $stageD,
        array $rejected,
        array $existingRows
    ): array {
        $existingKeys = [];
        foreach ($existingRows as $row) {
            $existingKeys[strtolower($row['date'].'|'.number_format((float) $row['amount'], 2, '.', '').'|'.$row['description'])] = true;
        }

        $rejectedMap = [];
        foreach ($rejected as $item) {
            $rejectedMap[$item['line_id']] = $item;
        }

        $recovered = [];
        foreach ($lines as $line) {
            if (! isset($rejectedMap[$line['id']])) {
                continue;
            }

            if (isset($stageB['excluded_ids'][$line['id']])) {
                continue;
            }

            if (! $this->isLineInsideRegion($line, $stageC['regions'] ?? [])) {
                continue;
            }

            $dateToken = $this->extractDateTokenFromLine($line['text']);
            if (! $dateToken) {
                continue;
            }

            $amountRaw = StatementParser::pickLikelyAmount(StatementParser::extractAmounts($line['text']));
            if (! $amountRaw) {
                continue;
            }

            $date = StatementParser::parseDate((string) $dateToken, $stageD['statement_year'] ?? null);
            if (! $date) {
                continue;
            }

            $signedAmount = StatementParser::parseAmount($amountRaw);
            $amount = abs($signedAmount);
            if ($amount <= 0) {
                continue;
            }

            $description = $this->extractDescriptionFromStructuredLine($line, $dateToken, $amountRaw, $stageD);
            if ($description === '') {
                $description = StatementParser::extractDescription($line['text'], (string) $dateToken, [$amountRaw]);
            }
            $description = StatementParser::sanitizeDescription($this->cleanMerchantText($description));
            if ($description === '') {
                continue;
            }

            $type = StatementParser::determineType($signedAmount, $description, $amountRaw, $line['text']);
            $key = strtolower($date.'|'.number_format($amount, 2, '.', '').'|'.$description);
            if (isset($existingKeys[$key])) {
                continue;
            }

            $existingKeys[$key] = true;
            $recovered[] = [
                'id' => (string) Str::uuid(),
                'date' => $date,
                'description' => $description,
                'amount' => $amount,
                'type' => $type,
                'category' => $type === 'income' ? 'Income' : 'Misc',
                'balance' => null,
                'include' => true,
                'duplicate' => false,
            ];
        }

        return ['rows' => $recovered];
    }

    private function resolveConfidence(bool $balanceMismatch, bool $orphanRecoveryUsed, int $rowsRecovered, bool $hasBalanceContext): string
    {
        if ($balanceMismatch) {
            return 'low';
        }

        if ($orphanRecoveryUsed && $rowsRecovered > 0) {
            return 'medium';
        }

        if (! $hasBalanceContext) {
            return 'medium';
        }

        return 'high';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function toTransactions(array $rows): array
    {
        return array_map(function (array $row) {
            return [
                'id' => (string) Str::uuid(),
                'date' => $row['date'],
                'description' => $row['description'],
                'amount' => (float) $row['amount'],
                'type' => $row['type'],
                'category' => $row['category'],
                'balance' => $row['balance'] ?? null,
                'include' => true,
                'duplicate' => false,
            ];
        }, $rows);
    }

    private function normalizeLineText(string $text): string
    {
        $text = str_replace(["\u{00A0}", "\u{200B}", "\u{200C}", "\u{200D}", "\u{FEFF}"], ' ', $text);
        $text = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text) ?? $text;
        $text = str_replace(['€', '£'], '$', $text);
        $text = str_replace(['−', '–', '—'], '-', $text);
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);

        return $text;
    }

    private function isHeaderLine(string $text): bool
    {
        return str_contains($text, 'date')
            && (str_contains($text, 'description') || str_contains($text, 'merchant'))
            && (str_contains($text, 'amount') || str_contains($text, 'debit') || str_contains($text, 'credit'));
    }

    private function isFooterLine(string $text): bool
    {
        return str_starts_with($text, 'page ')
            || str_contains($text, 'fdic')
            || str_contains($text, 'capitalone.com')
            || str_contains($text, 'member fdic');
    }

    private function isSummaryLikeLine(string $text): bool
    {
        return str_contains($text, 'statement period')
            || str_contains($text, 'account summary')
            || str_contains($text, 'cashflow summary')
            || str_contains($text, 'balance summary')
            || str_contains($text, 'opening balance')
            || str_contains($text, 'closing balance')
            || str_contains($text, 'ending balance')
            || str_contains($text, 'total ending balance')
            || str_contains($text, 'annual percentage yield')
            || str_contains($text, 'ytd interest')
            || str_contains($text, 'days in statement cycle')
            || str_contains($text, 'all accounts');
    }

    private function lineHasDate(string $text): bool
    {
        return $this->extractDateTokenFromLine($text) !== null;
    }

    private function lineHasAmount(string $text): bool
    {
        return ! empty(StatementParser::extractAmounts($text));
    }

    private function isLikelyDateStart(string $line): bool
    {
        return preg_match('/^\s*'.StatementParser::monthPattern().'\s+\d{1,2}\b/i', $line) === 1
            || preg_match('/^\s*\d{1,2}[\/-]\d{1,2}(?:[\/-]\d{2,4})?\b/', $line) === 1;
    }

    private function isBalanceRowText(string $normalized): bool
    {
        return str_contains($normalized, 'opening balance')
            || str_contains($normalized, 'closing balance')
            || str_contains($normalized, 'ending balance')
            || str_contains($normalized, 'new balance')
            || str_contains($normalized, 'total ending balance')
            || str_contains($normalized, 'balance forward');
    }

    private function extractDateTokenFromLine(string $text): ?string
    {
        return StatementParser::extractDateToken($text, StatementParser::monthPattern());
    }

    /**
     * @param array<int, float> $values
     */
    private function dominantClusterCenter(array $values, float $bucketSize): ?float
    {
        if (empty($values)) {
            return null;
        }

        $buckets = [];
        foreach ($values as $value) {
            $bucket = (string) round($value / $bucketSize);
            $buckets[$bucket][] = $value;
        }

        uasort($buckets, fn ($a, $b) => count($b) <=> count($a));
        $top = reset($buckets);
        if (! is_array($top) || empty($top)) {
            return null;
        }

        return array_sum($top) / count($top);
    }

    /**
     * @param array<int, float> $ys
     */
    private function medianSpacing(array $ys): float
    {
        if (count($ys) < 2) {
            return 0.012;
        }

        sort($ys);
        $deltas = [];
        for ($i = 1; $i < count($ys); $i++) {
            $delta = $ys[$i] - $ys[$i - 1];
            if ($delta > 0) {
                $deltas[] = $delta;
            }
        }

        if (empty($deltas)) {
            return 0.012;
        }

        sort($deltas);
        $mid = (int) floor(count($deltas) / 2);

        return $deltas[$mid] ?? 0.012;
    }

    /**
     * @param array<int, array<string, mixed>> $words
     * @return array<string, mixed>|null
     */
    private function findDateWord(array $words): ?array
    {
        foreach ($words as $word) {
            if ($this->lineHasDate((string) ($word['text'] ?? ''))) {
                return $word;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $words
     * @return array<int, array<string, mixed>>
     */
    private function findAmountWords(array $words): array
    {
        $amountWords = [];
        $pattern = '/'.StatementParser::amountPattern().'/';

        foreach ($words as $word) {
            $text = (string) ($word['text'] ?? '');
            if (preg_match($pattern, $text)) {
                $amountWords[] = $word;
            }
        }

        return $amountWords;
    }

    /**
     * @param array<string, mixed> $line
     * @param array<string, mixed> $stageD
     * @return array<string, mixed>|null
     */
    private function selectAmountTokenForLine(array $line, array $stageD): ?array
    {
        $tokens = [];
        $pattern = '/'.StatementParser::amountPattern().'/';

        foreach (($line['words'] ?? []) as $word) {
            $text = (string) ($word['text'] ?? '');
            if (! preg_match($pattern, $text)) {
                continue;
            }

            $center = ((float) $word['x_min'] + (float) $word['x_max']) / 2;
            $tokens[] = [
                'raw' => $text,
                'center' => $center,
                'x_min' => (float) $word['x_min'],
                'x_max' => (float) $word['x_max'],
            ];
        }

        if (empty($tokens)) {
            $amountRaw = StatementParser::pickLikelyAmount(StatementParser::extractAmounts((string) $line['text']));
            if (! $amountRaw) {
                return null;
            }

            return [
                'raw' => $amountRaw,
                'center' => ((float) ($line['x_min'] ?? 0) + (float) ($line['x_max'] ?? 0)) / 2,
                'balance_raw' => null,
            ];
        }

        usort($tokens, fn ($a, $b) => $a['center'] <=> $b['center']);

        $amountToken = end($tokens);
        $balanceRaw = null;

        if (count($tokens) >= 2) {
            $amountToken = $tokens[count($tokens) - 2];
            $balanceRaw = $tokens[count($tokens) - 1]['raw'];
        }

        $amountRange = $stageD['amount_range'] ?? null;
        if ($amountRange !== null) {
            $candidate = null;
            $bestDistance = null;
            $target = (($amountRange[0] + $amountRange[1]) / 2);

            foreach ($tokens as $token) {
                $center = (float) $token['center'];
                if ($center < $amountRange[0] || $center > $amountRange[1]) {
                    continue;
                }

                $distance = abs($center - $target);
                if ($bestDistance === null || $distance < $bestDistance) {
                    $bestDistance = $distance;
                    $candidate = $token;
                }
            }

            if ($candidate) {
                $amountToken = $candidate;
            }
        }

        return [
            'raw' => (string) $amountToken['raw'],
            'center' => (float) $amountToken['center'],
            'balance_raw' => $balanceRaw,
        ];
    }

    /**
     * @param array<string, mixed> $line
     * @param array<string, mixed> $stageD
     */
    private function extractDebitCreditToken(array $line, array $stageD): ?string
    {
        $target = $stageD['debit_credit_center'] ?? null;

        foreach (($line['words'] ?? []) as $word) {
            $text = strtolower((string) ($word['text'] ?? ''));
            if (! in_array($text, ['debit', 'credit', 'dr', 'cr'], true)) {
                continue;
            }

            if ($target === null) {
                return $text;
            }

            $center = ((float) $word['x_min'] + (float) $word['x_max']) / 2;
            if (abs($center - $target) <= 0.1) {
                return $text;
            }
        }

        if (preg_match('/\b(debit|credit|dr|cr)\b/i', (string) ($line['text'] ?? ''), $matches)) {
            return strtolower($matches[1]);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $line
     * @param array<string, mixed> $stageD
     */
    private function extractDescriptionFromStructuredLine(array $line, string $dateToken, string $amountRaw, array $stageD): string
    {
        $descriptionParts = [];
        $range = $stageD['description_range'] ?? null;

        foreach (($line['words'] ?? []) as $word) {
            $text = (string) ($word['text'] ?? '');
            if ($text === '') {
                continue;
            }

            if ($text === $dateToken || $text === $amountRaw) {
                continue;
            }

            if (preg_match('/\b(debit|credit|dr|cr)\b/i', $text)) {
                continue;
            }

            $center = ((float) $word['x_min'] + (float) $word['x_max']) / 2;
            if ($range !== null && ($center < $range[0] || $center > $range[1])) {
                continue;
            }

            if (preg_match('/'.StatementParser::amountPattern().'/', $text)) {
                continue;
            }

            $descriptionParts[] = $text;
        }

        $description = trim(implode(' ', $descriptionParts));
        if ($description === '') {
            $description = StatementParser::extractDescription((string) $line['text'], $dateToken, [$amountRaw]);
        }

        return $this->cleanMerchantText($description);
    }

    /**
     * @param array<string, mixed> $line
     * @param array<string, mixed> $stageD
     */
    private function lineAlignsToDescriptionColumn(array $line, array $stageD): bool
    {
        $range = $stageD['description_range'] ?? null;
        if ($range === null) {
            return true;
        }

        $center = ((float) ($line['x_min'] ?? 0) + (float) ($line['x_max'] ?? 0)) / 2;

        return $center >= ($range[0] - 0.1) && $center <= ($range[1] + 0.1);
    }

    /**
     * @param array<string, mixed> $line
     * @param array<int, array<string, mixed>> $regions
     */
    private function isLineInsideRegion(array $line, array $regions): bool
    {
        $page = (int) ($line['page'] ?? 1);
        if (! isset($regions[$page])) {
            return false;
        }

        $region = $regions[$page];

        return (float) $line['y_min'] >= (float) $region['min_y']
            && (float) $line['y_min'] <= (float) $region['max_y'];
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @param array<string, mixed> $stageB
     * @return array{0: ?float, 1: ?float}
     */
    private function extractBalancesFromLines(array $lines, array $stageB): array
    {
        $opening = null;
        $closing = null;

        foreach ($lines as $line) {
            $text = $line['text_lower'];
            $amounts = StatementParser::extractAmounts($line['text']);
            if (empty($amounts)) {
                continue;
            }

            if (str_contains($text, 'all accounts') && count($amounts) >= 2) {
                $opening = $opening ?? abs(StatementParser::parseAmount($amounts[0]));
                $closing = $closing ?? abs(StatementParser::parseAmount($amounts[1]));
                continue;
            }

            if ($opening === null && (str_contains($text, 'opening balance') || str_contains($text, 'beginning balance') || str_contains($text, 'balance forward'))) {
                $opening = abs(StatementParser::parseAmount(end($amounts)));
                continue;
            }

            if ($closing === null && (str_contains($text, 'closing balance') || str_contains($text, 'ending balance') || str_contains($text, 'new balance') || str_contains($text, 'total ending balance'))) {
                $closing = abs(StatementParser::parseAmount(end($amounts)));
                continue;
            }
        }

        // Try summary lines if still missing.
        if ($opening === null || $closing === null) {
            foreach ($lines as $line) {
                if (! isset($stageB['summary_ids'][$line['id']])) {
                    continue;
                }

                $amounts = StatementParser::extractAmounts($line['text']);
                if (count($amounts) >= 2) {
                    $opening = $opening ?? abs(StatementParser::parseAmount($amounts[0]));
                    $closing = $closing ?? abs(StatementParser::parseAmount($amounts[1]));
                }
            }
        }

        return [$opening, $closing];
    }

    private function cleanMerchantText(string $description): string
    {
        $description = trim($description);
        $description = preg_replace('/\s+/', ' ', $description) ?? $description;
        $description = preg_replace('/\b(ref|trace|id)\s*[:#-]?\s*[A-Z0-9-]{6,}\b/i', '', $description) ?? $description;
        $description = preg_replace('/\b\d{8,}\b/', ' ', $description) ?? $description;
        $description = preg_replace('/\s+/', ' ', trim($description)) ?? trim($description);

        return $description;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function textToSyntheticLines(string $text): array
    {
        $parts = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $parts = array_values(array_filter(array_map(fn ($line) => trim((string) $line), $parts), fn ($line) => $line !== ''));

        $lines = [];
        $count = max(count($parts), 1);
        foreach ($parts as $index => $line) {
            $y = min(0.99, (($index + 1) / ($count + 2)));

            $words = [];
            $tokens = preg_split('/\s+/', $line) ?: [];
            $tokenCount = max(count($tokens), 1);
            foreach ($tokens as $tokenIndex => $token) {
                $start = min(0.95, ($tokenIndex / $tokenCount));
                $end = min(0.99, $start + 0.06);
                $words[] = [
                    'text' => $token,
                    'x_min' => $start,
                    'x_max' => $end,
                    'y_min' => $y,
                    'y_max' => min(1.0, $y + 0.01),
                ];
            }

            $lines[] = [
                'id' => (string) Str::uuid(),
                'page' => 1,
                'text' => $line,
                'x_min' => 0.05,
                'x_max' => 0.95,
                'y_min' => $y,
                'y_max' => min(1.0, $y + 0.01),
                'words' => $words,
            ];
        }

        return $lines;
    }
}
