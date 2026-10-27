<?php

namespace App\Support;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

class OrderNumber
{
    public static function make(string $prefix): string
    {
        return sprintf('%s-%s-%04d', $prefix, now()->format('Ymd'), random_int(0, 9999));
    }

    /**
     * Runs $insert with a fresh order number until the database accepts it.
     */
    public static function withUnique(string $prefix, callable $insert): mixed
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return $insert(self::make($prefix));
            } catch (UniqueConstraintViolationException $error) {
                if (! Str::contains($error->getMessage(), 'order_number')) {
                    throw $error;
                }
            }
        }

        throw new \RuntimeException('Gagal membuat nomor pesanan unik. Coba lagi.');
    }
}
