<?php

namespace App\Jobs;

use App\Models\BankStatementImport;
use App\Services\Statements\StatementUploadPipelineService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;

class ProcessBankStatementJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $uploadId,
        public readonly bool $runInline = false,
    )
    {
    }

    public function handle(StatementUploadPipelineService $pipeline): void
    {
        $upload = BankStatementImport::query()->find($this->uploadId);
        if (! $upload) {
            return;
        }

        if (in_array((string) $upload->processing_status, ['completed', 'failed'], true)) {
            return;
        }

        $pipeline->begin($upload);

        if (! $pipeline->isPdfOnly($upload)) {
            $pipeline->processLegacyStructuredFiles($upload);

            return;
        }

        if ($this->runInline) {
            $text = $pipeline->extractText($upload);
            if (trim($text) === '') {
                $pipeline->fail($upload, 'Unable to read this statement. Try another PDF.');

                return;
            }

            $normalized = $pipeline->normalizeText($upload);
            if (($normalized['lines'] ?? []) === []) {
                $pipeline->fail($upload, 'We could not find transaction lines in this statement.');

                return;
            }

            $result = $pipeline->parseTransactions($upload);
            if ($result['needs_ai'] ?? false) {
                $transactions = $pipeline->runAiFallback($upload);
                $upload = $upload->fresh() ?? $upload;
                $aiFallbackUsed = (bool) $upload->ai_fallback_used;
                $confidence = $aiFallbackUsed ? 1.0 : (float) ($upload->confidence_score ?? 0.0);

                $pipeline->complete(
                    $upload,
                    $transactions,
                    $confidence,
                    [],
                    $aiFallbackUsed ? 'generic_pdf_ai_fallback' : 'generic_pdf',
                    $aiFallbackUsed,
                );

                return;
            }

            $pipeline->complete(
                $upload,
                $result['transactions'] ?? [],
                (float) ($result['confidence_score'] ?? 0.0),
                [],
                'generic_pdf',
                false,
            );

            return;
        }

        Bus::chain([
            new ExtractTextJob($upload->id),
            new NormalizeTextJob($upload->id),
            new ParseTransactionsJob($upload->id),
            new AIParseFallbackJob($upload->id),
        ])->dispatch();
    }
}
