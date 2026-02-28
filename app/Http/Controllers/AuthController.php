<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\AnalyticsEvent;
use App\Services\OnboardingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function __construct(private readonly OnboardingService $onboarding)
    {
    }

    private const LIFE_PHASES = [
        'early_builder',
        'foundation',
        'stability',
        'growth',
        'consolidation',
        'preservation',
    ];

    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'age_confirmed' => ['accepted'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);
        $user->last_login_at = now();
        $user->save();

        Auth::login($user, true);
        $request->session()->regenerate();
        $this->onboarding->startIfNeeded($user, $request);

        analytics_track('user_registered');

        return response()->json([
            'user' => $user,
            'csrf_token' => csrf_token(),
        ]);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return response()->json([
                'message' => 'These credentials do not match our records.',
            ], 422);
        }

        $user = $request->user();
        if ($user) {
            $user->last_login_at = now();
            $user->save();
        }

        $request->session()->regenerate();
        if ($user) {
            $this->onboarding->startIfNeeded($user, $request);
        }

        analytics_track('user_logged_in');

        return response()->json([
            'user' => $user,
            'csrf_token' => csrf_token(),
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        if ($user && $user->onboarding_mode) {
            $this->onboarding->cleanup($user, $request, null);
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Logged out.',
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user();
        if ($user) {
            $this->onboarding->startIfNeeded($user, $request);
            $this->onboarding->maybeExpire($user, $request);
            $user->refresh();
        }

        return response()->json([
            'user' => $user,
            'csrf_token' => csrf_token(),
            'impersonating' => $request->session()->has('impersonator_id'),
        ]);
    }

    public function csrf()
    {
        return response()->json([
            'csrf_token' => csrf_token(),
        ]);
    }

    public function profile(Request $request)
    {
        $user = $request->user();
        if ($user) {
            $this->onboarding->maybeExpire($user, $request);
            $user->refresh();
        }

        return response()->json([
            'user' => $user,
            'csrf_token' => csrf_token(),
            'impersonating' => $request->session()->has('impersonator_id'),
        ]);
    }

    public function updateLifePhase(Request $request)
    {
        $validated = $request->validate([
            'life_phase' => ['nullable', 'string', Rule::in(self::LIFE_PHASES)],
        ]);

        $user = $request->user();
        $previous = $user->life_phase;
        $user->life_phase = $validated['life_phase'] ?? null;
        $user->save();

        if ($user->life_phase && $user->life_phase !== $previous) {
            analytics_track('life_phase_selected', [
                'life_phase' => $user->life_phase,
            ]);
        }

        return response()->json([
            'user' => $user,
            'csrf_token' => csrf_token(),
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'current_password' => ['nullable', 'string'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        if (! empty($validated['password'])) {
            if (empty($validated['current_password']) || ! Hash::check($validated['current_password'], $user->password)) {
                return response()->json([
                    'message' => 'Your current password does not match.',
                ], 422);
            }

            $user->password = Hash::make($validated['password']);
        }

        if ($validated['email'] !== $user->email) {
            $user->email = $validated['email'];
        }

        $user->save();

        return response()->json([
            'user' => $user,
            'csrf_token' => csrf_token(),
        ]);
    }

    public function destroy(Request $request)
    {
        $user = $request->user();

        try {
            foreach ($user->subscriptions()->get() as $subscription) {
                if (in_array($subscription->stripe_status, ['active', 'trialing', 'past_due', 'incomplete'], true)) {
                    try {
                        $subscription->cancelNow();
                    } catch (\Throwable $e) {
                        // ignore cancel failure
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore subscription lookup failures
        }

        AnalyticsEvent::query()->where('user_id', $user->id)->delete();
        if (! empty($user->email)) {
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        }

        $receiptPaths = $user->receipts()->pluck('image_path')->all();
        if (! empty($receiptPaths)) {
            Storage::disk('public')->delete($receiptPaths);
        }

        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->flushCredentials();

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $user->delete();

        return response()->json([
            'message' => 'Account deleted.',
        ]);
    }

    public function dataSummary(Request $request)
    {
        $user = $request->user();

        $transactionsTotal = $user->transactions()->count();
        $transactionsImported = $user->transactions()->where('source', 'statement')->count();
        $journeys = $user->savingsJourneys()->count();
        $receipts = $user->receipts()->count();
        $imports = $user->bankStatementImports()->count();

        return response()->json([
            'transactions_total' => $transactionsTotal,
            'transactions_imported' => $transactionsImported,
            'journeys' => $journeys,
            'receipts' => $receipts,
            'statement_imports_pending' => $imports,
        ]);
    }

    public function deleteImportedTransactions(Request $request)
    {
        $deleted = $request->user()->transactions()->where('source', 'statement')->delete();

        return response()->json([
            'deleted' => $deleted,
        ]);
    }

    public function deleteAllTransactions(Request $request)
    {
        $deleted = $request->user()->transactions()->delete();

        return response()->json([
            'deleted' => $deleted,
        ]);
    }
}
