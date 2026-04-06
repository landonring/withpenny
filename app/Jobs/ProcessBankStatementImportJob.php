<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessBankStatementImportJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param array<int, array{name:string, storage_path:string, mime?:string|null}> $files
     */
    public function __construct(
        public readonly int $importId,
        public readonly array $files = [],
    )
    {
    }

    public function handle(): void
    {
        ProcessBankStatementJob::dispatchSync($this->importId);
    }
}
