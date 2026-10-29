<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\DashboardContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Konten beranda untuk pengguna: banner, promo aktif, dan peringkat pembelian
 * yang dihitung otomatis dari transaksi sukses.
 */
class DashboardController extends Controller
{
    public function show(): JsonResponse
    {
        $settings = DashboardContent::settings();

        return response()->json([
            'data' => [
                'banners' => DashboardContent::banners(true),
                'promos' => DashboardContent::promos(true),
                'leaderboard' => DashboardContent::leaderboard($settings),
            ],
        ]);
    }

    /**
     * Menyajikan gambar banner beranda. Nama file acak (32 hex) sehingga URL
     * tidak bisa ditebak; dipakai langsung oleh <img> di aplikasi.
     */
    public function banner(string $file): BinaryFileResponse
    {
        $file = basename($file);

        abort_unless(preg_match('/^[a-f0-9]{32}\.(jpg|jpeg|png|webp)$/', $file) === 1, 404);

        $path = 'banners/'.$file;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
