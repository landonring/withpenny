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

        $adminEmails = $this->adminEmails();
        $isAdmin = ($user->role === 'admin') || (in_array($user->email, $adminEmails, true));

        if (! $isAdmin) {
            return $this->deny($request);
        }

        return $next($request);
    }

    private function adminEmails(): array
    {
        $raw = (string) config('services.admin.email', '');
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    private function deny(Request $request)
    {
        if ($request->expectsJson() || str_starts_with($request->path(), 'admin/analytics')) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return redirect('/app');
    }
}
