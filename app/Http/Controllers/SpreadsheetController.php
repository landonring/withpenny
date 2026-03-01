<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PlanUsageService;
use App\Services\SpreadsheetExportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class SpreadsheetController extends Controller
{
    public function __construct(
        private readonly PlanUsageService $planUsage,
        private readonly SpreadsheetExportService $spreadsheetExport,
    )
    {
    }

    public function generate(Request $request): Response
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
        ]);

        [$startDate, $endDate] = $this->resolveDateRange(
            $request->user(),
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null
        );

        if ($endDate->lt($startDate)) {
            return response()->json([
                'message' => 'End date must be after start date.',
            ], 422);
        }

        $user = $request->user();
        $limit = $this->planUsage->limitState($user, 'spreadsheet_exports');
        if (! $limit['allowed']) {
            $payload = $this->planUsage->limitResponse($user, 'spreadsheet_exports', 'spreadsheet exports');
            if (empty($payload['message'])) {
                $payload['message'] = "You've reached your monthly limit.";
            }
            return response()->json($payload, 403);
        }

        $plan = $this->planUsage->resolvePlan($user);

        try {
            $export = $this->spreadsheetExport->generate(
                $user,
                $startDate,
                $endDate,
                in_array($plan, ['pro', 'premium'], true)
            );
        } catch (\Throwable $exception) {
            Log::warning('spreadsheet_export_failed', [
                'user_id' => $user->id,
                'plan' => $plan,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'We could not generate your spreadsheet right now. Please try again in a moment.',
            ], 500);
        }

        analytics_track('spreadsheet_generated', [
            'user_id' => $user->id,
            'plan_type' => $plan,
            'date_range' => $startDate->toDateString().' to '.$endDate->toDateString(),
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
        ]);

        $fileName = $export['filename'];
        $content = $export['binary'];

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'Content-Length' => (string) strlen($content),
        ]);
    }

    private function resolveDateRange(User $user, ?string $start, ?string $end): array
    {
        if ($start && $end) {
            return [
                Carbon::parse($start)->startOfDay(),
                Carbon::parse($end)->endOfDay(),
            ];
        }

        if (! $start && ! $end) {
            $minDate = $user->transactions()
                ->where(function ($query) {
                    $query->whereNull('source')
                        ->orWhereNotIn('source', ['demo', 'onboarding_demo']);
                })
                ->min('transaction_date');

            $maxDate = $user->transactions()
                ->where(function ($query) {
                    $query->whereNull('source')
                        ->orWhereNotIn('source', ['demo', 'onboarding_demo']);
                })
                ->max('transaction_date');

            if ($minDate && $maxDate) {
                return [
                    Carbon::parse($minDate)->startOfDay(),
                    Carbon::parse($maxDate)->endOfDay(),
                ];
            }
        }

        return [now()->startOfMonth(), now()->endOfMonth()];
    }
}
