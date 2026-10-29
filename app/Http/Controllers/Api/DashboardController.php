<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\DashboardContent;
use Illuminate\Http\JsonResponse;

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
}
