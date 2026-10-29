<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CostComparison;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Halaman konsol admin "Banding harga modal": harga supplier Digiflazz
 * (tabel `products.cost_price`) diadu dengan daftar harga TriPay.
 */
class AdminCostComparisonController extends Controller
{
    public function index(Request $request, CostComparison $comparison): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:60'],
            'view' => ['nullable', 'in:all,tripay,digiflazz'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        if (! $comparison->isConfigured()) {
            return response()->json([
                'message' => 'TriPay belum dikonfigurasi: isi TRIPAY_API_KEY dari tripay.id → Profile → API & Callback.',
                'data' => ['configured' => false],
            ], 422);
        }

        // Daftar produk TriPay bisa besar; jangan biarkan batas waktu PHP memutus.
        @set_time_limit(60);

        try {
            $report = $comparison->report(
                trim((string) ($data['q'] ?? '')),
                (string) ($data['view'] ?? 'all'),
                (int) ($data['limit'] ?? 200),
            );
        } catch (\Throwable $exception) {
            Log::warning('Gagal menarik harga TriPay: '.$exception->getMessage());

            return response()->json([
                'message' => 'Gagal menarik harga dari TriPay: '.$exception->getMessage(),
                'data' => ['configured' => true],
            ], 502);
        }

        return response()->json(['data' => ['configured' => true] + $report]);
    }
}
