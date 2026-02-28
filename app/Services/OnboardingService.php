<?php

namespace App\Services;

use App\Models\BankStatementImport;
use App\Models\User;
use Illuminate\Http\Request;

class OnboardingService
{
    public const LAST_STEP = 5;

    private const IDLE_MINUTES = 30;
    private const TOUCH_THROTTLE_SECONDS = 60;
    private const SESSION_LAST_ACTIVE = 'onboarding.last_active_at';
    private const SESSION_CHAT = 'onboarding.demo_chat';
    private const SESSION_IMPORT_ID = 'onboarding.demo_import_id';
    private const SESSION_CONFIRMED = 'onboarding.demo_confirmed_transactions';

    public function shouldStart(User $user): bool
    {
        if (($user->role ?? 'user') !== 'user') {
            return false;
        }

        return ! (bool) $user->onboarding_completed;
    }

    public function startIfNeeded(User $user, Request $request): void
    {
        if (! $this->shouldStart($user)) {
            return;
        }

        if ($user->onboarding_mode && $this->isExpired($user)) {
            $this->cleanup($user, $request, null);
            $user->refresh();
        }

        if (! $user->onboarding_mode) {
            $user->onboarding_mode = true;
            $user->onboarding_step = 0;
            $user->onboarding_started_at = now();
            $user->save();
        }

        $this->touchActivity($user, $request);
    }

    public function maybeExpire(User $user, Request $request): bool
    {
        if (! $user->onboarding_mode) {
            return false;
        }

        if ($this->isExpired($user)) {
            $this->cleanup($user, $request, null);
            return true;
        }

        $this->touchActivity($user, $request);

        return false;
    }

    public function advance(User $user, Request $request): array
    {
        if (! $user->onboarding_mode) {
            return $this->status($user, $request);
        }

        if ((int) $user->onboarding_step === 1 && ! $this->importIdFromSession($request)) {
            return $this->status($user, $request);
        }

        if ((int) $user->onboarding_step === 2 && ! $request->session()->has(self::SESSION_CONFIRMED)) {
            return $this->status($user, $request);
        }

        $nextStep = min(self::LAST_STEP, (int) $user->onboarding_step + 1);
        if ($nextStep >= self::LAST_STEP) {
            $this->finish($user, $request);
            return $this->status($user, $request);
        }

        $user->onboarding_step = $nextStep;
        $user->onboarding_started_at = now();
        $user->save();
        $request->session()->put(self::SESSION_LAST_ACTIVE, now()->timestamp);

        return $this->status($user, $request);
    }

    public function setStep(User $user, int $step, Request $request): array
    {
        if (! $user->onboarding_mode) {
            return $this->status($user, $request);
        }

        $user->onboarding_step = max(0, min(self::LAST_STEP - 1, $step));
        $user->onboarding_started_at = now();
        $user->save();
        $request->session()->put(self::SESSION_LAST_ACTIVE, now()->timestamp);

        return $this->status($user, $request);
    }

    public function finish(User $user, Request $request): void
    {
        $this->cleanup($user, $request, true);
    }

    public function skip(User $user, Request $request): void
    {
        $this->cleanup($user, $request, true);
    }

    public function replay(User $user, Request $request): array
    {
        $this->clearSandboxSession($request);

        $user->onboarding_mode = true;
        $user->onboarding_step = 0;
        $user->onboarding_started_at = now();
        $user->save();

        $request->session()->put(self::SESSION_LAST_ACTIVE, now()->timestamp);

        return $this->status($user, $request);
    }

    public function status(User $user, Request $request): array
    {
        if ($user->onboarding_mode && (int) $user->onboarding_step === 2 && ! $this->importIdFromSession($request)) {
            $user->onboarding_step = 1;
            $user->onboarding_started_at = now();
            $user->save();
        }

        $step = (int) ($user->onboarding_step ?? 0);
        $mode = (bool) ($user->onboarding_mode ?? false);

        return [
            'mode' => $mode,
            'step' => $mode ? $step : 0,
            'completed' => (bool) ($user->onboarding_completed ?? false),
            'started_at' => optional($user->onboarding_started_at)->toIso8601String(),
            'target_path' => $mode ? $this->pathForStep($step, $request) : null,
            'instructions' => $mode ? $this->instructionsForStep($step) : [],
        ];
    }

    public function pathForStep(int $step, Request $request): string
    {
        return match ($step) {
            0 => '/app',
            1 => '/statements/scan',
            2 => $this->reviewPathFromSession($request) ?? '/statements/scan',
            3 => '/insights',
            4 => '/chat',
            default => '/app',
        };
    }

    public function expectedStepMismatch(User $user, int ...$steps): bool
    {
        if (! $user->onboarding_mode) {
            return false;
        }

        return ! in_array((int) $user->onboarding_step, $steps, true);
    }

    public function rememberImportId(Request $request, int $importId): void
    {
        $request->session()->put(self::SESSION_IMPORT_ID, $importId);
    }

    public function forgetImportId(Request $request): void
    {
        $request->session()->forget(self::SESSION_IMPORT_ID);
    }

    public function importIdFromSession(Request $request): ?int
    {
        $id = $request->session()->get(self::SESSION_IMPORT_ID);
        return is_numeric($id) ? (int) $id : null;
    }

    public function storeConfirmedTransactions(Request $request, array $transactions): void
    {
        $request->session()->put(self::SESSION_CONFIRMED, $transactions);
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function chatHistory(Request $request): array
    {
        $history = $request->session()->get(self::SESSION_CHAT, []);
        return is_array($history) ? array_values(array_filter($history, 'is_array')) : [];
    }

    public function appendChatHistory(Request $request, string $userMessage, string $assistantMessage): void
    {
        $history = $this->chatHistory($request);
        $history[] = [
            'user' => $userMessage,
            'assistant' => $assistantMessage,
        ];
        $request->session()->put(self::SESSION_CHAT, array_slice($history, -25));
    }

    public function cleanup(User $user, Request $request, ?bool $markCompleted): void
    {
        $user->onboarding_mode = false;
        $user->onboarding_step = 0;
        $user->onboarding_started_at = null;
        if ($markCompleted !== null) {
            $user->onboarding_completed = $markCompleted;
        }
        $user->save();

        BankStatementImport::query()
            ->where('user_id', $user->id)
            ->where('source', 'onboarding_demo')
            ->delete();

        $this->clearSandboxSession($request);
    }

    public function clearSandboxSession(Request $request): void
    {
        $request->session()->forget([
            self::SESSION_LAST_ACTIVE,
            self::SESSION_CHAT,
            self::SESSION_IMPORT_ID,
            self::SESSION_CONFIRMED,
        ]);
    }

    private function touchActivity(User $user, Request $request): void
    {
        if (! $user->onboarding_mode) {
            return;
        }

        $last = $request->session()->get(self::SESSION_LAST_ACTIVE);
        $now = now()->timestamp;
        if (is_numeric($last) && ($now - (int) $last) < self::TOUCH_THROTTLE_SECONDS) {
            return;
        }

        $user->onboarding_started_at = now();
        $user->save();
        $request->session()->put(self::SESSION_LAST_ACTIVE, $now);
    }

    private function isExpired(User $user): bool
    {
        if (! $user->onboarding_started_at) {
            return false;
        }

        return $user->onboarding_started_at->lt(now()->subMinutes(self::IDLE_MINUTES));
    }

    private function reviewPathFromSession(Request $request): ?string
    {
        $importId = $this->importIdFromSession($request);
        if (! $importId) {
            return null;
        }

        return '/statements/'.$importId.'/review';
    }

    /**
     * @return array<string, string>
     */
    private function instructionsForStep(int $step): array
    {
        return match ($step) {
            0 => [
                'target' => 'dashboard',
                'title' => 'This is your dashboard.',
                'body' => 'You can see a calm summary of money in, spending, and balance before taking any action.',
                'action' => 'Continue',
            ],
            1 => [
                'target' => 'upload',
                'title' => 'Upload a sample statement.',
                'body' => 'Penny extracts transactions directly from your statement so you can review before saving.',
                'action' => 'Continue after upload',
            ],
            2 => [
                'target' => 'review',
                'title' => 'Review each transaction.',
                'body' => 'Check date, description, amount, and category. Confirm only when everything looks correct.',
                'action' => 'Continue after confirm',
            ],
            3 => [
                'target' => 'insights',
                'title' => 'Generate a quick insight.',
                'body' => 'Insights summarize spending patterns and help you decide the next small adjustment.',
                'action' => 'Continue',
            ],
            4 => [
                'target' => 'chat',
                'title' => 'Ask one question in chat.',
                'body' => 'You can ask Penny about spending, categories, or what to do next this week.',
                'action' => 'Finish',
            ],
            default => [
                'target' => 'dashboard',
                'title' => 'Onboarding complete.',
                'body' => 'You can now use Penny normally.',
                'action' => 'Continue',
            ],
        };
    }
}
