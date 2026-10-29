<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pengembalian dana transaksi berbayar Midtrans yang gagal di supplier.
 *
 * Dana pembeli langsung dikreditkan ke saldo aplikasi — berlaku untuk **semua
 * tipe akun**, termasuk akun pribadi yang membayar langsung lewat Midtrans — dan
 * bisa ditarik lewat menu Penarikan. Midtrans tidak dipanggil untuk refund, jadi
 * pengembaliannya selesai saat itu juga dan tidak bergantung pada metode
 * pembayaran yang dipakai (VA/transfer bank tidak mendukung refund Midtrans).
 *
 * Bila kredit saldo gagal, refund ditandai `refund_status = failed` agar admin
 * mengembalikan dananya secara manual ke rekening pengguna. Kolom `refund_status`
 * membuat proses ini idempoten: webhook/cek berulang tidak pernah membayar dua
 * kali.
 *
 * Transaksi saldo (reseller) tidak disentuh di sini; pengembaliannya sudah
 * dilakukan `TransactionFulfillment::refundBalance()`.
 */
class TransactionRefund
{
    /**
     * Kembalikan dana transaksi yang gagal. Aman dipanggil berkali-kali.
     */
    public function refundFailed(object $transaction): void
    {
        if ((string) ($transaction->payment_method ?? '') !== 'midtrans' || ($transaction->paid_at ?? null) === null) {
            return;
        }

        // Klaim baris ini dulu supaya dua proses (webhook + admin) tidak
        // mengembalikan dana bersamaan.
        $claimed = DB::table('transactions')
            ->where('id', $transaction->id)
            ->where(function ($query) {
                $query->whereNull('refund_status')->orWhere('refund_status', 'failed');
            })
            ->update(['refund_status' => 'processing']);

        if ($claimed === 0) {
            return;
        }

        if ($this->creditBalance((int) $transaction->id)) {
            $this->settle((int) $transaction->id, 'balance', 'Dana dikembalikan ke saldo akun.');

            return;
        }

        DB::table('transactions')->where('id', $transaction->id)->update([
            'refund_status' => 'failed',
            'refund_message' => 'Perlu pengembalian manual ke rekening pengguna.',
        ]);
    }

    private function settle(int $transactionId, string $status, string $message): void
    {
        DB::table('transactions')->where('id', $transactionId)->update([
            'refund_status' => $status,
            'refund_message' => $message !== '' ? $message : null,
            'refunded_at' => now(),
        ]);
    }

    /**
     * Kredit saldo pengguna sebesar nominal transaksi.
     */
    private function creditBalance(int $transactionId): bool
    {
        try {
            return DB::transaction(function () use ($transactionId): bool {
                $current = DB::table('transactions')->where('id', $transactionId)->lockForUpdate()->first();

                if ($current === null || $current->refund_status !== 'processing') {
                    return false;
                }

                $user = DB::table('users')->where('id', $current->user_id)->lockForUpdate()->first();

                if ($user === null) {
                    return false;
                }

                // Biaya layanan Midtrans ikut dikembalikan: pembeli sudah membayarnya,
                // jadi transaksi yang gagal tidak boleh menyisakan kerugian fee.
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

                return true;
            });
        } catch (\Throwable $error) {
            Log::error('Pengembalian dana ke saldo gagal: '.$error->getMessage(), [
                'transaction_id' => $transactionId,
            ]);

            return false;
        }
    }
}
