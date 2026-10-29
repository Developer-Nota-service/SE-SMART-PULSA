<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MidtransService;
use App\Support\OrderNumber;
use App\Support\PaymentFee;
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
            ->get(['id', 'order_number', 'amount', 'fee_amount', 'payment_channel', 'status', 'created_at', 'paid_at']);

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

        // Akun pribadi tidak memakai saldo, jadi tidak perlu mengisi saldo.
        if ((string) ($user->account_type ?? 'reseller') !== 'reseller') {
            return response()->json([
                'message' => 'Isi saldo hanya untuk akun reseller. Akun pribadi bertransaksi langsung lewat Midtrans.',
            ], 422);
        }

        // Biaya layanan mengikuti metode yang dipilih; nominalnya dihitung server.
        $channel = trim((string) $request->input('method'));

        if ($channel === '' || ! PaymentFee::exists($channel)) {
            return response()->json([
                'message' => 'Pilih metode pembayaran terlebih dahulu.',
                'errors' => ['method' => ['Metode pembayaran tidak dikenal.']],
            ], 422);
        }

        $fee = PaymentFee::fee((int) $amount, $channel);

        $deposit = OrderNumber::withUnique('DEP', function (string $orderNumber) use ($user, $amount, $fee, $channel) {
            $id = DB::table('deposits')->insertGetId([
                'user_id' => $user->id,
                'order_number' => $orderNumber,
                'amount' => (int) $amount,
                'fee_amount' => $fee,
                'payment_channel' => $channel,
                'status' => 'pending',
                'created_at' => now(),
            ]);

            return DB::table('deposits')
                ->select('id', 'order_number', 'amount', 'fee_amount', 'payment_channel', 'status', 'created_at', 'paid_at')
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
                [
                    'fee' => (int) $deposit->fee_amount,
                    'enabled_payments' => PaymentFee::enabledPayments($channel),
                ],
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
        $fee = (int) ($row->fee_amount ?? 0);

        return [
            'id' => (int) $row->id,
            'orderNumber' => $row->order_number,
            'amount' => (int) $row->amount,
            'feeAmount' => $fee,
            'total' => (int) $row->amount + $fee,
            'paymentChannel' => $row->payment_channel ?? null,
            'status' => $row->status,
            'createdAt' => $row->created_at,
            'paidAt' => $row->paid_at,
        ];
    }
}
