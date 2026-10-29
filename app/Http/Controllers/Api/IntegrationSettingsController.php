<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\IntegrationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Pengaturan kredensial integrasi (API key) di konsol admin.
 *
 * Nilai hanya bisa dibaca dalam bentuk tersamarkan dan setiap perubahan wajib
 * menyertakan password admin yang sedang masuk — sesi yang dibajak tidak cukup
 * untuk mengganti kunci pembayaran/supplier.
 */
class IntegrationSettingsController extends Controller
{
    public function __construct(private readonly IntegrationSettings $settings)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->settings->status()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'max:255'],
            'values' => ['required', 'array'],
        ]);

        $user = $request->attributes->get('authUser');
        $hash = $user ? DB::table('users')->where('id', $user->id)->value('password_hash') : null;

        if (! $hash || ! Hash::check($data['password'], $hash)) {
            return response()->json([
                'message' => 'Password admin salah. Perubahan kredensial dibatalkan.',
                'errors' => ['password' => ['Password admin salah.']],
            ], 422);
        }

        $changed = $this->settings->update($data['values'], (int) $user->id);

        return response()->json([
            'data' => [
                'changed' => $changed,
                'settings' => $this->settings->status(),
            ],
        ]);
    }
}
