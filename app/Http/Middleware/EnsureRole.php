<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Batasi route ke peran tertentu, mis. role:admin,super_admin (B17).
 */
class EnsureRole
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        abort_if($user === null || ! $user->is_active || ! in_array($user->role->value, $roles, true), Response::HTTP_FORBIDDEN);

        return $next($request);
    }
}
