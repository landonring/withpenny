<?php

namespace App\Jobs;

use App\Models\BankStatementImport;
use App\Services\Statements\StatementUploadPipelineService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExtractTextJob implements ShouldQueue
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

        $text = $pipeline->extractText($upload);
        if (trim($text) === '') {
            $pipeline->fail($upload, 'Unable to read this statement. Try another PDF.');
        }
    }
}
