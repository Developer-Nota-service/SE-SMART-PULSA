<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Mengirim transaksi yang sudah dibayar ke Digiflazz dan menerapkan hasilnya.
 *
 * Urutan status: `pending` (dibayar, belum dikirim) → `success` (Sukses) atau
 * `failed` (Gagal). Status `supplier_status` bersifat terminal: begitu `success`
 * atau `failed`, permintaan berikutnya (webhook berulang, cek manual) diabaikan
 * sehingga saldo tidak pernah dikembalikan dua kali.
 *
 * Produk demo (`products.is_demo`) tidak pernah dikirim ke supplier.
 */
class TransactionFulfillment
{
    private const SUPPLIER = 'digiflazz';

    public function __construct(
        private readonly DigiflazzClient $digiflazz,
        private readonly TransactionRefund $refunds,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->digiflazz->isConfigured();
    }

    /**
     * Kirim (atau cek ulang) transaksi ke Digiflazz. Aman dipanggil berkali-kali.
     */
    public function fulfill(int $transactionId): void
    {
        $row = $this->load($transactionId);

        if ($row === null || $row->is_demo || $this->isFinal($row->supplier_status)) {
            return;
        }

        if (! $this->digiflazz->isConfigured()) {
            Log::warning('Transaksi tidak dikirim ke Digiflazz: kredensial belum diisi.', [
                'order_number' => $row->order_number,
            ]);

            return;
        }

        try {
            $result = $this->digiflazz->topup($row->order_number, $row->sku, $row->customer_number);
        } catch (\Throwable $exception) {
            Log::warning('Pengiriman ke Digiflazz gagal: '.$exception->getMessage(), [
                'order_number' => $row->order_number,
            ]);

            $this->recordFailure($transactionId, $exception->getMessage());

            return;
        }

        $this->applyResult($transactionId, $result);
    }

    /**
     * Terapkan payload Digiflazz (dari topup maupun webhook) ke transaksi lokal.
     *
     * @param  array<string, mixed>  $result
     */
    public function applyResult(int $transactionId, array $result): void
    {
        $status = strtolower(trim((string) ($result['status'] ?? '')));
        $message = trim((string) ($result['message'] ?? ''));
        $serial = trim((string) ($result['sn'] ?? ''));

        if ($status === '') {
            return;
        }

        $failed = null;

        DB::transaction(function () use ($transactionId, $status, $message, $serial, &$failed): void {
            $current = DB::table('transactions')->where('id', $transactionId)->lockForUpdate()->first();

            if ($current === null || $this->isFinal($current->supplier_status)) {
                return;
            }

            $base = [
                'supplier' => self::SUPPLIER,
                'supplier_message' => $message !== '' ? $message : null,
            ];

            if ($status === 'sukses') {
                DB::table('transactions')->where('id', $current->id)->update($base + [
                    'supplier_status' => 'success',
                    'supplier_sn' => $serial !== '' ? $serial : null,
                    'status' => 'success',
                    'fulfilled_at' => now(),
                ]);

                return;
            }

            if ($status === 'gagal') {
                DB::table('transactions')->where('id', $current->id)->update($base + [
                    'supplier_status' => 'failed',
                    'status' => 'failed',
                    'fulfilled_at' => now(),
                ]);

                $this->refundBalance($current);

                $failed = $current;

                return;
            }

            // Pending: Digiflazz akan mengirim webhook saat statusnya berubah.
            DB::table('transactions')->where('id', $current->id)->update($base + [
                'supplier_status' => 'pending',
                'status' => 'pending',
            ]);
        });

        // Refund Midtrans memanggil API eksternal; jalankan setelah transaksi DB
        // selesai supaya lock baris tidak ditahan selama request HTTP.
        if ($failed !== null) {
            $this->refunds->refundFailed($failed);
        }
    }

    /**
     * Kegagalan teknis (jaringan/kredensial) belum tentu gagal permanen, jadi
     * transaksi dibiarkan `pending` agar bisa dikirim ulang.
     */
    private function recordFailure(int $transactionId, string $message): void
    {
        DB::table('transactions')
            ->where('id', $transactionId)
            ->whereNull('supplier_status')
            ->update([
                'supplier' => self::SUPPLIER,
                'supplier_message' => $message,
                'status' => 'pending',
            ]);
    }

    /**
     * Kembalikan saldo reseller untuk transaksi yang gagal di supplier.
     * Hanya berlaku untuk pembayaran saldo, dan hanya sekali (penjaga: status
     * supplier sudah `failed` sebelum baris ini dijalankan).
     */
    private function refundBalance(object $transaction): void
    {
        if ((string) $transaction->payment_method !== 'balance') {
            return;
        }

        $user = DB::table('users')->where('id', $transaction->user_id)->lockForUpdate()->first();

        if ($user === null) {
            return;
        }

        $balance = (int) $user->balance + (int) $transaction->amount;

        DB::table('users')->where('id', $user->id)->update(['balance' => $balance]);

        DB::table('balance_entries')->insert([
            'user_id' => $user->id,
            'direction' => 'credit',
            'amount' => (int) $transaction->amount,
            'balance_after' => $balance,
            'reference' => $transaction->order_number,
            'description' => 'Pengembalian saldo: transaksi gagal di supplier',
            'created_at' => now(),
        ]);
    }

    private function load(int $transactionId): ?object
    {
        return DB::table('transactions')
            ->join('products', 'products.id', '=', 'transactions.product_id')
            ->where('transactions.id', $transactionId)
            ->first([
                'transactions.id',
                'transactions.order_number',
                'transactions.customer_number',
                'transactions.supplier_status',
                'products.sku',
                'products.is_demo',
            ]);
    }

    private function isFinal(?string $status): bool
    {
        return in_array($status, ['success', 'failed'], true);
    }
}
