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

    public function __construct(public readonly int $uploadId)
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

        Bus::chain([
            new ExtractTextJob($upload->id),
            new NormalizeTextJob($upload->id),
            new ParseTransactionsJob($upload->id),
            new AIParseFallbackJob($upload->id),
        ])->dispatch();
    }
}
