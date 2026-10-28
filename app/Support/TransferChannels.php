<?php

namespace App\Support;

/**
 * Daftar tujuan penarikan saldo: rekening bank dan e-wallet. Kode mengikuti
 * kode tujuan yang dipakai layanan pengecekan rekening (Durianpay).
 */
class TransferChannels
{
    public const TYPES = ['bank', 'ewallet'];

    public const BANKS = [
        ['code' => 'bca', 'name' => 'BCA'],
        ['code' => 'bni', 'name' => 'BNI'],
        ['code' => 'bri', 'name' => 'BRI'],
        ['code' => 'mandiri', 'name' => 'Mandiri'],
        ['code' => 'permata', 'name' => 'Permata'],
        ['code' => 'cimb', 'name' => 'CIMB Niaga'],
        ['code' => 'danamon', 'name' => 'Danamon'],
        ['code' => 'btn', 'name' => 'BTN'],
        ['code' => 'bsi', 'name' => 'BSI'],
        ['code' => 'jago', 'name' => 'Bank Jago'],
        ['code' => 'seabank', 'name' => 'SeaBank'],
        ['code' => 'blu', 'name' => 'blu by BCA Digital'],
    ];

    public const EWALLETS = [
        ['code' => 'gopay', 'name' => 'GoPay'],
        ['code' => 'ovo', 'name' => 'OVO'],
        ['code' => 'dana', 'name' => 'DANA'],
        ['code' => 'shopeepay', 'name' => 'ShopeePay'],
        ['code' => 'linkaja', 'name' => 'LinkAja'],
    ];

    /** @return array<int, array{code: string, name: string}> */
    public static function list(string $type): array
    {
        return match ($type) {
            'bank' => self::BANKS,
            'ewallet' => self::EWALLETS,
            default => [],
        };
    }

    public static function find(string $type, string $code): ?array
    {
        foreach (self::list($type) as $channel) {
            if ($channel['code'] === $code) {
                return $channel;
            }
        }

        return null;
    }

    /** @return array{bank: array, ewallet: array} */
    public static function all(): array
    {
        return ['bank' => self::BANKS, 'ewallet' => self::EWALLETS];
    }
}
