<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Menerapkan status pembayaran Midtrans ke pesanan lokal (deposit atau
 * transaksi). Dipakai oleh webhook dan oleh pemeriksaan status langsung ke API
 * Midtrans, jadi aturan transisi status hanya ada di satu tempat.
 */
class MidtransPaymentStatus
{
    public const OK = 'ok';

    public const NOT_FOUND = 'not_found';

    public const AMOUNT_MISMATCH = 'amount_mismatch';

    public function apply(string $orderId, string $transactionStatus, string $fraudStatus, string $grossAmount): string
    {
        $paid = $transactionStatus === 'settlement'
            || ($transactionStatus === 'capture' && $fraudStatus === 'accept');

        $deposit = DB::table('deposits')->where('order_number', $orderId)->first();

        if ($deposit) {
            if (! self::amountMatches($grossAmount, (int) $deposit->amount)) {
                return self::AMOUNT_MISMATCH;
            }

            $this->applyDepositStatus($deposit, $transactionStatus, $paid);

            return self::OK;
        }

        $transaction = DB::table('transactions')->where('order_number', $orderId)->first();

        if ($transaction) {
            if (! self::amountMatches($grossAmount, (int) $transaction->amount)) {
                return self::AMOUNT_MISMATCH;
            }

            $this->applyTransactionStatus($transaction, $transactionStatus, $paid);

            return self::OK;
        }

        return self::NOT_FOUND;
    }

    public static function amountMatches(string $grossAmount, int $amount): bool
    {
        return abs((float) $grossAmount - $amount) < 0.01;
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
}
