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

    public function __construct(private readonly TransactionFulfillment $fulfillment)
    {
    }

    public function apply(string $orderId, string $transactionStatus, string $fraudStatus, string $grossAmount): string
    {
        $paid = $transactionStatus === 'settlement'
            || ($transactionStatus === 'capture' && $fraudStatus === 'accept');

        $deposit = DB::table('deposits')->where('order_number', $orderId)->first();

        if ($deposit) {
            // Yang ditagih = nilai deposit + biaya layanan metode pembayaran.
            $expected = (int) $deposit->amount + (int) ($deposit->fee_amount ?? 0);

            if (! self::amountMatches($grossAmount, $expected)) {
                return self::AMOUNT_MISMATCH;
            }

            $this->applyDepositStatus($deposit, $transactionStatus, $paid);

            return self::OK;
        }

        $transaction = DB::table('transactions')->where('order_number', $orderId)->first();

        if ($transaction) {
            // Yang ditagih = nilai transaksi + biaya layanan metode pembayaran.
            $expected = (int) $transaction->amount + (int) ($transaction->fee_amount ?? 0);

            if (! self::amountMatches($grossAmount, $expected)) {
                return self::AMOUNT_MISMATCH;
            }

            $this->applyTransactionStatus($transaction, $transactionStatus, $paid);

            return self::OK;
        }

        return self::NOT_FOUND;
    }

    public static function amountMatches(string $grossAmount, int $amount): bool
    {
        if ($amount < 0 || ! preg_match('/^\\d+(?:\\.\\d{1,2})?$/', $grossAmount)) {
            return false;
        }

        [$whole, $fraction] = array_pad(explode('.', $grossAmount, 2), 2, '');

        return (string) (int) $whole === (string) $amount
            && rtrim($fraction, '0') === '';
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
        $needsFulfillment = DB::transaction(function () use ($transaction, $transactionStatus, $paid): bool {
            $current = DB::table('transactions')->where('id', $transaction->id)->lockForUpdate()->first();

            if ($current === null || $current->payment_method !== 'midtrans'
                || in_array($current->status, ['success', 'failed'], true)) {
                return false;
            }

            if ($paid) {
                // Produk supplier dikirim ke Digiflazz setelah lunas; produk demo
                // langsung sukses seperti sebelumnya.
                $isDemo = (bool) DB::table('products')->where('id', $current->product_id)->value('is_demo');

                DB::table('transactions')->where('id', $current->id)->update([
                    'status' => $isDemo ? 'success' : 'pending',
                    'paid_at' => now(),
                ]);

                return ! $isDemo;
            }

            if ($transactionStatus === 'pending') {
                return false;
            }

            DB::table('transactions')->where('id', $current->id)->update(['status' => 'failed']);

            return false;
        });

        if ($needsFulfillment) {
            $this->fulfillment->fulfill((int) $transaction->id);
        }
    }
}
