<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Pricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CatalogController extends Controller
{
    public const CATEGORIES = ['pulsa', 'data', 'pln_token', 'pln_bill', 'ewallet', 'game', 'voucher', 'voucher_inject'];

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

        $accountType = self::accountType($request);

        return response()->json([
            'data' => $rows->map(fn (object $row) => self::serializeProduct($row, $accountType))->all(),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
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

        return response()->json(['data' => self::serializeProduct($product, self::accountType($request))]);
    }

    private static function accountType(Request $request): string
    {
        return (string) ($request->attributes->get('authUser')->account_type ?? 'reseller');
    }

    public static function serializeProduct(object $row, string $accountType = 'reseller'): array
    {
        return [
            'id' => (int) $row->id,
            'sku' => $row->sku,
            'category' => $row->category,
            'operator' => $row->operator,
            'name' => $row->name,
            // `price` adalah harga yang berlaku untuk tipe akun peminta;
            // `retailPrice` tetap harga publik sebagai pembanding.
            'price' => Pricing::unitPrice((int) $row->price, (int) $row->retail_price, $accountType),
            'retailPrice' => (int) ($row->retail_price ?: $row->price),
            'isDemo' => (bool) $row->is_demo,
        ];
    }
}
