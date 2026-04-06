<?php

namespace App\Jobs;

use App\Models\BankStatementImport;
use App\Services\Statements\StatementUploadPipelineService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AIParseFallbackJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $uploadId)
    {
    }

    public function handle(StatementUploadPipelineService $pipeline): void
    {
        $upload = BankStatementImport::query()->find($this->uploadId);
        if (! $upload || (string) $upload->processing_status === 'failed') {
            return;
        }

        $analysis = is_array($upload->meta['analysis'] ?? null) ? $upload->meta['analysis'] : [];
        $needsAi = (bool) ($analysis['needs_ai'] ?? false);

        if (! $needsAi) {
            return;
        }

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
    }
}
