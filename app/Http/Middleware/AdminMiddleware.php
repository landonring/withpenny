<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user) {
            return $this->deny($request);
        }

        if (! $this->isAdmin($user->role, $user->email)) {
            return $this->deny($request);
        }

        return $next($request);
    }

    private function isAdmin(?string $role, ?string $email): bool
    {
        $normalizedEmail = strtolower(trim((string) $email));

        return $role === 'admin' || in_array($normalizedEmail, $this->adminEmails(), true);
    }

    private function adminEmails(): array
    {
        $raw = (string) config('services.admin.email', '');
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $value) => strtolower(trim($value)),
            explode(',', $raw)
        )));
    }

    private function deny(Request $request)
    {
        if ($request->expectsJson() || str_starts_with($request->path(), 'admin/analytics')) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return redirect('/app');
    }
}
