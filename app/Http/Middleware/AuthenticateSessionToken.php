<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateSessionToken
{
    public const COOKIE = 'sesp_session';

    /**
     * Resolves the session token from the cookie or the Bearer header and
     * attaches the authenticated user to the request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = $request->bearerToken();
        $cookieToken = $request->cookie(self::COOKIE);
        $token = $bearerToken ?: $cookieToken;

        if (! $token) {
            return response()->json(['message' => 'Sesi tidak ditemukan. Silakan masuk.'], 401);
        }

        // Cookie is ambient browser authority. For unsafe cookie-authenticated
        // requests, require a same-origin Origin header to prevent CSRF.
        if (! $bearerToken && $cookieToken && ! $request->isMethodSafe()) {
            $origin = $request->headers->get('Origin');
            $originHost = is_string($origin) ? parse_url($origin, PHP_URL_HOST) : null;
            $originScheme = is_string($origin) ? parse_url($origin, PHP_URL_SCHEME) : null;

            if (
                ! is_string($originHost)
                || ! is_string($originScheme)
                || ! hash_equals(strtolower($request->getHost()), strtolower($originHost))
                || ! hash_equals(strtolower($request->getScheme()), strtolower($originScheme))
            ) {
                return response()->json(['message' => 'Permintaan lintas asal ditolak.'], 403);
            }
        }

        $user = DB::table('auth_sessions')
            ->join('users', 'users.id', '=', 'auth_sessions.user_id')
            ->where('auth_sessions.token_hash', hash('sha256', $token))
            ->where('auth_sessions.expires_at', '>', now())
            ->select('users.id', 'users.name', 'users.email', 'users.phone', 'users.role', 'users.account_type', 'users.balance', 'users.email_verified_at', 'users.phone_verified_at', 'users.avatar_path')
            ->first();

        if (! $user) {
            return response()->json(['message' => 'Sesi telah berakhir. Silakan masuk lagi.'], 401);
        }

        $request->attributes->set('authUser', $user);
        $request->attributes->set('sessionTokenHash', hash('sha256', $token));

        return $next($request);
    }
}
