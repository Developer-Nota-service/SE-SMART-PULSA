<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pemberitahuan akun untuk lonceng di header.
 *
 * Isinya diturunkan dari data yang sudah ada, bukan tabel notifikasi terpisah:
 * transaksi yang gagal (beserta alasan supplier dan status pengembalian dana)
 * dan pengajuan penarikan saldo. Waktu kejadian dipakai sebagai penanda baca:
 * pengguna melihat titik merah selama ada kejadian yang lebih baru dari
 * `users.notifications_read_at`.
 *
 * Efek sampingnya disengaja: ketika pengembalian dana selesai (`refunded_at`
 * terisi), notifikasi yang sama naik ke atas dan kembali belum dibaca — pembeli
 * diberi tahu saat uangnya benar-benar sudah masuk saldo.
 */
class NotificationController extends Controller
{
    private const LIMIT = 30;

    public function index(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authUser');
        $readAt = DB::table('users')->where('id', $user->id)->value('notifications_read_at');
        $readTimestamp = $readAt !== null ? (int) strtotime((string) $readAt) : null;

        $items = array_merge(
            $this->transactionItems((int) $user->id),
            $this->withdrawalItems((int) $user->id),
        );

        usort($items, fn (array $a, array $b): int => $b['sort'] <=> $a['sort']);

        $items = array_slice($items, 0, self::LIMIT);
        $unreadCount = 0;

        foreach ($items as &$item) {
            $item['unread'] = $readTimestamp === null || $item['sort'] > $readTimestamp;

            if ($item['unread']) {
                $unreadCount++;
            }

            unset($item['sort']);
        }
        unset($item);

        return response()->json([
            'data' => [
                'items' => $items,
                'unreadCount' => $unreadCount,
            ],
        ]);
    }

    /**
     * Tandai semua pemberitahuan sudah dibaca (titik merah hilang).
     */
    public function read(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authUser');

        DB::table('users')->where('id', $user->id)->update([
            'notifications_read_at' => now(),
        ]);

        return response()->json(['data' => ['unreadCount' => 0]]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function transactionItems(int $userId): array
    {
        $rows = DB::table('transactions')
            ->join('products', 'products.id', '=', 'transactions.product_id')
            ->where('transactions.user_id', $userId)
            ->where('transactions.status', 'failed')
            ->orderByDesc('transactions.id')
            ->limit(self::LIMIT)
            ->get([
                'transactions.id',
                'transactions.order_number',
                'transactions.customer_number',
                'transactions.amount',
                'transactions.payment_method',
                'transactions.supplier_status',
                'transactions.supplier_message',
                'transactions.refund_status',
                'transactions.refunded_at',
                'transactions.fulfilled_at',
                'transactions.created_at',
                'products.name as product_name',
            ]);

        $items = [];

        foreach ($rows as $row) {
            $supplierFailed = (string) ($row->supplier_status ?? '') === 'failed';
            $at = $row->refunded_at ?? $row->fulfilled_at ?? $row->created_at;
            $reason = trim((string) ($row->supplier_message ?? ''));

            if ($supplierFailed) {
                $title = 'Transaksi gagal';
                $body = sprintf(
                    '%s untuk %s gagal diproses supplier: %s %s',
                    $row->product_name,
                    $row->customer_number,
                    $reason !== '' ? rtrim($reason, '. ').'.' : 'tanpa keterangan dari supplier.',
                    $this->refundText($row),
                );
                $tone = $this->refundTone($row);
            } else {
                $title = 'Pembayaran tidak selesai';
                $body = sprintf(
                    'Pesanan %s untuk %s tidak dibayar sampai batas waktu, jadi dibatalkan. Tidak ada dana yang ditarik.',
                    $row->product_name,
                    $row->customer_number,
                );
                $tone = 'muted';
            }

            $items[] = [
                'id' => 'trx-'.$row->id,
                'type' => 'transaction',
                'tone' => $tone,
                'title' => $title,
                'body' => $body,
                'reference' => (string) $row->order_number,
                'href' => '/transaksi',
                'at' => $this->iso($at),
                'sort' => (int) strtotime((string) $at),
            ];
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function withdrawalItems(int $userId): array
    {
        $rows = DB::table('withdrawals')
            ->where('user_id', $userId)
            ->whereIn('status', ['pending', 'processing', 'paid', 'rejected'])
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get([
                'id',
                'reference',
                'amount',
                'status',
                'note',
                'provider_name',
                'account_number',
                'created_at',
                'processed_at',
            ]);

        $items = [];

        foreach ($rows as $row) {
            $amount = $this->money((int) $row->amount);
            $destination = sprintf(
                '%s %s',
                $row->provider_name,
                $this->maskAccount((string) $row->account_number),
            );
            $at = $row->processed_at ?? $row->created_at;

            [$title, $tone, $body] = match ((string) $row->status) {
                'paid' => [
                    'Penarikan sudah dikirim',
                    'success',
                    sprintf('%s sudah dikirim ke %s.', $amount, $destination),
                ],
                'rejected' => [
                    'Penarikan ditolak',
                    'danger',
                    sprintf('%s ke %s ditolak. Saldo %s sudah dikembalikan ke akun Anda.', $amount, $destination, $amount),
                ],
                'processing' => [
                    'Penarikan sedang diproses',
                    'warning',
                    sprintf('%s ke %s sedang diproses admin (1-7 hari kerja).', $amount, $destination),
                ],
                default => [
                    'Penarikan menunggu diproses',
                    'warning',
                    sprintf('%s ke %s sudah diterima dan menunggu diproses admin (1-7 hari kerja).', $amount, $destination),
                ],
            };

            $note = trim((string) ($row->note ?? ''));

            if ($note !== '' && (string) $row->status === 'rejected') {
                $body .= ' Catatan admin: '.$note;
            }

            $items[] = [
                'id' => 'wd-'.$row->id,
                'type' => 'withdrawal',
                'tone' => $tone,
                'title' => $title,
                'body' => $body,
                'reference' => (string) $row->reference,
                'href' => '/saldo',
                'at' => $this->iso($at),
                'sort' => (int) strtotime((string) $at),
            ];
        }

        return $items;
    }

    /**
     * Status uang pembeli setelah transaksi gagal di supplier.
     */
    private function refundText(object $row): string
    {
        $amount = $this->money((int) $row->amount);

        if ((string) ($row->payment_method ?? '') === 'balance') {
            return "Saldo {$amount} sudah dikembalikan otomatis.";
        }

        return match ((string) ($row->refund_status ?? '')) {
            'balance' => "Dana {$amount} sudah masuk ke saldo aplikasi dan bisa ditarik kapan saja.",
            'manual' => "Dana {$amount} sudah dikirim manual ke rekening Anda.",
            'midtrans' => "Dana {$amount} sudah dikembalikan ke metode pembayaran asal.",
            'failed' => "Dana {$amount} belum bisa dikembalikan otomatis; admin akan mengirimnya ke rekening Anda.",
            'processing' => "Pengembalian dana {$amount} sedang diproses.",
            default => "Pengembalian dana {$amount} sedang disiapkan.",
        };
    }

    private function refundTone(object $row): string
    {
        if ((string) ($row->payment_method ?? '') === 'balance') {
            return 'success';
        }

        return match ((string) ($row->refund_status ?? '')) {
            'balance', 'manual', 'midtrans' => 'success',
            'failed' => 'danger',
            default => 'warning',
        };
    }

    private function money(int $amount): string
    {
        return 'Rp'.number_format($amount, 0, ',', '.');
    }

    private function maskAccount(string $account): string
    {
        $digits = preg_replace('/\s+/', '', $account) ?? '';

        if ($digits === '') {
            return '—';
        }

        return '•••• '.substr($digits, -4);
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse((string) $value)->toIso8601String();
    }
}
