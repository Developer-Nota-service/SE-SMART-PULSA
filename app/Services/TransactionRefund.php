<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Refund transaksi Midtrans yang gagal di supplier.
 *
 * Saldo, ledger, dan status refund diselesaikan dalam satu transaksi database:
 * proses yang mati di tengah tidak dapat meninggalkan kredit tanpa status
 * settlement, dan webhook berulang tidak dapat mengkreditkan dua kali.
 */
class TransactionRefund
{
    public function refundFailed(object $transaction): void
    {
        if ((string) ($transaction->payment_method ?? '') !== 'midtrans'
            || ($transaction->paid_at ?? null) === null) {
            return;
        }

        $transactionId = (int) $transaction->id;

        try {
            DB::transaction(function () use ($transactionId): void {
                $current = DB::table('transactions')
                    ->where('id', $transactionId)
                    ->lockForUpdate()
                    ->first();

                if ($current === null
                    || $current->payment_method !== 'midtrans'
                    || $current->paid_at === null
                    || ! in_array($current->refund_status, [null, 'failed'], true)) {
                    return;
                }

                DB::table('transactions')->where('id', $transactionId)->update([
                    'refund_status' => 'processing',
                    'refund_message' => null,
                ]);

                $user = DB::table('users')
                    ->where('id', $current->user_id)
                    ->lockForUpdate()
                    ->first();

                if ($user === null) {
                    DB::table('transactions')->where('id', $transactionId)->update([
                        'refund_status' => 'failed',
                        'refund_message' => 'Pengguna tidak ditemukan; perlu pemeriksaan admin.',
                    ]);

                    return;
                }

                // Nominal transaksi dan biaya pembayaran yang benar-benar ditagihkan.
                $refundAmount = (int) $current->amount + (int) ($current->fee_amount ?? 0);
                $balance = (int) $user->balance + $refundAmount;

                DB::table('users')->where('id', $user->id)->update(['balance' => $balance]);

                DB::table('balance_entries')->insert([
                    'user_id' => $user->id,
                    'direction' => 'credit',
                    'amount' => $refundAmount,
                    'balance_after' => $balance,
                    'reference' => $current->order_number,
                    'description' => 'Pengembalian dana: transaksi gagal di supplier',
                    'created_at' => now(),
                ]);

                DB::table('transactions')->where('id', $transactionId)->update([
                    'refund_status' => 'balance',
                    'refund_message' => 'Dana dikembalikan ke saldo akun.',
                    'refunded_at' => now(),
                ]);
            }, 3);
        } catch (\Throwable $error) {
            Log::error('Pengembalian dana ke saldo gagal.', [
                'transaction_id' => $transactionId,
                'error' => $error->getMessage(),
            ]);

            // Transaksi kredit di atas sudah rollback. Tandai agar admin dapat
            // mencoba ulang atau menyelesaikan pengembalian secara manual.
            DB::table('transactions')
                ->where('id', $transactionId)
                ->whereIn('refund_status', [null, 'failed', 'processing'])
                ->update([
                    'refund_status' => 'failed',
                    'refund_message' => 'Kredit saldo gagal; perlu pemeriksaan admin.',
                ]);
        }
    }
}
