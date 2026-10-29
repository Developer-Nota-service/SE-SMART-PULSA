<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Kode promo yang bisa dipakai pembeli di halaman beli.
 *
 * Promo diatur admin di konsol Beranda (`promos`): `discount_percent` adalah
 * persentase potongan dari harga yang berlaku untuk akun pembeli. Kode hanya
 * dianggap sah bila promo aktif, punya kode, potongannya > 0, dan belum lewat
 * `valid_until`. Nominal akhir selalu dihitung server.
 */
class PromoCode
{
    /** Batas potongan supaya satu kode tidak menghabiskan harga produk. */
    public const MAX_PERCENT = 90;

    public static function normalize(mixed $code): string
    {
        // Kode boleh mengandung spasi (mis. "HEMAT 10"); spasi berlebih dirapikan.
        $code = preg_replace('/\s+/', ' ', strtoupper(trim((string) $code)));

        return $code ?? '';
    }

    /** Promo aktif yang cocok dengan kode, atau null bila tidak berlaku. */
    public static function find(string $code): ?object
    {
        $code = self::normalize($code);

        if ($code === '') {
            return null;
        }

        return DB::table('promos')
            ->whereRaw('UPPER(code) = ?', [$code])
            ->where('active', true)
            ->where('discount_percent', '>', 0)
            ->where(fn ($query) => $query
                ->whereNull('valid_until')
                ->orWhere('valid_until', '>=', now()->toDateString()))
            ->orderByDesc('discount_percent')
            ->first(['id', 'code', 'title', 'discount_percent']);
    }

    /**
     * Potongan dan total akhir untuk satu harga produk.
     *
     * @return array{code: string, title: string, discountPercent: int, discount: int, total: int}
     */
    public static function apply(object $promo, int $price): array
    {
        $percent = max(0, min(self::MAX_PERCENT, (int) $promo->discount_percent));
        $discount = (int) floor($price * $percent / 100);

        return [
            'code' => (string) $promo->code,
            'title' => (string) $promo->title,
            'discountPercent' => $percent,
            'discount' => $discount,
            'total' => max(0, $price - $discount),
        ];
    }
}
