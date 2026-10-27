<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\OrderNumber;
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
        'transactions.amount',
        'transactions.status',
        'transactions.created_at',
        'products.id as product_id',
        'products.name as product_name',
        'products.category',
        'products.operator',
        'products.is_demo',
    ];

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
        $productId = $request->input('productId');
        $customerNumber = trim((string) $request->input('customerNumber'));
        $idempotencyKey = $request->header('Idempotency-Key') ?: null;

        if (! is_numeric($productId) || (int) $productId != $productId) {
            return response()->json(['message' => 'Produk tidak valid.'], 422);
        }

        if (! preg_match('/^\d{6,16}$/', $customerNumber)) {
            return response()->json([
                'message' => 'Nomor tujuan harus 6-16 digit angka.',
                'errors' => ['customerNumber' => ['Nomor tujuan harus 6-16 digit angka.']],
            ], 422);
        }

        if ($idempotencyKey) {
            $previous = $this->findByKey($user->id, $idempotencyKey);
            if ($previous) {
                return response()->json(['data' => ['transaction' => self::serialize($previous)]]);
            }
        }

        try {
            return DB::transaction(function () use ($user, $productId, $customerNumber, $idempotencyKey) {
                $locked = DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
                abort_if($locked === null, 404, 'Pengguna tidak ditemukan.');

                $product = DB::table('products')
                    ->where('id', (int) $productId)
                    ->where('active', true)
                    ->first(['id', 'name', 'price', 'category', 'operator', 'is_demo']);

                if (! $product) {
                    return response()->json(['message' => 'Produk tidak tersedia.'], 404);
                }

                // Atomic charge: the balance check runs in SQL so BIGINT never
                // reaches a floating point comparison.
                $charged = DB::update(
                    'UPDATE users SET balance = balance - ? WHERE id = ? AND balance >= ?',
                    [$product->price, $user->id, $product->price],
                );

                if ($charged === 0) {
                    return response()->json(['message' => 'Saldo tidak cukup. Silakan isi saldo terlebih dahulu.'], 402);
                }

                $balance = (int) DB::table('users')->where('id', $user->id)->value('balance');

                $transaction = OrderNumber::withUnique('TRX', function (string $orderNumber) use ($user, $product, $customerNumber, $idempotencyKey) {
                    $id = DB::table('transactions')->insertGetId([
                        'user_id' => $user->id,
                        'product_id' => $product->id,
                        'order_number' => $orderNumber,
                        'customer_number' => $customerNumber,
                        'amount' => $product->price,
                        'status' => 'success',
                        'idempotency_key' => $idempotencyKey,
                        'created_at' => now(),
                    ]);

                    return DB::table('transactions')->find($id, ['id', 'order_number', 'customer_number', 'amount', 'status', 'created_at']);
                });

                DB::table('balance_entries')->insert([
                    'user_id' => $user->id,
                    'direction' => 'debit',
                    'amount' => $product->price,
                    'balance_after' => $balance,
                    'reference' => $transaction->order_number,
                    'description' => $product->name,
                    'created_at' => now(),
                ]);

                return response()->json([
                    'data' => [
                        'transaction' => self::serialize((object) [
                            'id' => $transaction->id,
                            'order_number' => $transaction->order_number,
                            'customer_number' => $transaction->customer_number,
                            'amount' => $transaction->amount,
                            'status' => $transaction->status,
                            'created_at' => $transaction->created_at,
                            'product_id' => $product->id,
                            'product_name' => $product->name,
                            'category' => $product->category,
                            'operator' => $product->operator,
                            'is_demo' => $product->is_demo,
                        ]),
                        'balance' => $balance,
                    ],
                ], 201);
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $error) {
            if (str_contains($error->getMessage(), 'idempotency')) {
                return response()->json(['message' => 'Transaksi ini sudah diproses.'], 409);
            }

            throw $error;
        }
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
            'amount' => (int) $row->amount,
            'status' => $row->status,
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
