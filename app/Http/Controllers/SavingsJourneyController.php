<?php

namespace App\Http\Controllers;

use App\Models\SavingsJourney;
use App\Models\SavingsContribution;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

    private function authorizeJourney(Request $request, SavingsJourney $journey): void
    {
        if ($journey->user_id !== $request->user()->id) {
            abort(403);
        }
    }
}
