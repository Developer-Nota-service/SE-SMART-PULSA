<?php

namespace App\Support;

class Pricing
{
    public const ACCOUNT_TYPES = ['reseller', 'personal'];

    /**
     * Harga yang berlaku untuk satu akun: reseller memakai harga saldo
     * (`products.price`), akun pribadi memakai harga publik
     * (`products.retail_price`). Selalu dipanggil di server.
     */
    public static function unitPrice(int $price, int $retailPrice, string $accountType): int
    {
        if ($accountType !== 'personal') {
            return $price;
        }

        return $retailPrice > 0 ? $retailPrice : $price;
    }
}
