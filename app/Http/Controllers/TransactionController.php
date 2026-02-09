<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\SavingsContribution;
use Carbon\Carbon;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function index(Request $request)
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

        $transactions = Transaction::query()
            ->where('user_id', $user->id)
            ->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()])
            ->orderByDesc('transaction_date')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'transactions' => $transactions,
            'future_total' => SavingsContribution::query()
                ->where('user_id', $user->id)
                ->whereBetween('contribution_date', [$start->toDateString(), $end->toDateString()])
                ->sum('amount'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'category' => ['required', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
            'transaction_date' => ['nullable', 'date'],
            'type' => ['nullable', 'in:income,spending'],
        ]);

        $validated['transaction_date'] = $validated['transaction_date'] ?? now()->toDateString();
        $validated['type'] = $validated['type'] ?? 'spending';

        $transaction = $request->user()->transactions()->create($validated);

        return response()->json([
            'transaction' => $transaction,
        ], 201);
    }

    public function update(Request $request, Transaction $transaction)
    {
        $this->authorizeTransaction($request, $transaction);

        $validated = $request->validate([
            'amount' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'category' => ['sometimes', 'required', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
            'transaction_date' => ['sometimes', 'nullable', 'date'],
            'type' => ['sometimes', 'nullable', 'in:income,spending'],
        ]);

        $transaction->fill($validated);
        $transaction->save();

        return response()->json([
            'transaction' => $transaction,
        ]);
    }

    public function destroy(Request $request, Transaction $transaction)
    {
        $this->authorizeTransaction($request, $transaction);

        $transaction->delete();

        return response()->json(['status' => 'deleted']);
    }

    private function authorizeTransaction(Request $request, Transaction $transaction): void
    {
        if ($transaction->user_id !== $request->user()->id) {
            abort(403);
        }
    }
}
