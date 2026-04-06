<?php

namespace App\Jobs;

use App\Models\BankStatementImport;
use App\Services\Statements\StatementUploadPipelineService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ParseTransactionsJob implements ShouldQueue
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

        $result = $pipeline->parseTransactions($upload);

        if (! ($result['needs_ai'] ?? false)) {
            $pipeline->complete(
                $upload,
                $result['transactions'] ?? [],
                (float) ($result['confidence_score'] ?? 0.0),
                [],
                'generic_pdf',
                false,
            );
        }
    }
}
