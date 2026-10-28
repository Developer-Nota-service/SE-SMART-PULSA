<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pengembalian dana transaksi berbayar Midtrans yang gagal di supplier.
 *
 * Alurnya: minta refund ke Midtrans (dana kembali ke metode pembayaran asal).
 * Bila Midtrans menolak — metode seperti VA/transfer bank memang tidak
 * mendukung refund — nominal transaksi dikreditkan ke saldo pengguna lewat
 * `balance_entries`, sehingga uang tetap kembali dan bisa ditarik lewat menu
 * Penarikan.
 *
 * Kredit saldo hanya untuk akun **reseller**. Akun pribadi tidak memakai saldo
 * dan tidak bisa menariknya, jadi refund yang ditolak Midtrans ditandai
 * `refund_status = failed` agar admin mengembalikan dananya secara manual ke
 * rekening pengguna.
 *
 * Transaksi saldo (reseller) tidak disentuh di sini; pengembaliannya sudah
 * dilakukan `TransactionFulfillment::refundBalance()`. Kolom `refund_status`
 * membuat proses ini idempoten: webhook/cek berulang tidak pernah membayar dua
 * kali.
 */
class TransactionRefund
{
    public function __construct(private readonly MidtransService $midtrans)
    {
    }

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

        $result = $this->midtrans->refund(
            (string) $transaction->order_number,
            (int) $transaction->amount,
            'Transaksi gagal diproses supplier',
        );

        if ($result['ok']) {
            $this->settle($transaction->id, 'midtrans', $result['message']);

            return;
        }

        // Kredit saldo hanya untuk akun reseller: akun pribadi tidak memakai
        // saldo, jadi uang yang masuk ke sana tidak bisa dipakai/ditarik.
        if ($this->isReseller((int) $transaction->user_id) && $this->creditBalance($transaction->id)) {
            $this->settle($transaction->id, 'balance', $result['message']);

            return;
        }

        DB::table('transactions')->where('id', $transaction->id)->update([
            'refund_status' => 'failed',
            'refund_message' => 'Perlu pengembalian manual ke rekening pengguna: '.$result['message'],
        ]);
    }

    private function isReseller(int $userId): bool
    {
        return (string) DB::table('users')->where('id', $userId)->value('account_type') === 'reseller';
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
     * Kredit saldo pengguna sebagai cadangan bila refund Midtrans tidak bisa.
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

                $balance = (int) $user->balance + (int) $current->amount;

                DB::table('users')->where('id', $user->id)->update(['balance' => $balance]);

                DB::table('balance_entries')->insert([
                    'user_id' => $user->id,
                    'direction' => 'credit',
                    'amount' => (int) $current->amount,
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
