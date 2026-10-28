<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\OrderNumber;
use App\Support\TransferChannels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Penarikan saldo (withdraw) untuk akun reseller.
 *
 * Aturan: kelipatan Rp100.000, minimal Rp100.000, dana diproses 1-7 hari kerja.
 * Saldo dipotong saat pengajuan (atomik, satu transaksi DB dengan
 * balance_entries) supaya saldo yang sama tidak bisa dipakai dua kali.
 *
 * Nama pemilik rekening diisi pengguna dan diperiksa admin saat dana dikirim;
 * tidak ada penyedia pengecekan nama rekening otomatis yang dipakai.
 */
class WithdrawalController extends Controller
{
    public const MIN_AMOUNT = 100000;

    public const STEP_AMOUNT = 100000;

    public const PROCESSING_DAYS = '1-7 hari kerja';

    public function channels(): JsonResponse
    {
        return response()->json([
            'data' => [
                ...TransferChannels::all(),
                'minAmount' => self::MIN_AMOUNT,
                'stepAmount' => self::STEP_AMOUNT,
                'processingDays' => self::PROCESSING_DAYS,
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authUser');

        $rows = DB::table('withdrawals')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json(['data' => $rows->map([self::class, 'serialize'])->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $this->channelInput($request);
        $error = $this->validateChannel($input);

        if ($error !== null) {
            return $error;
        }

        $amount = $request->input('amount');

        if (! is_numeric($amount) || (int) $amount != $amount || (int) $amount < self::MIN_AMOUNT) {
            return $this->unprocessable(
                'Minimal penarikan Rp'.number_format(self::MIN_AMOUNT, 0, ',', '.').'.',
                'amount',
            );
        }

        $amount = (int) $amount;

        if ($amount % self::STEP_AMOUNT !== 0) {
            return $this->unprocessable(
                'Penarikan harus kelipatan Rp'.number_format(self::STEP_AMOUNT, 0, ',', '.').'.',
                'amount',
            );
        }

        $user = $request->attributes->get('authUser');
        $fresh = DB::table('users')->find($user->id);

        // Saldo hanya ada di akun reseller.
        if ((string) ($fresh->account_type ?? 'reseller') !== 'reseller') {
            return $this->unprocessable(
                'Penarikan saldo hanya untuk akun reseller.',
                'amount',
            );
        }

        if ((int) $fresh->balance < $amount) {
            return $this->unprocessable('Saldo tidak cukup untuk penarikan ini.', 'amount');
        }

        $channel = TransferChannels::find($input['channelType'], $input['providerCode']);

        // Nama pemilik rekening diisi pengguna dan dicocokkan admin saat dana
        // dikirim — tidak ada penyedia pengecekan otomatis yang dipakai.
        if ($input['accountHolder'] === '') {
            return $this->unprocessable('Masukkan nama pemilik rekening.', 'accountHolder');
        }

        try {
            $result = DB::transaction(function () use ($user, $fresh, $amount, $channel, $input) {
                $debited = DB::update(
                    'UPDATE users SET balance = balance - ? WHERE id = ? AND balance >= ?',
                    [$amount, $user->id, $amount],
                );

                if ($debited === 0) {
                    return null;
                }

                $balance = (int) DB::table('users')->where('id', $user->id)->value('balance');

                $withdrawal = OrderNumber::withUnique('WD', function (string $reference) use ($user, $amount, $channel, $input) {
                    $id = DB::table('withdrawals')->insertGetId([
                        'user_id' => $user->id,
                        'reference' => $reference,
                        'amount' => $amount,
                        'channel_type' => $input['channelType'],
                        'provider_code' => $channel['code'],
                        'provider_name' => $channel['name'],
                        'account_number' => $input['accountNumber'],
                        'account_holder' => $input['accountHolder'],
                        'verified_holder' => null,
                        'verification_status' => 'unverified',
                        'status' => 'pending',
                        'created_at' => now(),
                    ]);

                    return DB::table('withdrawals')->find($id);
                });

                DB::table('balance_entries')->insert([
                    'user_id' => $user->id,
                    'direction' => 'debit',
                    'amount' => $amount,
                    'balance_after' => $balance,
                    'reference' => $withdrawal->reference,
                    'description' => 'Penarikan saldo ke '.$channel['name'],
                    'created_at' => now(),
                ]);

                DB::table('audit_logs')->insert([
                    'user_id' => $user->id,
                    'action' => 'withdrawal.create',
                    'detail' => json_encode([
                        'reference' => $withdrawal->reference,
                        'amount' => $amount,
                        'provider' => $channel['code'],
                        'verification' => 'unverified',
                    ]),
                    'created_at' => now(),
                ]);

                return ['withdrawal' => $withdrawal, 'balance' => $balance];
            });
        } catch (\Throwable $error) {
            report($error);

            return response()->json(['message' => 'Gagal membuat pengajuan penarikan. Coba lagi.'], 500);
        }

        if ($result === null) {
            return $this->unprocessable('Saldo tidak cukup untuk penarikan ini.', 'amount');
        }

        return response()->json([
            'data' => [
                ...self::serialize($result['withdrawal']),
                'balance' => $result['balance'],
            ],
        ], 201);
    }

    /**
     * @return array{channelType: string, providerCode: string, accountNumber: string, accountHolder: string}
     */
    private function channelInput(Request $request): array
    {
        return [
            'channelType' => strtolower(trim((string) $request->input('channelType'))),
            'providerCode' => strtolower(trim((string) $request->input('providerCode'))),
            'accountNumber' => preg_replace('/\D/', '', (string) $request->input('accountNumber')) ?? '',
            'accountHolder' => trim((string) $request->input('accountHolder')),
        ];
    }

    /**
     * @param  array{channelType: string, providerCode: string, accountNumber: string, accountHolder: string}  $input
     */
    private function validateChannel(array $input): ?JsonResponse
    {
        if (! in_array($input['channelType'], TransferChannels::TYPES, true)) {
            return $this->unprocessable('Pilih jenis tujuan: rekening bank atau e-wallet.', 'channelType');
        }

        if (TransferChannels::find($input['channelType'], $input['providerCode']) === null) {
            return $this->unprocessable('Bank atau e-wallet tujuan tidak dikenal.', 'providerCode');
        }

        if (! preg_match('/^\d{6,20}$/', $input['accountNumber'])) {
            return $this->unprocessable(
                'Nomor rekening/e-wallet harus 6-20 digit angka.',
                'accountNumber',
            );
        }

        if ($input['accountHolder'] === '') {
            return $this->unprocessable('Masukkan nama pemilik rekening.', 'accountHolder');
        }

        if (! preg_match("/^[A-Za-z .'’-]{3,80}$/", $input['accountHolder'])) {
            return $this->unprocessable('Nama pemilik rekening 3-80 huruf.', 'accountHolder');
        }

        return null;
    }

    private function unprocessable(string $message, string $field): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'errors' => [$field => [$message]],
        ], 422);
    }

    public static function serialize(object $row): array
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
            'verifiedHolder' => $row->verified_holder,
            'verificationStatus' => $row->verification_status,
            'status' => $row->status,
            'note' => $row->note,
            'estimate' => self::PROCESSING_DAYS,
            'createdAt' => $row->created_at,
            'processedAt' => $row->processed_at,
        ];
    }
}
