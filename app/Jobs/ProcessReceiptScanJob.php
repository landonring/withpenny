<?php

namespace App\Jobs;

use App\Models\Receipt;
use App\Models\ReceiptText;
use App\Services\Ingestion\ReceiptIngestionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class ProcessReceiptScanJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param array<int, string> $imagePaths Relative paths on public disk.
     */
    public function __construct(
        public readonly int $receiptId,
        public readonly array $imagePaths,
    )
    {
    }

    public function handle(ReceiptIngestionService $ingestion): void
    {
        $receipt = Receipt::query()->find($this->receiptId);
        if (! $receipt) {
            $this->cleanupExtraImages();
            return;
        }

        if (in_array((string) $receipt->processing_status, ['completed', 'failed'], true)) {
            $this->cleanupExtraImages();
            return;
        }

        $receipt->update([
            'processing_status' => 'processing',
            'processing_error' => null,
            'processing_started_at' => now(),
        ]);

        try {
            $absolute = [];
            foreach ($this->imagePaths as $path) {
                if (Storage::disk('public')->exists($path)) {
                    $absolute[] = Storage::disk('public')->path($path);
                }
            }

            $result = $ingestion->processImages($absolute);

            ReceiptText::query()->updateOrCreate(
                ['receipt_id' => $receipt->id],
                ['raw_text' => (string) ($result['raw_text'] ?? '')]
            );

            $receipt->update([
                'processing_status' => 'completed',
                'processing_error' => $result['processing_error'] ?? null,
                'extracted_data' => [
                    'suggestions' => $result['suggestions'] ?? [],
                    'line_items' => $result['line_items'] ?? [],
                    'warnings' => $result['warnings'] ?? [],
                    'raw_text_cached' => null,
                ],
                'confidence_score' => $result['confidence_score'] ?? null,
                'flagged' => (bool) ($result['flagged'] ?? false),
                'category_suggestion' => $result['category_suggestion'] ?? null,
                'category_confidence' => $result['category_confidence'] ?? null,
                'processing_completed_at' => now(),
            ]);
        } catch (\Throwable $error) {
            $receipt->update([
                'processing_status' => 'failed',
                'processing_error' => $error->getMessage(),
                'flagged' => true,
                'processing_completed_at' => now(),
            ]);
        } finally {
            $this->cleanupExtraImages();
        }
    }

    private function cleanupExtraImages(): void
    {
        if (count($this->imagePaths) <= 1) {
            return;
        }

        foreach (array_slice($this->imagePaths, 1) as $path) {
            if ($path !== '') {
                Storage::disk('public')->delete($path);
            }
        }
    }
}
