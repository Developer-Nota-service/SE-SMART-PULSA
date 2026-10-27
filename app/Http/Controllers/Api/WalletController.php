<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MidtransService;
use App\Support\OrderNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WalletController extends Controller
{
    private const MIN_DEPOSIT = 10000;

    private const MAX_DEPOSIT = 2000000;

    public function __construct(private readonly MidtransService $midtrans)
    {
    }

    public function entries(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authUser');

        $rows = DB::table('balance_entries')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get(['id', 'direction', 'amount', 'balance_after', 'reference', 'description', 'created_at']);

        return response()->json([
            'data' => $rows->map(fn ($row) => [
                'id' => (int) $row->id,
                'direction' => $row->direction,
                'amount' => (int) $row->amount,
                'balanceAfter' => (int) $row->balance_after,
                'reference' => $row->reference,
                'description' => $row->description,
                'createdAt' => $row->created_at,
            ])->all(),
        ]);
    }

    public function deposits(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authUser');

        $rows = DB::table('deposits')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'order_number', 'amount', 'status', 'created_at', 'paid_at']);

        return response()->json(['data' => $rows->map([self::class, 'serializeDeposit'])->all()]);
    }

    /**
     * Creates a pending deposit only. The balance is credited exclusively by a
     * verified payment notification, never from the browser.
     */
    public function storeDeposit(Request $request): JsonResponse
    {
        $amount = $request->input('amount');

        if (! is_numeric($amount) || (int) $amount != $amount
            || (int) $amount < self::MIN_DEPOSIT || (int) $amount > self::MAX_DEPOSIT) {
            return response()->json([
                'message' => 'Nominal deposit harus antara Rp'.number_format(self::MIN_DEPOSIT, 0, ',', '.').' dan Rp'.number_format(self::MAX_DEPOSIT, 0, ',', '.').'.',
                'errors' => ['amount' => ['Nominal deposit di luar batas yang diizinkan.']],
            ], 422);
        }

        $user = $request->attributes->get('authUser');

        $deposit = OrderNumber::withUnique('DEP', function (string $orderNumber) use ($user, $amount) {
            $id = DB::table('deposits')->insertGetId([
                'user_id' => $user->id,
                'order_number' => $orderNumber,
                'amount' => (int) $amount,
                'status' => 'pending',
                'created_at' => now(),
            ]);

            return DB::table('deposits')
                ->select('id', 'order_number', 'amount', 'status', 'created_at', 'paid_at')
                ->find($id);
        });

        $payment = null;
        $paymentError = null;

        try {
            $payment = $this->midtrans->createSnapPayment(
                $deposit->order_number,
                (int) $deposit->amount,
                'Deposit saldo '.number_format((int) $deposit->amount, 0, ',', '.'),
                ['name' => $user->name, 'email' => $user->email, 'phone' => $user->phone],
            );
        } catch (\Throwable $error) {
            // Pesanan tetap tersimpan sebagai pending; saldo hanya bertambah
            // lewat notifikasi Midtrans yang signature-nya sah.
            $paymentError = $error->getMessage();
        }

        return response()->json([
            'data' => [
                ...self::serializeDeposit($deposit),
                'payment' => $payment,
                'paymentError' => $paymentError,
            ],
        ], 201);
    }

    public static function serializeDeposit(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'orderNumber' => $row->order_number,
            'amount' => (int) $row->amount,
            'status' => $row->status,
            'createdAt' => $row->created_at,
            'paidAt' => $row->paid_at,
        ];
    }
}
