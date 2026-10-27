<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CatalogController extends Controller
{
    public const CATEGORIES = ['pulsa', 'data', 'pln_token', 'pln_bill', 'ewallet', 'game', 'voucher'];

    public function index(Request $request): JsonResponse
    {
        $category = (string) $request->query('category', '');

        if ($category !== '' && ! in_array($category, self::CATEGORIES, true)) {
            return response()->json([
                'message' => 'Kategori tidak dikenal.',
                'errors' => ['category' => ['Kategori tidak dikenal.']],
            ], 422);
        }

        $rows = DB::table('products')
            ->select('id', 'sku', 'category', 'operator', 'name', 'price', 'retail_price', 'is_demo')
            ->where('active', true)
            ->when($category !== '', fn ($query) => $query->where('category', $category))
            ->orderBy('category')
            ->orderBy('operator')
            ->orderBy('sort_order')
            ->orderBy('price')
            ->get();

        return response()->json(['data' => $rows->map([self::class, 'serializeProduct'])->all()]);
    }

    public function show(string $id): JsonResponse
    {
        if (! ctype_digit($id)) {
            return response()->json(['message' => 'Produk tidak valid.'], 422);
        }

        $product = DB::table('products')
            ->select('id', 'sku', 'category', 'operator', 'name', 'price', 'retail_price', 'is_demo')
            ->where('id', (int) $id)
            ->where('active', true)
            ->first();

        if (! $product) {
            return response()->json(['message' => 'Produk tidak ditemukan.'], 404);
        }

        return response()->json(['data' => self::serializeProduct($product)]);
    }

    public static function serializeProduct(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'sku' => $row->sku,
            'category' => $row->category,
            'operator' => $row->operator,
            'name' => $row->name,
            'price' => (int) $row->price,
            'retailPrice' => (int) ($row->retail_price ?: $row->price),
            'isDemo' => (bool) $row->is_demo,
        ];
    }
}
