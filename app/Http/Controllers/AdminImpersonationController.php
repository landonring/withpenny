<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminImpersonationController extends Controller
{
    public function start(Request $request, User $user): JsonResponse
    {
        $admin = $request->user();

        if (! $admin || ! $this->isAdmin($admin)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($admin->id === $user->id) {
            return response()->json(['message' => 'Cannot impersonate yourself.'], 422);
        }

        $request->session()->put('impersonator_id', $admin->id);

        Auth::login($user, true);
        $request->session()->regenerate();
        $request->session()->put('impersonator_id', $admin->id);

        return response()->json([
            'user' => $user,
            'csrf_token' => csrf_token(),
            'impersonating' => true,
        ]);
    }

    public function stop(Request $request)
    {
        $impersonatorId = $request->session()->pull('impersonator_id');
        if (! $impersonatorId) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Not impersonating.'], 422);
            }
            return redirect('/admin/users');
        }

        $admin = User::query()->where('id', $impersonatorId)->first();
        if (! $admin || ! $this->isAdmin($admin)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
            return redirect('/admin/users');
        }

        Auth::login($admin, true);
        $request->session()->regenerate();

        if ($request->expectsJson()) {
            return response()->json([
                'user' => $admin,
                'csrf_token' => csrf_token(),
                'impersonating' => false,
            ]);
        }

        return redirect('/admin/users');
    }

    private function isAdmin(User $user): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        $email = strtolower(trim((string) $user->email));
        if ($email === '') {
            return false;
        }

        $allowed = array_values(array_filter(array_map(
            static fn (string $value) => strtolower(trim($value)),
            explode(',', (string) config('services.admin.email', ''))
        )));

        return in_array($email, $allowed, true);
    }
}
