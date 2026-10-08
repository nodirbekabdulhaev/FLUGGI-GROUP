<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthTokenController extends Controller
{
    /** POST /api/v1/auth/token — exchange credentials for a Sanctum bearer token. */
    public function store(Request $request)
    {
        $d = $request->validate(['login' => 'required|string|max:120', 'password' => 'required|string', 'device_name' => 'nullable|string|max:60']);
        $key = 'api-login:'.Str::lower($d['login']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['message' => 'Too many attempts'], 429);
        }

        $user = str_contains($d['login'], '@') ? User::where('email', Str::lower($d['login']))->first() : User::where('phone', User::normalizePhone($d['login']))->first();
        if (! $user || ! $user->is_active || ! Hash::check($d['password'], $user->password)) {
            RateLimiter::hit($key, 900);

            return response()->json(['message' => 'Invalid credentials'], 422);
        }
        RateLimiter::clear($key);
        AuditLog::record('api_token_issued', $user);

        return response()->json(['token' => $user->createToken($d['device_name'] ?? 'api')->plainTextToken, 'user' => ['id' => $user->id, 'name' => $user->name]]);
    }

    public function ping()
    {
        return response()->json(['ok' => true, 'time' => now()->toIso8601String()]);
    }

    public function destroy(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'ok']);
    }
}
