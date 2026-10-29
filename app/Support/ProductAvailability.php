<?php

namespace App\Support;

/**
 * Ketersediaan produk supplier terhadap batas harga modal admin.
 *
 * `products.max_cost_price` adalah harga modal tertinggi yang masih mau
 * dibayar. Bila harga seller Digiflazz pada sinkron terakhir melewati batas
 * itu, produk tetap tayang di katalog tetapi **tidak bisa dibeli** sampai
 * harganya turun lagi — pembeli melihat keterangan "produk sedang gangguan".
 */
class ProductAvailability
{
    public const DISTURBED_MESSAGE = 'Produk sedang gangguan: harga supplier melebihi harga max yang ditetapkan. Silakan pilih produk lain atau coba lagi nanti.';

    public static function isDisturbed(object $product): bool
    {
        $cost = (int) ($product->cost_price ?? 0);
        $max = (int) ($product->max_cost_price ?? 0);

        return $cost > 0 && $max > 0 && $cost > $max;
    }
}
