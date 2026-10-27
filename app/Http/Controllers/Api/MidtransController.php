<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MidtransService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MidtransController extends Controller
{
    public function __construct(private readonly MidtransService $midtrans)
    {
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

        $paid = $transactionStatus === 'settlement'
            || ($transactionStatus === 'capture' && $fraudStatus === 'accept');

        $deposit = DB::table('deposits')->where('order_number', $orderId)->first();

        if ($deposit) {
            if (! $this->amountMatches($grossAmount, (int) $deposit->amount)) {
                return response()->json(['message' => 'Nominal notifikasi tidak sesuai.'], 422);
            }

            $this->applyDepositStatus($deposit, $transactionStatus, $paid);

            return response()->json(['message' => 'ok']);
        }

        $transaction = DB::table('transactions')->where('order_number', $orderId)->first();

        if ($transaction) {
            if (! $this->amountMatches($grossAmount, (int) $transaction->amount)) {
                return response()->json(['message' => 'Nominal notifikasi tidak sesuai.'], 422);
            }

            $this->applyTransactionStatus($transaction, $transactionStatus, $paid);

            return response()->json(['message' => 'ok']);
        }

        return response()->json(['message' => 'Pesanan tidak ditemukan.'], 404);
    }

    private function applyDepositStatus(object $deposit, string $transactionStatus, bool $paid): void
    {
        DB::transaction(function () use ($deposit, $transactionStatus, $paid): void {
            $current = DB::table('deposits')->where('id', $deposit->id)->lockForUpdate()->first();

            // Notifikasi Midtrans bisa dikirim berulang; hanya status pending yang diproses.
            if ($current === null || $current->status !== 'pending') {
                return;
            }

            if ($paid) {
                $user = DB::table('users')->where('id', $current->user_id)->lockForUpdate()->first();
                $balance = (int) $user->balance + (int) $current->amount;

                DB::table('users')->where('id', $user->id)->update(['balance' => $balance]);
                DB::table('deposits')->where('id', $current->id)->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                ]);
                DB::table('balance_entries')->insert([
                    'user_id' => $current->user_id,
                    'direction' => 'credit',
                    'amount' => (int) $current->amount,
                    'balance_after' => $balance,
                    'reference' => $current->order_number,
                    'description' => 'Deposit saldo',
                    'created_at' => now(),
                ]);

                return;
            }

            if ($transactionStatus === 'pending') {
                return;
            }

            DB::table('deposits')->where('id', $current->id)->update([
                'status' => $transactionStatus === 'expire' ? 'expired' : 'canceled',
            ]);
        });
    }

    private function applyTransactionStatus(object $transaction, string $transactionStatus, bool $paid): void
    {
        DB::transaction(function () use ($transaction, $transactionStatus, $paid): void {
            $current = DB::table('transactions')->where('id', $transaction->id)->lockForUpdate()->first();

            if ($current === null || $current->payment_method !== 'midtrans'
                || in_array($current->status, ['success', 'failed'], true)) {
                return;
            }

            if ($paid) {
                DB::table('transactions')->where('id', $current->id)->update([
                    'status' => 'success',
                    'paid_at' => now(),
                ]);

                return;
            }

            if ($transactionStatus === 'pending') {
                return;
            }

            DB::table('transactions')->where('id', $current->id)->update(['status' => 'failed']);
        });
    }

    private function amountMatches(string $grossAmount, int $amount): bool
    {
        return abs((float) $grossAmount - $amount) < 0.01;
    }
}
