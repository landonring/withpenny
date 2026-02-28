<?php

namespace App\Http\Controllers;

use App\Models\SavingsJourney;
use App\Models\SavingsContribution;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class SavingsJourneyController extends Controller
{
    public function index(Request $request)
    {
        $journeys = SavingsJourney::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'journeys' => $journeys,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'target_amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        if ($this->isEmergencyFund($validated['title']) && (float) $validated['target_amount'] < 20000) {
            return response()->json([
                'message' => 'Emergency fund targets start at $20,000.',
            ], 422);
        }

        $journey = $request->user()->savingsJourneys()->create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'target_amount' => $validated['target_amount'] ?? null,
            'current_amount' => 0,
            'status' => 'active',
        ]);

        return response()->json([
            'journey' => $journey,
        ], 201);
    }

    public function update(Request $request, SavingsJourney $journey)
    {
        $this->authorizeJourney($request, $journey);

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'target_amount' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'status' => ['sometimes', 'required', Rule::in(['active', 'paused', 'completed'])],
        ]);

        $effectiveTitle = $validated['title'] ?? $journey->title;
        $effectiveTarget = $validated['target_amount'] ?? $journey->target_amount;
        if ($this->isEmergencyFund($effectiveTitle) && (float) $effectiveTarget < 20000) {
            return response()->json([
                'message' => 'Emergency fund targets start at $20,000.',
            ], 422);
        }

        $journey->fill($validated);
        if ($journey->target_amount && $journey->current_amount >= $journey->target_amount) {
            $journey->status = 'completed';
        }
        $journey->save();

        return response()->json([
            'journey' => $journey,
        ]);
    }

    public function add(Request $request, SavingsJourney $journey)
    {
        $this->authorizeJourney($request, $journey);

        if ($journey->status !== 'active') {
            return response()->json([
                'message' => 'This journey is paused or complete.',
            ], 422);
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'date' => ['nullable', 'date'],
        ]);

        $contributionDate = $validated['date'] ?? now()->toDateString();

        SavingsContribution::create([
            'user_id' => $request->user()->id,
            'savings_journey_id' => $journey->id,
            'amount' => $validated['amount'],
            'contribution_date' => $contributionDate,
        ]);

        $journey->current_amount = (float) $journey->current_amount + (float) $validated['amount'];
        if ($journey->target_amount && $journey->current_amount >= $journey->target_amount) {
            $journey->status = 'completed';
        }
        $journey->save();

        return response()->json([
            'journey' => $journey,
        ]);
    }

    public function pause(Request $request, SavingsJourney $journey)
    {
        $this->authorizeJourney($request, $journey);
        $journey->status = 'paused';
        $journey->save();

        return response()->json(['journey' => $journey]);
    }

    public function resume(Request $request, SavingsJourney $journey)
    {
        $this->authorizeJourney($request, $journey);
        $journey->status = 'active';
        $journey->save();

        return response()->json(['journey' => $journey]);
    }

    public function complete(Request $request, SavingsJourney $journey)
    {
        $this->authorizeJourney($request, $journey);
        $journey->status = 'completed';
        $journey->save();

        return response()->json(['journey' => $journey]);
    }

    public function destroy(Request $request, SavingsJourney $journey)
    {
        $this->authorizeJourney($request, $journey);
        $journey->delete();

        return response()->json(['status' => 'deleted']);
    }

    public function emergencyTotal(Request $request)
    {
        $user = $request->user();
        $month = $request->query('month');

        if ($month) {
            try {
                $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
            } catch (\Throwable $error) {
                $start = now()->startOfMonth();
            }
        } else {
            $start = now()->startOfMonth();
        }
        $end = (clone $start)->endOfMonth();

        $journeyIds = SavingsJourney::query()
            ->where('user_id', $user->id)
            ->whereRaw('LOWER(title) LIKE ?', ['%emergency fund%'])
            ->where('status', '!=', 'completed')
            ->pluck('id')
            ->all();

        if (empty($journeyIds)) {
            return response()->json(['total' => 0]);
        }

        $total = SavingsContribution::query()
            ->where('user_id', $user->id)
            ->whereIn('savings_journey_id', $journeyIds)
            ->whereBetween('contribution_date', [$start->toDateString(), $end->toDateString()])
            ->sum('amount');

        return response()->json(['total' => (float) $total]);
    }

    private function authorizeJourney(Request $request, SavingsJourney $journey): void
    {
        if ($journey->user_id !== $request->user()->id) {
            abort(403);
        }
    }

    private function isEmergencyFund(string $title): bool
    {
        return str_contains(strtolower(trim($title)), 'emergency fund');
    }
}
