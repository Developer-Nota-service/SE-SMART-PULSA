<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Membatasi endpoint konsol admin hanya untuk akun dengan role `admin`.
 * Middleware ini berjalan setelah `session.token`, jadi pengguna sudah ada di
 * atribut request.
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->attributes->get('authUser');

        if (! $user || (string) ($user->role ?? '') !== 'admin') {
            return response()->json(['message' => 'Halaman ini hanya untuk admin.'], 403);
        }

        return $next($request);
    }
}
