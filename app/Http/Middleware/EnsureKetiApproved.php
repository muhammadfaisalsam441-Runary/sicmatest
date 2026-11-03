<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keti yang belum disetujui atau belum terhubung ke kelas aktif diarahkan ke status pengajuan (B20).
 */
class EnsureKetiApproved
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isApprovedKeti()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'code' => 'ACCOUNT_NOT_APPROVED',
                    'message' => 'Akun Keti belum disetujui atau belum terhubung ke kelas aktif.',
                    'details' => [],
                ], Response::HTTP_FORBIDDEN);
            }

            return redirect()->route('keti.application');
        }

        return $next($request);
    }
}
