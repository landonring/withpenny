<?php

namespace App\Jobs;

use App\Models\BankStatementImport;
use App\Services\Statements\StatementUploadPipelineService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class NormalizeTextJob implements ShouldQueue
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

        $normalized = $pipeline->normalizeText($upload);
        if (($normalized['lines'] ?? []) === []) {
            $pipeline->fail($upload, 'We could not find transaction lines in this statement.');
        }
    }
}
