<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MidtransPaymentStatus;
use App\Services\MidtransService;
use App\Services\TransactionFulfillment;
use App\Services\TransactionRefund;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Konsol admin: memantau seluruh aktivitas akun pengguna (deposit, transaksi,
 * penarikan) dan menyelesaikan yang bermasalah.
 *
 * Aturan uang tetap sama seperti di sisi pengguna: saldo hanya berubah lewat
 * transaksi DB, dan penarikan yang ditolak mengembalikan saldo lewat entri
 * ledger — bukan dengan menimpa angka saldo.
 */
class AdminController extends Controller
{
    /** Deposit pending lebih tua dari ini dianggap bermasalah (tidak dibayar). */
    private const STALE_DEPOSIT_MINUTES = 30;

    /** Zona waktu laporan penjualan harian (WITA) — hari mengikuti waktu pengelola. */
    private const REPORT_TIMEZONE = 'Asia/Makassar';

    /** Status yang boleh diubah untuk setiap aksi admin penarikan. */
    private const WITHDRAWAL_ACTIONS = [
        'process' => ['from' => ['pending'], 'to' => 'processing'],
        'paid' => ['from' => ['pending', 'processing'], 'to' => 'paid'],
        'reject' => ['from' => ['pending', 'processing'], 'to' => 'rejected'],
    ];

    private const DEPOSIT_STATUSES = ['pending', 'paid', 'canceled', 'expired'];

    private const TRANSACTION_STATUSES = ['success', 'pending', 'failed'];

    public function __construct(
        private readonly MidtransService $midtrans,
        private readonly MidtransPaymentStatus $status,
        private readonly TransactionFulfillment $fulfillment,
        private readonly TransactionRefund $refunds,
    ) {
    }

    public function overview(): JsonResponse
    {
        $staleBefore = now()->subMinutes(self::STALE_DEPOSIT_MINUTES);
        $today = now()->startOfDay();

        $depositsPending = DB::table('deposits')->where('status', 'pending');
        $withdrawalsPending = DB::table('withdrawals')->where('status', 'pending');
        $sales = $this->salesTotals();

        return response()->json([
            'data' => [
                'salesDaily' => $this->dailySales(),
                'counts' => [
                    'withdrawalsPending' => (int) (clone $withdrawalsPending)->count(),
                    'withdrawalsPendingAmount' => (int) (clone $withdrawalsPending)->sum('amount'),
                    'withdrawalsProcessing' => (int) DB::table('withdrawals')->where('status', 'processing')->count(),
                    'withdrawalsUnverified' => (int) DB::table('withdrawals')
                        ->whereIn('status', ['pending', 'processing'])
                        ->where('verification_status', 'unverified')
                        ->count(),
                    'depositsPending' => (int) (clone $depositsPending)->count(),
                    'depositsPendingAmount' => (int) (clone $depositsPending)->sum('amount'),
                    'depositsStale' => (int) (clone $depositsPending)->where('created_at', '<', $staleBefore)->count(),
                    'depositsPaidToday' => (int) DB::table('deposits')
                        ->where('status', 'paid')
                        ->where('paid_at', '>=', $today)
                        ->sum('amount'),
                    'transactionsFailed' => (int) DB::table('transactions')->where('status', 'failed')->count(),
                    'transactionsPending' => (int) DB::table('transactions')->where('status', 'pending')->count(),
                    'transactionsToday' => (int) DB::table('transactions')->where('created_at', '>=', $today)->count(),
                    // Refund yang ditolak Midtrans pada akun pribadi: tidak bisa
                    // masuk saldo, admin harus mengirim dananya manual.
                    'refundsManual' => (int) DB::table('transactions')
                        ->where('status', 'failed')
                        ->where('refund_status', 'failed')
                        ->count(),
                    'users' => (int) DB::table('users')->count(),
                    'balanceTotal' => (int) DB::table('users')->sum('balance'),
                    // Penjualan = transaksi sukses (omzet), bukan deposit.
                    'salesToday' => (int) $sales->today_revenue,
                    'salesTodayOrders' => (int) $sales->today_orders,
                    'salesWeek' => (int) $sales->revenue,
                    'salesWeekOrders' => (int) $sales->orders,
                ],
            ],
        ]);
    }

    /** Omzet transaksi sukses hari ini dan 7 hari terakhir (zona laporan). */
    private function salesTotals(): object
    {
        $start = Carbon::now(self::REPORT_TIMEZONE)->startOfDay()->subDays(6)->utc();
        $todayStart = Carbon::now(self::REPORT_TIMEZONE)->startOfDay()->utc();

        return DB::table('transactions')
            ->where('status', 'success')
            ->where('created_at', '>=', $start)
            ->selectRaw('COALESCE(SUM(amount), 0) AS revenue, COUNT(*) AS orders')
            ->selectRaw('COALESCE(SUM(CASE WHEN created_at >= ? THEN amount ELSE 0 END), 0) AS today_revenue', [$todayStart])
            ->selectRaw('COUNT(CASE WHEN created_at >= ? THEN 1 END) AS today_orders', [$todayStart])
            ->first();
    }

    /** Ringkasan penjualan per hari untuk 7 hari terakhir, termasuk hari tanpa penjualan. */
    private function dailySales(int $days = 7): array
    {
        $start = Carbon::now(self::REPORT_TIMEZONE)->startOfDay()->subDays($days - 1);

        $rows = DB::table('transactions')
            ->where('status', 'success')
            ->where('created_at', '>=', $start->copy()->utc())
            ->selectRaw(
                "to_char(created_at AT TIME ZONE ?, 'YYYY-MM-DD') AS day, COUNT(*) AS orders, COALESCE(SUM(amount), 0) AS revenue",
                [self::REPORT_TIMEZONE],
            )
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $series = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i);
            $key = $date->format('Y-m-d');
            $row = $rows->get($key);

            $series[] = [
                'date' => $key,
                'orders' => (int) ($row->orders ?? 0),
                'revenue' => (int) ($row->revenue ?? 0),
            ];
        }

        return $series;
    }

    public function withdrawals(Request $request): JsonResponse
    {
        $status = (string) $request->query('status', '');
        $allowed = ['pending', 'processing', 'paid', 'rejected'];

        if ($status !== '' && ! in_array($status, $allowed, true)) {
            return response()->json(['message' => 'Status tidak dikenal.'], 422);
        }

        $rows = $this->withdrawalQuery()
            ->when($status !== '', fn ($query) => $query->where('withdrawals.status', $status))
            ->when($status === '', fn ($query) => $query->orderByRaw(
                "CASE withdrawals.status WHEN 'pending' THEN 0 WHEN 'processing' THEN 1 ELSE 2 END",
            ))
            ->orderByDesc('withdrawals.created_at')
            ->orderByDesc('withdrawals.id')
            ->limit(100)
            ->get();

        return response()->json(['data' => $rows->map([self::class, 'serializeWithdrawal'])->all()]);
    }

    /**
     * Memproses pengajuan penarikan: proses → terkirim, atau tolak (saldo
     * dikembalikan). Status final tidak bisa diubah lagi, jadi saldo tidak
     * mungkin kembali dua kali.
     */
    public function updateWithdrawal(Request $request, string $id): JsonResponse
    {
        if (! ctype_digit($id)) {
            return response()->json(['message' => 'Pengajuan tidak valid.'], 422);
        }

        $action = (string) $request->input('action', '');

        if (! isset(self::WITHDRAWAL_ACTIONS[$action])) {
            return response()->json([
                'message' => 'Aksi tidak dikenal.',
                'errors' => ['action' => ['Pilih aksi: process, paid, atau reject.']],
            ], 422);
        }

        $note = trim((string) $request->input('note', ''));

        if (mb_strlen($note) > 200) {
            return response()->json([
                'message' => 'Catatan maksimal 200 karakter.',
                'errors' => ['note' => ['Catatan maksimal 200 karakter.']],
            ], 422);
        }

        $admin = $request->attributes->get('authUser');
        $rule = self::WITHDRAWAL_ACTIONS[$action];

        $outcome = DB::transaction(function () use ($id, $action, $note, $admin, $rule) {
            $row = DB::table('withdrawals')->where('id', (int) $id)->lockForUpdate()->first();

            if (! $row) {
                return ['code' => 404, 'message' => 'Pengajuan penarikan tidak ditemukan.'];
            }

            if (! in_array((string) $row->status, $rule['from'], true)) {
                return [
                    'code' => 409,
                    'message' => 'Pengajuan berstatus '.$row->status.' dan tidak bisa diubah lagi.',
                ];
            }

            $balance = null;

            if ($action === 'reject') {
                // Saldo dikembalikan dengan penambahan atomik + entri ledger,
                // sepasang dengan potongan saat pengajuan dibuat.
                DB::update('UPDATE users SET balance = balance + ? WHERE id = ?', [(int) $row->amount, (int) $row->user_id]);
                $balance = (int) DB::table('users')->where('id', $row->user_id)->value('balance');

                DB::table('balance_entries')->insert([
                    'user_id' => $row->user_id,
                    'direction' => 'credit',
                    'amount' => (int) $row->amount,
                    'balance_after' => $balance,
                    'reference' => $row->reference,
                    'description' => 'Pengembalian saldo: penarikan ditolak',
                    'created_at' => now(),
                ]);
            }

            DB::table('withdrawals')->where('id', $row->id)->update([
                'status' => $rule['to'],
                'note' => $note !== '' ? $note : null,
                'processed_at' => now(),
            ]);

            DB::table('audit_logs')->insert([
                'user_id' => $admin->id,
                'action' => 'admin.withdrawal.'.$action,
                'detail' => json_encode([
                    'reference' => $row->reference,
                    'target_user' => (int) $row->user_id,
                    'amount' => (int) $row->amount,
                    'status' => $rule['to'],
                    'note' => $note !== '' ? $note : null,
                ]),
                'created_at' => now(),
            ]);

            return [
                'code' => 200,
                'withdrawal' => DB::table('withdrawals')->find($row->id),
                'balance' => $balance,
            ];
        });

        if ($outcome['code'] !== 200) {
            return response()->json(['message' => $outcome['message']], $outcome['code']);
        }

        return response()->json([
            'data' => [
                ...self::serializeWithdrawal($this->withdrawalQuery()->where('withdrawals.id', $outcome['withdrawal']->id)->first()),
                'userBalance' => $outcome['balance'],
            ],
        ]);
    }

    public function deposits(Request $request): JsonResponse
    {
        $status = (string) $request->query('status', '');

        if ($status !== '' && ! in_array($status, self::DEPOSIT_STATUSES, true)) {
            return response()->json(['message' => 'Status tidak dikenal.'], 422);
        }

        $staleBefore = now()->subMinutes(self::STALE_DEPOSIT_MINUTES);

        $rows = DB::table('deposits')
            ->join('users', 'users.id', '=', 'deposits.user_id')
            ->when($status !== '', fn ($query) => $query->where('deposits.status', $status))
            ->orderByDesc('deposits.created_at')
            ->orderByDesc('deposits.id')
            ->limit(100)
            ->get([
                'deposits.id',
                'deposits.order_number',
                'deposits.amount',
                'deposits.status',
                'deposits.created_at',
                'deposits.paid_at',
                'users.id as user_id',
                'users.name as user_name',
                'users.email as user_email',
                'users.phone as user_phone',
            ]);

        return response()->json([
            'data' => $rows->map(fn ($row) => self::serializeDeposit($row, $staleBefore))->all(),
        ]);
    }

    /**
     * Menyelaraskan satu deposit dengan status resmi Midtrans. Signature pada
     * respons API Midtrans diverifikasi dulu — sama seperti webhook — sehingga
     * saldo tetap tidak pernah bertambah dari permintaan browser.
     */
    public function syncDeposit(Request $request, string $id): JsonResponse
    {
        if (! ctype_digit($id)) {
            return response()->json(['message' => 'Deposit tidak valid.'], 422);
        }

        $deposit = DB::table('deposits')->find((int) $id, ['id', 'order_number', 'status']);

        if (! $deposit) {
            return response()->json(['message' => 'Deposit tidak ditemukan.'], 404);
        }

        if (! $this->midtrans->isConfigured()) {
            return response()->json(['message' => 'Pembayaran Midtrans belum dikonfigurasi.'], 503);
        }

        $status = $this->midtrans->fetchStatus((string) $deposit->order_number);

        if ($status === null) {
            return response()->json(['message' => 'Status Midtrans tidak tersedia untuk pesanan ini.'], 502);
        }

        $remoteOrderId = (string) ($status['order_id'] ?? '');
        $statusCode = (string) ($status['status_code'] ?? '');
        $grossAmount = (string) ($status['gross_amount'] ?? '');
        $signature = (string) ($status['signature_key'] ?? '');

        // Midtrans menjawab 404 untuk pesanan yang halaman pembayarannya belum
        // pernah dibuka — tidak ada signature yang bisa diverifikasi.
        if ($statusCode === '404') {
            return response()->json([
                'message' => 'Midtrans belum mengenal pesanan ini (pembayaran tidak pernah dibuka). Deposit bisa dibatalkan.',
            ], 409);
        }

        if ($remoteOrderId !== (string) $deposit->order_number || $signature === ''
            || ! $this->midtrans->verifySignature($remoteOrderId, $statusCode, $grossAmount, $signature)) {
            Log::warning('Sinkronisasi deposit admin diabaikan: signature tidak valid', [
                'order_id' => $deposit->order_number,
            ]);

            return response()->json(['message' => 'Status Midtrans tidak bisa diverifikasi.'], 422);
        }

        $result = $this->status->apply(
            $remoteOrderId,
            (string) ($status['transaction_status'] ?? ''),
            (string) ($status['fraud_status'] ?? ''),
            $grossAmount,
        );

        if ($result === MidtransPaymentStatus::AMOUNT_MISMATCH) {
            return response()->json(['message' => 'Nominal pembayaran tidak sesuai pesanan.'], 422);
        }

        $fresh = $this->depositRow((int) $id);

        return response()->json([
            'data' => [
                'deposit' => self::serializeDeposit(
                    $fresh,
                    now()->subMinutes(self::STALE_DEPOSIT_MINUTES),
                ),
                'midtransStatus' => (string) ($status['transaction_status'] ?? ''),
                'changed' => (string) $deposit->status !== (string) $fresh->status,
            ],
        ]);
    }

    /**
     * Membatalkan deposit pending yang tidak akan dibayar. Tidak ada saldo yang
     * berubah karena deposit pending belum pernah menambah saldo.
     */
    public function cancelDeposit(Request $request, string $id): JsonResponse
    {
        if (! ctype_digit($id)) {
            return response()->json(['message' => 'Deposit tidak valid.'], 422);
        }

        $admin = $request->attributes->get('authUser');

        $outcome = DB::transaction(function () use ($id, $admin) {
            $row = DB::table('deposits')->where('id', (int) $id)->lockForUpdate()->first();

            if (! $row) {
                return ['code' => 404, 'message' => 'Deposit tidak ditemukan.'];
            }

            // Deposit yang sudah dibayar tidak boleh dibatalkan — saldo sudah masuk.
            if ((string) $row->status !== 'pending') {
                return ['code' => 409, 'message' => 'Hanya deposit menunggu yang bisa dibatalkan.'];
            }

            DB::table('deposits')->where('id', $row->id)->update(['status' => 'canceled']);

            DB::table('audit_logs')->insert([
                'user_id' => $admin->id,
                'action' => 'admin.deposit.cancel',
                'detail' => json_encode([
                    'order_number' => $row->order_number,
                    'target_user' => (int) $row->user_id,
                    'amount' => (int) $row->amount,
                ]),
                'created_at' => now(),
            ]);

            return ['code' => 200];
        });

        if ($outcome['code'] !== 200) {
            return response()->json(['message' => $outcome['message']], $outcome['code']);
        }

        return response()->json([
            'data' => self::serializeDeposit(
                $this->depositRow((int) $id),
                now()->subMinutes(self::STALE_DEPOSIT_MINUTES),
            ),
        ]);
    }

    /**
     * Menandai refund yang harus dibayar manual (Midtrans menolak, akun pribadi)
     * sudah dikirim ke rekening pengguna. Tanpa ini antrean "Refund manual" tidak
     * pernah kosong walau uangnya sudah dikirim.
     */
    public function markRefundPaid(Request $request, string $id): JsonResponse
    {
        if (! ctype_digit($id)) {
            return response()->json(['message' => 'Transaksi tidak valid.'], 422);
        }

        $note = trim((string) $request->input('note', ''));

        if (mb_strlen($note) > 200) {
            return response()->json([
                'message' => 'Catatan maksimal 200 karakter.',
                'errors' => ['note' => ['Terlalu panjang.']],
            ], 422);
        }

        $admin = $request->attributes->get('authUser');

        $outcome = DB::transaction(function () use ($id, $note, $admin) {
            $row = DB::table('transactions')->where('id', (int) $id)->lockForUpdate()->first();

            if (! $row) {
                return ['code' => 404, 'message' => 'Transaksi tidak ditemukan.'];
            }

            if ((string) $row->refund_status !== 'failed') {
                return ['code' => 409, 'message' => 'Tidak ada refund manual yang menunggu untuk transaksi ini.'];
            }

            DB::table('transactions')->where('id', $row->id)->update([
                'refund_status' => 'manual',
                'refund_message' => $note !== '' ? $note : 'Dana dikirim manual oleh admin',
                'refunded_at' => now(),
            ]);

            DB::table('audit_logs')->insert([
                'user_id' => $admin->id,
                'action' => 'admin.transaction.refund_manual',
                'detail' => json_encode([
                    'order_number' => $row->order_number,
                    'target_user' => (int) $row->user_id,
                    'amount' => (int) $row->amount,
                ]),
                'created_at' => now(),
            ]);

            return ['code' => 200];
        });

        if ($outcome['code'] !== 200) {
            return response()->json(['message' => $outcome['message']], $outcome['code']);
        }

        $row = DB::table('transactions')->where('id', (int) $id)->first();

        return response()->json(['data' => [
            'id' => (int) $row->id,
            'refundStatus' => $row->refund_status,
            'refundMessage' => $row->refund_message,
            'refundedAt' => $row->refunded_at,
        ]]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $status = (string) $request->query('status', '');
        $search = trim((string) $request->query('q', ''));

        if ($status !== '' && ! in_array($status, self::TRANSACTION_STATUSES, true)) {
            return response()->json(['message' => 'Status tidak dikenal.'], 422);
        }

        $rows = DB::table('transactions')
            ->join('users', 'users.id', '=', 'transactions.user_id')
            ->leftJoin('products', 'products.id', '=', 'transactions.product_id')
            ->when($status !== '', fn ($query) => $query->where('transactions.status', $status))
            ->when($search !== '', fn ($query) => $query->where(function ($inner) use ($search) {
                $inner->where('transactions.order_number', 'ilike', '%'.$search.'%')
                    ->orWhere('transactions.customer_number', 'ilike', '%'.$search.'%');
            }))
            ->orderByDesc('transactions.created_at')
            ->orderByDesc('transactions.id')
            ->limit(100)
            ->get([
                'transactions.id',
                'transactions.order_number',
                'transactions.customer_number',
                'transactions.amount',
                'transactions.status',
                'transactions.payment_method',
                'transactions.created_at',
                'transactions.paid_at',
                'transactions.supplier_status',
                'transactions.supplier_sn',
                'transactions.supplier_message',
                'transactions.refund_status',
                'transactions.refund_message',
                'transactions.refunded_at',
                'users.id as user_id',
                'users.name as user_name',
                'users.email as user_email',
                'products.name as product_name',
                'products.category as product_category',
            ]);

        return response()->json(['data' => $rows->map([self::class, 'serializeTransaction'])->all()]);
    }

    /**
     * Kirim ulang / cek status transaksi ke supplier (Digiflazz). Dipakai untuk
     * transaksi `pending` yang webhook-nya belum sampai.
     */
    public function checkTransaction(string $id): JsonResponse
    {
        if (! ctype_digit($id)) {
            return response()->json(['message' => 'Transaksi tidak valid.'], 422);
        }

        if (! DB::table('transactions')->where('id', (int) $id)->exists()) {
            return response()->json(['message' => 'Transaksi tidak ditemukan.'], 404);
        }

        if (! $this->fulfillment->isConfigured()) {
            return response()->json(['message' => 'Digiflazz belum dikonfigurasi.'], 503);
        }

        $this->fulfillment->fulfill((int) $id);

        // Transaksi yang gagal di supplier: coba ulang pengembalian dananya
        // (mis. percobaan refund Midtrans sebelumnya error).
        $failed = DB::table('transactions')->where('id', (int) $id)->first();

        if ($failed !== null && $failed->status === 'failed') {
            $this->refunds->refundFailed($failed);
        }

        $row = DB::table('transactions')->where('id', (int) $id)->first([
            'id',
            'status',
            'supplier_status',
            'supplier_sn',
            'supplier_message',
            'refund_status',
            'refund_message',
        ]);

        return response()->json(['data' => [
            'id' => (int) $row->id,
            'status' => $row->status,
            'supplierStatus' => $row->supplier_status,
            'serialNumber' => $row->supplier_sn,
            'supplierMessage' => $row->supplier_message,
            'refundStatus' => $row->refund_status,
            'refundMessage' => $row->refund_message,
        ]]);
    }

    public function users(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));

        $rows = DB::table('users')
            ->when($search !== '', fn ($query) => $query->where(function ($inner) use ($search) {
                $inner->where('name', 'ilike', '%'.$search.'%')
                    ->orWhere('email', 'ilike', '%'.$search.'%')
                    ->orWhere('phone', 'ilike', '%'.$search.'%');
            }))
            ->orderByDesc('users.created_at')
            ->orderByDesc('users.id')
            ->limit(100)
            ->get([
                'users.id',
                'users.name',
                'users.email',
                'users.phone',
                'users.role',
                'users.account_type',
                'users.balance',
                'users.email_verified_at',
                'users.phone_verified_at',
                'users.created_at',
                DB::raw('(SELECT COUNT(*) FROM transactions WHERE transactions.user_id = users.id) as transactions_count'),
                DB::raw('(SELECT COUNT(*) FROM deposits WHERE deposits.user_id = users.id) as deposits_count'),
                DB::raw('(SELECT COUNT(*) FROM withdrawals WHERE withdrawals.user_id = users.id) as withdrawals_count'),
                DB::raw("(SELECT MAX(created_at) FROM audit_logs WHERE audit_logs.user_id = users.id AND audit_logs.action = 'auth.login') as last_login_at"),
            ]);

        return response()->json(['data' => $rows->map([self::class, 'serializeUser'])->all()]);
    }

    /** Daftar produk beserta harga jual — dasar halaman "Harga" konsol admin. */
    public function products(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));
        $category = (string) $request->query('category', '');

        if ($category !== '' && ! in_array($category, CatalogController::CATEGORIES, true)) {
            return response()->json(['message' => 'Kategori tidak dikenal.'], 422);
        }

        $rows = DB::table('products')
            ->when($category !== '', fn ($query) => $query->where('category', $category))
            ->when($search !== '', fn ($query) => $query->where(function ($inner) use ($search) {
                $inner->where('name', 'ilike', '%'.$search.'%')
                    ->orWhere('sku', 'ilike', '%'.$search.'%')
                    ->orWhere('operator', 'ilike', '%'.$search.'%');
            }))
            ->orderBy('category')
            ->orderBy('operator')
            ->orderBy('sort_order')
            ->orderBy('price')
            ->limit(500)
            ->get(['id', 'sku', 'category', 'operator', 'name', 'price', 'retail_price', 'active', 'is_demo']);

        return response()->json(['data' => $rows->map([self::class, 'serializeAdminProduct'])->all()]);
    }

    /**
     * Ubah harga reseller, harga publik, atau status tayang satu produk.
     * Harga publik tidak boleh turun di bawah harga reseller agar akun pribadi
     * tidak membayar lebih murah daripada reseller.
     */
    public function updateProduct(Request $request, string $id): JsonResponse
    {
        if (! ctype_digit($id)) {
            return response()->json(['message' => 'Produk tidak valid.'], 422);
        }

        $product = DB::table('products')->where('id', (int) $id)->first();

        if ($product === null) {
            return response()->json(['message' => 'Produk tidak ditemukan.'], 404);
        }

        $data = $request->validate([
            'price' => ['sometimes', 'integer', 'min:0'],
            'retailPrice' => ['sometimes', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $price = (int) ($data['price'] ?? $product->price);
        $retail = (int) ($data['retailPrice'] ?? $product->retail_price);

        if ($retail > 0 && $retail < $price) {
            return response()->json([
                'message' => 'Harga publik tidak boleh lebih rendah dari harga reseller.',
                'errors' => ['retailPrice' => ['Harga publik tidak boleh lebih rendah dari harga reseller.']],
            ], 422);
        }

        DB::table('products')->where('id', (int) $id)->update([
            'price' => $price,
            'retail_price' => $retail,
            'active' => array_key_exists('active', $data) ? (bool) $data['active'] : (bool) $product->active,
        ]);

        $row = DB::table('products')
            ->where('id', (int) $id)
            ->first(['id', 'sku', 'category', 'operator', 'name', 'price', 'retail_price', 'active', 'is_demo']);

        return response()->json(['data' => self::serializeAdminProduct($row)]);
    }

    /**
     * Naikkan/turunkan harga sekaligus untuk satu kategori (opsional dibatasi
     * satu operator). Harga publik ikut bergeser agar selisihnya tetap.
     */
    public function bulkPrice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', 'in:'.implode(',', CatalogController::CATEGORIES)],
            'operator' => ['nullable', 'string', 'max:80'],
            'mode' => ['required', 'in:percent,amount'],
            'value' => ['required', 'numeric', 'between:-1000000,1000000'],
        ]);

        $operator = trim((string) ($data['operator'] ?? ''));

        $rows = DB::table('products')
            ->where('category', $data['category'])
            ->when($operator !== '', fn ($query) => $query->where('operator', 'ilike', $operator))
            ->get(['id', 'price', 'retail_price']);

        if ($rows->isEmpty()) {
            return response()->json(['message' => 'Tidak ada produk untuk filter itu.'], 422);
        }

        $mode = (string) $data['mode'];
        $value = (float) $data['value'];

        $updated = DB::transaction(function () use ($rows, $mode, $value): int {
            $count = 0;

            foreach ($rows as $row) {
                $price = self::shiftPrice((int) $row->price, $mode, $value);
                $retail = (int) $row->retail_price > 0
                    ? self::shiftPrice((int) $row->retail_price, $mode, $value)
                    : 0;

                if ($retail > 0 && $retail < $price) {
                    $retail = $price;
                }

                DB::table('products')->where('id', $row->id)->update([
                    'price' => $price,
                    'retail_price' => $retail,
                ]);

                $count++;
            }

            return $count;
        });

        return response()->json(['data' => ['updated' => $updated]]);
    }

    private static function shiftPrice(int $price, string $mode, float $value): int
    {
        $next = $mode === 'percent' ? $price * (1 + $value / 100) : $price + $value;

        return max(0, (int) round($next));
    }

    /** Baris deposit beserta pemiliknya, untuk serialisasi tanggapan admin. */
    private function depositRow(int $id): object
    {
        return DB::table('deposits')
            ->join('users', 'users.id', '=', 'deposits.user_id')
            ->where('deposits.id', $id)
            ->first([
                'deposits.id',
                'deposits.order_number',
                'deposits.amount',
                'deposits.status',
                'deposits.created_at',
                'deposits.paid_at',
                'users.id as user_id',
                'users.name as user_name',
                'users.email as user_email',
                'users.phone as user_phone',
            ]);
    }

    private function withdrawalQuery()
    {
        return DB::table('withdrawals')
            ->join('users', 'users.id', '=', 'withdrawals.user_id')
            ->select([
                'withdrawals.id',
                'withdrawals.reference',
                'withdrawals.amount',
                'withdrawals.channel_type',
                'withdrawals.provider_code',
                'withdrawals.provider_name',
                'withdrawals.account_number',
                'withdrawals.account_holder',
                'withdrawals.verification_status',
                'withdrawals.status',
                'withdrawals.note',
                'withdrawals.created_at',
                'withdrawals.processed_at',
                'users.id as user_id',
                'users.name as user_name',
                'users.email as user_email',
                'users.phone as user_phone',
                'users.balance as user_balance',
            ]);
    }

    public static function serializeWithdrawal(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'reference' => $row->reference,
            'amount' => (int) $row->amount,
            'channelType' => $row->channel_type,
            'providerCode' => $row->provider_code,
            'providerName' => $row->provider_name,
            'accountNumber' => $row->account_number,
            'accountHolder' => $row->account_holder,
            'verificationStatus' => $row->verification_status,
            'status' => $row->status,
            'note' => $row->note,
            'createdAt' => $row->created_at,
            'processedAt' => $row->processed_at,
            'user' => [
                'id' => (int) $row->user_id,
                'name' => $row->user_name,
                'email' => $row->user_email,
                'phone' => $row->user_phone,
                'balance' => (int) ($row->user_balance ?? 0),
            ],
        ];
    }

    public static function serializeDeposit(object $row, Carbon $staleBefore): array
    {
        $createdAt = $row->created_at ? Carbon::parse($row->created_at) : null;
        $pending = (string) $row->status === 'pending';

        return [
            'id' => (int) $row->id,
            'orderNumber' => $row->order_number,
            'amount' => (int) $row->amount,
            'status' => $row->status,
            'createdAt' => $row->created_at,
            'paidAt' => $row->paid_at,
            'ageMinutes' => $createdAt ? (int) $createdAt->diffInMinutes(now()) : 0,
            'stale' => $pending && $createdAt !== null && $createdAt->lessThan($staleBefore),
            'user' => [
                'id' => (int) $row->user_id,
                'name' => $row->user_name,
                'email' => $row->user_email,
                'phone' => $row->user_phone,
            ],
        ];
    }

    public static function serializeAdminProduct(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'sku' => $row->sku,
            'category' => $row->category,
            'operator' => $row->operator,
            'name' => $row->name,
            'price' => (int) $row->price,
            // Harga publik kosong (0) berarti mengikuti harga reseller.
            'retailPrice' => (int) ($row->retail_price ?: $row->price),
            'active' => (bool) $row->active,
            'isDemo' => (bool) $row->is_demo,
        ];
    }

    public static function serializeTransaction(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'orderNumber' => $row->order_number,
            'customerNumber' => $row->customer_number,
            'amount' => (int) $row->amount,
            'status' => $row->status,
            'paymentMethod' => $row->payment_method ?? 'balance',
            'createdAt' => $row->created_at,
            'paidAt' => $row->paid_at,
            'productName' => $row->product_name,
            'productCategory' => $row->product_category,
            'supplierStatus' => $row->supplier_status ?? null,
            'serialNumber' => $row->supplier_sn ?? null,
            'supplierMessage' => $row->supplier_message ?? null,
            'refundStatus' => $row->refund_status ?? null,
            'refundMessage' => $row->refund_message ?? null,
            'refundedAt' => $row->refunded_at ?? null,
            'user' => [
                'id' => (int) $row->user_id,
                'name' => $row->user_name,
                'email' => $row->user_email,
            ],
        ];
    }

    public static function serializeUser(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'name' => $row->name,
            'email' => $row->email,
            'phone' => $row->phone,
            'role' => $row->role,
            'accountType' => $row->account_type ?? 'reseller',
            'balance' => (int) $row->balance,
            'emailVerified' => ($row->email_verified_at ?? null) !== null,
            'phoneVerified' => ($row->phone_verified_at ?? null) !== null,
            'createdAt' => $row->created_at,
            'lastLoginAt' => $row->last_login_at ?? null,
            'counts' => [
                'transactions' => (int) $row->transactions_count,
                'deposits' => (int) $row->deposits_count,
                'withdrawals' => (int) $row->withdrawals_count,
            ],
        ];
    }
}
