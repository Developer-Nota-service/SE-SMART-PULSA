<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MidtransPaymentStatus;
use App\Services\MidtransService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MidtransController extends Controller
{
    public function __construct(
        private readonly MidtransService $midtrans,
        private readonly MidtransPaymentStatus $status,
    ) {
    }

    /**
     * Webhook notifikasi pembayaran Midtrans (HTTP notification).
     * URL ini didaftarkan di Dashboard Midtrans > Settings > Notification URL.
     */
    public function notification(Request $request): JsonResponse
    {
        if (! $this->midtrans->isConfigured()) {
            return response()->json(['message' => 'Pembayaran Midtrans belum dikonfigurasi.'], 503);
        }

        $orderId = (string) $request->input('order_id', '');
        $statusCode = (string) $request->input('status_code', '');
        $grossAmount = (string) $request->input('gross_amount', '');
        $signature = (string) $request->input('signature_key', '');
        $transactionStatus = (string) $request->input('transaction_status', '');
        $fraudStatus = (string) $request->input('fraud_status', '');

        if ($orderId === '' || $statusCode === '' || $grossAmount === ''
            || ! $this->midtrans->verifySignature($orderId, $statusCode, $grossAmount, $signature)) {
            Log::warning('Notifikasi Midtrans ditolak: signature tidak valid', ['order_id' => $orderId]);

            return response()->json(['message' => 'Signature notifikasi tidak valid.'], 401);
        }

        $result = $this->status->apply($orderId, $transactionStatus, $fraudStatus, $grossAmount);

        return match ($result) {
            MidtransPaymentStatus::OK => response()->json(['message' => 'ok']),
            MidtransPaymentStatus::AMOUNT_MISMATCH => response()->json(['message' => 'Nominal notifikasi tidak sesuai.'], 422),
            default => response()->json(['message' => 'Pesanan tidak ditemukan.'], 404),
        };
    }

    /**
     * Menyelaraskan pesanan yang masih pending dengan status resmi Midtrans.
     *
     * Dipakai halaman Transaksi/Saldo saat dibuka (termasuk setelah kembali dari
     * halaman pembayaran Midtrans) sebagai cadangan bila notifikasi webhook
     * tidak sampai ke server. Status diambil langsung dari API Midtrans dengan
     * Server Key, dan signature pada responsnya wajib valid — sama seperti
     * webhook, saldo tidak pernah bertambah dari permintaan browser sendiri.
     */
    public function sync(Request $request): JsonResponse
    {
        if (! $this->midtrans->isConfigured()) {
            return response()->json(['message' => 'Pembayaran Midtrans belum dikonfigurasi.'], 503);
        }

        $user = $request->attributes->get('authUser');
        $orderNumber = trim((string) $request->input('orderNumber', ''));

        $orders = $this->pendingOrders((int) $user->id, $orderNumber);
        $updated = 0;

        foreach ($orders as $order) {
            $status = $this->midtrans->fetchStatus($order['order_number']);

            if ($status === null) {
                continue;
            }

            $remoteOrderId = (string) ($status['order_id'] ?? '');
            $statusCode = (string) ($status['status_code'] ?? '');
            $grossAmount = (string) ($status['gross_amount'] ?? '');
            $signature = (string) ($status['signature_key'] ?? '');

            // Tanpa signature (mis. HTTP 404) Midtrans tidak mengenal pesanan ini.
            if ($remoteOrderId !== $order['order_number'] || $signature === '') {
                continue;
            }

            if (! $this->midtrans->verifySignature($remoteOrderId, $statusCode, $grossAmount, $signature)) {
                Log::warning('Status Midtrans diabaikan: signature tidak valid', ['order_id' => $order['order_number']]);

                continue;
            }

            $result = $this->status->apply(
                $remoteOrderId,
                (string) ($status['transaction_status'] ?? ''),
                (string) ($status['fraud_status'] ?? ''),
                $grossAmount,
            );

            if ($result !== MidtransPaymentStatus::OK) {
                continue;
            }

            $current = DB::table($order['table'])->where('order_number', $order['order_number'])->value('status');

            if ($current !== $order['status']) {
                $updated++;
            }
        }

        return response()->json(['data' => ['checked' => count($orders), 'updated' => $updated]]);
    }

    /**
     * Pesanan milik pengguna yang masih bisa berubah status.
     *
     * @return list<array{table: string, order_number: string, status: string}>
     */
    private function pendingOrders(int $userId, string $orderNumber): array
    {
        $limit = $orderNumber !== '' ? 1 : 5;

        $deposits = DB::table('deposits')
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->when($orderNumber !== '', fn ($query) => $query->where('order_number', $orderNumber))
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['order_number', 'status']);

        $transactions = DB::table('transactions')
            ->where('user_id', $userId)
            ->where('payment_method', 'midtrans')
            ->where('status', 'pending')
            ->when($orderNumber !== '', fn ($query) => $query->where('order_number', $orderNumber))
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['order_number', 'status']);

        $orders = [];

        foreach ($deposits as $row) {
            $orders[] = [
                'table' => 'deposits',
                'order_number' => (string) $row->order_number,
                'status' => (string) $row->status,
            ];
        }

        foreach ($transactions as $row) {
            $orders[] = [
                'table' => 'transactions',
                'order_number' => (string) $row->order_number,
                'status' => (string) $row->status,
            ];
        }

        return $orders;
    }
}
