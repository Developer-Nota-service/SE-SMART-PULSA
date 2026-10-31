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
        $token = $request->cookie(self::COOKIE) ?: $request->bearerToken();

        if (! $token) {
            return response()->json(['message' => 'Sesi tidak ditemukan. Silakan masuk.'], 401);
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
