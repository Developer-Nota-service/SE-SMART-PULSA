<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\PaymentFee;
use Illuminate\Http\JsonResponse;

/**
 * Metode pembayaran Midtrans yang tersedia beserta tarif biaya layanannya
 * (config `midtrans_fees`). Frontend memakai daftar ini untuk pemilih metode
 * dan menampilkan biaya; nominal final tetap dihitung server.
 */
class PaymentController extends Controller
{
    public function methods(): JsonResponse
    {
        return response()->json([
            'data' => [
                'methods' => PaymentFee::channels(),
            ],
        ]);
    }
}
