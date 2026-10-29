<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TransactionFulfillment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Webhook Digiflazz (event create/update/ping) untuk transaksi prabayar.
 *
 * Body diverifikasi dengan HMAC-SHA1 (header `X-Hub-Signature: sha1=...`) memakai
 * `DIGIFLAZZ_WEBHOOK_SECRET` yang diatur di menu Atur Koneksi > API > Webhook.
 * Tanpa secret, endpoint menolak semua permintaan — status transaksi tidak boleh
 * bisa diubah pihak lain.
 */
class DigiflazzWebhookController extends Controller
{
    public function __construct(private readonly TransactionFulfillment $fulfillment)
    {
    }

    public function handle(Request $request): JsonResponse
    {
        $secret = trim((string) config('digiflazz.webhook_secret'));

        if ($secret === '') {
            return response()->json(['message' => 'Webhook Digiflazz belum dikonfigurasi.'], 503);
        }

        $expected = 'sha1='.hash_hmac('sha1', $request->getContent(), $secret);

        if (! hash_equals($expected, (string) $request->header('X-Hub-Signature'))) {
            Log::warning('Webhook Digiflazz ditolak: signature tidak valid');

            return response()->json(['message' => 'Signature tidak valid.'], 401);
        }

        if ((string) $request->header('X-Digiflazz-Event') === 'ping') {
            return response()->json(['message' => 'pong']);
        }

        $data = $request->input('data');
        $refId = is_array($data) ? trim((string) ($data['ref_id'] ?? '')) : '';

        if ($refId === '') {
            return response()->json(['message' => 'ref_id tidak ada.'], 422);
        }

        $transaction = DB::table('transactions')->where('order_number', $refId)->value('id');

        // Pesanan dari sistem lain (mis. produk pascabayar) diabaikan, tetap 200
        // supaya Digiflazz tidak mengulang pengiriman tanpa henti.
        if ($transaction === null) {
            return response()->json(['message' => 'ok']);
        }

        $this->fulfillment->applyResult((int) $transaction, $data);

        return response()->json(['message' => 'ok']);
    }
}
