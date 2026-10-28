<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MidtransService;
use App\Services\TransactionFulfillment;
use App\Support\OrderNumber;
use App\Support\Pricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
    private const STATUSES = ['success', 'pending', 'failed'];

    private const SELECT_COLUMNS = [
        'transactions.id',
        'transactions.order_number',
        'transactions.customer_number',
        'transactions.voucher_number',
        'transactions.amount',
        'transactions.status',
        'transactions.payment_method',
        'transactions.paid_at',
        'transactions.created_at',
        'transactions.supplier_status',
        'transactions.supplier_sn',
        'transactions.supplier_message',
        'products.id as product_id',
        'products.name as product_name',
        'products.category',
        'products.operator',
        'products.is_demo',
    ];

    public function __construct(
        private readonly MidtransService $midtrans,
        private readonly TransactionFulfillment $fulfillment,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authUser');

        $status = (string) $request->query('status', '');
        $category = (string) $request->query('category', '');
        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', '');

        if ($status !== '' && ! in_array($status, self::STATUSES, true)) {
            return response()->json(['message' => 'Status tidak dikenal.'], 422);
        }

        if ($category !== '' && ! in_array($category, CatalogController::CATEGORIES, true)) {
            return response()->json(['message' => 'Kategori tidak dikenal.'], 422);
        }

        $rows = DB::table('transactions')
            ->join('products', 'products.id', '=', 'transactions.product_id')
            ->where('transactions.user_id', $user->id)
            ->when($status !== '', fn ($query) => $query->where('transactions.status', $status))
            ->when($category !== '', fn ($query) => $query->where('products.category', $category))
            ->when($from !== '', fn ($query) => $query->whereDate('transactions.created_at', '>=', $from))
            ->when($to !== '', fn ($query) => $query->whereDate('transactions.created_at', '<=', $to))
            ->orderByDesc('transactions.created_at')
            ->orderByDesc('transactions.id')
            ->limit(100)
            ->get(self::SELECT_COLUMNS);

        return response()->json(['data' => $rows->map([self::class, 'serialize'])->all()]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        if (! ctype_digit($id)) {
            return response()->json(['message' => 'Transaksi tidak valid.'], 422);
        }

        $user = $request->attributes->get('authUser');

        $row = DB::table('transactions')
            ->join('products', 'products.id', '=', 'transactions.product_id')
            ->where('transactions.id', (int) $id)
            ->where('transactions.user_id', $user->id)
            ->first(self::SELECT_COLUMNS);

        if (! $row) {
            return response()->json(['message' => 'Transaksi tidak ditemukan.'], 404);
        }

        return response()->json(['data' => self::serialize($row)]);
    }

    /**
     * Price, balance and status are decided here; the client only names the
     * product and the destination number.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authUser');

        // Jalur saldo hanya untuk reseller; akun pribadi selalu lewat Midtrans.
        if (self::isPersonal($user)) {
            return response()->json([
                'message' => 'Akun pribadi tidak memakai saldo. Selesaikan transaksi lewat Midtrans.',
                'errors' => ['paymentMethod' => ['Pembayaran saldo hanya untuk akun reseller.']],
            ], 422);
        }

        $productId = $request->input('productId');
        $customerNumber = trim((string) $request->input('customerNumber'));
        $voucherNumber = $this->voucherNumber($request->input('voucherNumber'));
        $idempotencyKey = $request->header('Idempotency-Key') ?: null;

        if (! is_numeric($productId) || (int) $productId != $productId) {
            return response()->json(['message' => 'Produk tidak valid.'], 422);
        }

        $productCategory = $this->productCategory($productId);

        if ($productCategory === null) {
            return response()->json(['message' => 'Produk tidak tersedia.'], 404);
        }

        // Voucher dikirim ke email penerima; voucher inject memakai nomor voucher
        // kosong sebagai tujuan karena itulah data yang dibutuhkan supplier.
        $destinationError = $this->destinationError($productCategory, $customerNumber);

        if ($destinationError !== null) {
            return response()->json([
                'message' => $destinationError,
                'errors' => ['customerNumber' => [$destinationError]],
            ], 422);
        }

        // Voucher inject: nomor voucher kosong adalah tujuan transaksinya.
        if ($productCategory === 'voucher_inject' && $voucherNumber === '') {
            $voucherNumber = $customerNumber;
        }

        $voucherError = $this->voucherNumberError($productCategory, $voucherNumber);

        if ($voucherError !== null) {
            return response()->json([
                'message' => $voucherError,
                'errors' => ['voucherNumber' => [$voucherError]],
            ], 422);
        }

        if ($idempotencyKey) {
            $previous = $this->findByKey($user->id, $idempotencyKey);
            if ($previous) {
                return response()->json(['data' => ['transaction' => self::serialize($previous)]]);
            }
        }

        try {
            $result = DB::transaction(function () use ($user, $productId, $customerNumber, $voucherNumber, $idempotencyKey) {
                $locked = DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
                abort_if($locked === null, 404, 'Pengguna tidak ditemukan.');

                $product = DB::table('products')
                    ->where('id', (int) $productId)
                    ->where('active', true)
                    ->first(['id', 'name', 'price', 'retail_price', 'category', 'operator', 'is_demo']);

                if (! $product) {
                    return response()->json(['message' => 'Produk tidak tersedia.'], 404);
                }

                // Harga mengikuti tipe akun (reseller/pribadi) dan selalu
                // ditentukan server.
                $amount = Pricing::unitPrice(
                    (int) $product->price,
                    (int) $product->retail_price,
                    (string) ($locked->account_type ?? 'reseller'),
                );

                // Atomic charge: the balance check runs in SQL so BIGINT never
                // reaches a floating point comparison.
                $charged = DB::update(
                    'UPDATE users SET balance = balance - ? WHERE id = ? AND balance >= ?',
                    [$amount, $user->id, $amount],
                );

                if ($charged === 0) {
                    return response()->json(['message' => 'Saldo tidak cukup. Silakan isi saldo terlebih dahulu.'], 402);
                }

                $balance = (int) DB::table('users')->where('id', $user->id)->value('balance');

                $transaction = OrderNumber::withUnique('TRX', function (string $orderNumber) use ($user, $product, $customerNumber, $voucherNumber, $idempotencyKey, $amount) {
                    $id = DB::table('transactions')->insertGetId([
                        'user_id' => $user->id,
                        'product_id' => $product->id,
                        'order_number' => $orderNumber,
                        'customer_number' => $customerNumber,
                        'voucher_number' => $voucherNumber ?: null,
                        'amount' => $amount,
                        // Produk nyata (supplier) selesai setelah Digiflazz menjawab;
                        // produk demo langsung sukses seperti sebelumnya.
                        'status' => $product->is_demo ? 'success' : 'pending',
                        'idempotency_key' => $idempotencyKey,
                        'created_at' => now(),
                    ]);

                    return DB::table('transactions')->find($id, ['id', 'order_number', 'customer_number', 'voucher_number', 'amount', 'status', 'created_at']);
                });

                DB::table('balance_entries')->insert([
                    'user_id' => $user->id,
                    'direction' => 'debit',
                    'amount' => $amount,
                    'balance_after' => $balance,
                    'reference' => $transaction->order_number,
                    'description' => $product->name,
                    'created_at' => now(),
                ]);

                return [
                    'transaction' => $transaction,
                    'product' => $product,
                    'balance' => $balance,
                ];
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $error) {
            if (str_contains($error->getMessage(), 'idempotency')) {
                return response()->json(['message' => 'Transaksi ini sudah diproses.'], 409);
            }

            throw $error;
        }

        if ($result instanceof JsonResponse) {
            return $result;
        }

        // Dikirim ke supplier setelah transaksi DB commit, supaya panggilan HTTP
        // tidak menahan lock saldo.
        $this->fulfillment->fulfill((int) $result['transaction']->id);

        $fresh = DB::table('transactions')->where('id', $result['transaction']->id)->first([
            'status',
            'supplier_status',
            'supplier_sn',
            'supplier_message',
        ]);

        $transaction = $result['transaction'];
        $product = $result['product'];

        return response()->json([
            'data' => [
                'transaction' => self::serialize((object) [
                    'id' => $transaction->id,
                    'order_number' => $transaction->order_number,
                    'customer_number' => $transaction->customer_number,
                    'voucher_number' => $transaction->voucher_number,
                    'amount' => $transaction->amount,
                    'status' => $fresh->status ?? $transaction->status,
                    'payment_method' => 'balance',
                    'paid_at' => null,
                    'created_at' => $transaction->created_at,
                    'supplier_status' => $fresh->supplier_status ?? null,
                    'supplier_sn' => $fresh->supplier_sn ?? null,
                    'supplier_message' => $fresh->supplier_message ?? null,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'category' => $product->category,
                    'operator' => $product->operator,
                    'is_demo' => $product->is_demo,
                ]),
                'balance' => $result['balance'],
            ],
        ], 201);
    }

    /**
     * Alur bayar langsung: Midtrans menagih harga tipe akun, saldo tidak dipotong.
     */
    public function direct(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authUser');

        // Reseller wajib mengisi saldo dan bertransaksi memakai saldo.
        if (! self::isPersonal($user)) {
            return response()->json([
                'message' => 'Akun reseller bertransaksi memakai saldo. Silakan isi saldo terlebih dahulu.',
                'errors' => ['paymentMethod' => ['Bayar langsung hanya untuk akun pribadi.']],
            ], 422);
        }

        $productId = $request->input('productId');
        $customerNumber = trim((string) $request->input('customerNumber'));
        $voucherNumber = $this->voucherNumber($request->input('voucherNumber'));

        if (! is_numeric($productId) || (int) $productId != $productId) {
            return response()->json(['message' => 'Produk tidak valid.'], 422);
        }

        $product = DB::table('products')
            ->where('id', (int) $productId)
            ->where('active', true)
            ->first(['id', 'name', 'price', 'retail_price', 'category', 'operator', 'is_demo']);

        if (! $product) {
            return response()->json(['message' => 'Produk tidak tersedia.'], 404);
        }

        $destinationError = $this->destinationError($product->category, $customerNumber);

        if ($destinationError !== null) {
            return response()->json([
                'message' => $destinationError,
                'errors' => ['customerNumber' => [$destinationError]],
            ], 422);
        }

        // Voucher inject: nomor voucher kosong adalah tujuan transaksinya.
        if ($product->category === 'voucher_inject' && $voucherNumber === '') {
            $voucherNumber = $customerNumber;
        }

        $voucherError = $this->voucherNumberError($product->category, $voucherNumber);

        if ($voucherError !== null) {
            return response()->json([
                'message' => $voucherError,
                'errors' => ['voucherNumber' => [$voucherError]],
            ], 422);
        }

        $amount = Pricing::unitPrice(
            (int) $product->price,
            (int) $product->retail_price,
            (string) ($user->account_type ?? 'reseller'),
        );

        $transaction = OrderNumber::withUnique('TRX', function (string $orderNumber) use ($user, $product, $customerNumber, $voucherNumber, $amount) {
            $id = DB::table('transactions')->insertGetId([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'order_number' => $orderNumber,
                'customer_number' => $customerNumber,
                'voucher_number' => $voucherNumber ?: null,
                'amount' => $amount,
                'status' => 'pending',
                'payment_method' => 'midtrans',
                'idempotency_key' => null,
                'created_at' => now(),
            ]);

            return DB::table('transactions')->find($id, [
                'id',
                'order_number',
                'customer_number',
                'voucher_number',
                'amount',
                'status',
                'payment_method',
                'paid_at',
                'created_at',
            ]);
        });

        try {
            $payment = $this->midtrans->createSnapPayment(
                $transaction->order_number,
                $amount,
                $product->name,
                ['name' => $user->name, 'email' => $user->email, 'phone' => $user->phone],
            );
        } catch (\Throwable $error) {
            // Belum ada tagihan yang sah; jangan tinggalkan pesanan pending.
            DB::table('transactions')->where('id', $transaction->id)->delete();

            // 422 (bukan 5xx): gateway dev/preview mengganti body 5xx dengan
            // halaman statusnya sendiri sehingga pesan asli hilang.
            return response()->json(['message' => $error->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'transaction' => self::serialize((object) [
                    'id' => $transaction->id,
                    'order_number' => $transaction->order_number,
                    'customer_number' => $transaction->customer_number,
                    'voucher_number' => $transaction->voucher_number,
                    'amount' => $transaction->amount,
                    'status' => $transaction->status,
                    'payment_method' => $transaction->payment_method,
                    'paid_at' => $transaction->paid_at,
                    'created_at' => $transaction->created_at,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'category' => $product->category,
                    'operator' => $product->operator,
                    'is_demo' => $product->is_demo,
                ]),
                'payment' => $payment,
            ],
        ], 201);
    }

    private static function isPersonal(object $user): bool
    {
        return (string) ($user->account_type ?? 'reseller') === 'personal';
    }

    private function productCategory(mixed $productId): ?string
    {
        return DB::table('products')
            ->where('id', (int) $productId)
            ->where('active', true)
            ->value('category');
    }

    private function destinationError(string $category, string $customerNumber): ?string
    {
        // Voucher inject diisi nomor voucher kosong (dikirim ke supplier sebagai
        // customer_no), bukan email penerima.
        if ($category === 'voucher_inject') {
            return preg_match('/^\d{8,20}$/', $customerNumber)
                ? null
                : 'Nomor voucher kosong harus 8-20 digit angka.';
        }

        if ($category === 'voucher') {
            return filter_var($customerNumber, FILTER_VALIDATE_EMAIL) === false
                ? 'Email penerima voucher tidak valid.'
                : null;
        }

        // Game top up memakai ID game / User ID / username, dan sebagian produk
        // meminta gabungan ID + Zone/Server (mis. "UID|Server"), jadi bukan angka.
        if ($category === 'game') {
            return preg_match('/^[A-Za-z0-9|.()_#\[\] -]{3,60}$/', $customerNumber)
                ? null
                : 'ID game harus 3-60 karakter sesuai petunjuk produk.';
        }

        // Token PLN memakai ID pelanggan / nomor meter (bukan nomor HP).
        if ($category === 'pln_token') {
            return preg_match('/^\d{6,20}$/', $customerNumber)
                ? null
                : 'ID pelanggan/meter harus 6-20 digit angka.';
        }

        return preg_match('/^\d{6,16}$/', $customerNumber) ? null : 'Nomor tujuan harus 6-16 digit angka.';
    }

    /** Nomor voucher kosong hanya berisi angka; spasi/pemisah dibuang. */
    private function voucherNumber(mixed $value): string
    {
        return preg_replace('/\D/', '', (string) $value) ?? '';
    }

    private function voucherNumberError(string $category, string $voucherNumber): ?string
    {
        if ($category !== 'voucher_inject') {
            return null;
        }

        return preg_match('/^\d{8,20}$/', $voucherNumber)
            ? null
            : 'Nomor voucher kosong wajib diisi 8-20 digit angka.';
    }

    private function findByKey(int $userId, string $key): ?object
    {
        return DB::table('transactions')
            ->join('products', 'products.id', '=', 'transactions.product_id')
            ->where('transactions.user_id', $userId)
            ->where('transactions.idempotency_key', $key)
            ->first(self::SELECT_COLUMNS);
    }

    public static function serialize(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'orderNumber' => $row->order_number,
            'customerNumber' => $row->customer_number,
            'voucherNumber' => $row->voucher_number ?? null,
            'amount' => (int) $row->amount,
            'status' => $row->status,
            'paymentMethod' => $row->payment_method ?? 'balance',
            'paidAt' => $row->paid_at ?? null,
            'serialNumber' => $row->supplier_sn ?? null,
            'supplierStatus' => $row->supplier_status ?? null,
            'supplierMessage' => $row->supplier_message ?? null,
            'createdAt' => $row->created_at,
            'product' => [
                'id' => (int) $row->product_id,
                'name' => $row->product_name,
                'category' => $row->category,
                'operator' => $row->operator,
                'isDemo' => (bool) $row->is_demo,
            ],
        ];
    }
}
