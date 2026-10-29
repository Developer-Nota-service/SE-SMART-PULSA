<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\DashboardContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Pengaturan beranda pengguna oleh admin: banner, promo, dan peringkat
 * pembelian otomatis. Isi beranda pengguna dilayani DashboardController.
 */
class AdminDashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $settings = DashboardContent::settings();

        return response()->json([
            'data' => [
                'banners' => DashboardContent::banners(false),
                'promos' => DashboardContent::promos(false),
                'settings' => $settings,
                'leaderboard' => DashboardContent::leaderboard($settings),
            ],
        ]);
    }

    public function storeBanner(Request $request): JsonResponse
    {
        $data = $this->validateBanner($request);

        $id = DB::table('banners')->insertGetId([
            'title' => $data['title'],
            'subtitle' => $data['subtitle'] ?? '',
            'tone' => $data['tone'] ?? 'navy',
            'active' => $data['active'] ?? true,
            'sort_order' => $data['sortOrder'] ?? 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['data' => $this->bannerRow($id)], 201);
    }

    public function updateBanner(Request $request, string $id): JsonResponse
    {
        $row = $this->findOrFail('banners', $id, 'Banner tidak ditemukan.');
        $data = $this->validateBanner($request, true);
        $changes = $this->bannerChanges($data);

        if ($changes !== []) {
            DB::table('banners')->where('id', $row->id)->update($changes + ['updated_at' => now()]);
        }

        return response()->json(['data' => $this->bannerRow((int) $row->id)]);
    }

    public function destroyBanner(string $id): JsonResponse
    {
        $row = $this->findOrFail('banners', $id, 'Banner tidak ditemukan.');
        DB::table('banners')->where('id', $row->id)->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function storePromo(Request $request): JsonResponse
    {
        $data = $this->validatePromo($request);

        $id = DB::table('promos')->insertGetId([
            'title' => $data['title'],
            'description' => $data['description'] ?? '',
            'code' => $data['code'] ?? '',
            'badge' => $data['badge'] ?? '',
            'valid_until' => $data['validUntil'] ?? null,
            'active' => $data['active'] ?? true,
            'sort_order' => $data['sortOrder'] ?? 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['data' => $this->promoRow($id)], 201);
    }

    public function updatePromo(Request $request, string $id): JsonResponse
    {
        $row = $this->findOrFail('promos', $id, 'Promo tidak ditemukan.');
        $data = $this->validatePromo($request, true);
        $changes = [];

        foreach ([
            'title' => 'title',
            'description' => 'description',
            'code' => 'code',
            'badge' => 'badge',
            'validUntil' => 'valid_until',
            'active' => 'active',
            'sortOrder' => 'sort_order',
        ] as $field => $column) {
            if (array_key_exists($field, $data)) {
                $changes[$column] = $data[$field];
            }
        }

        if ($changes !== []) {
            DB::table('promos')->where('id', $row->id)->update($changes + ['updated_at' => now()]);
        }

        return response()->json(['data' => $this->promoRow((int) $row->id)]);
    }

    public function destroyPromo(string $id): JsonResponse
    {
        $row = $this->findOrFail('promos', $id, 'Promo tidak ditemukan.');
        DB::table('promos')->where('id', $row->id)->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** Pengaturan peringkat pembelian di beranda (dan pratinjaunya). */
    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'leaderboardEnabled' => ['sometimes', 'boolean'],
            'leaderboardLimit' => ['sometimes', 'integer', 'in:'.implode(',', DashboardContent::LIMITS)],
            'leaderboardPeriodDays' => ['sometimes', 'integer', 'in:'.implode(',', DashboardContent::PERIODS)],
            'leaderboardAccountType' => ['sometimes', 'string', 'in:'.implode(',', DashboardContent::ACCOUNT_TYPES)],
        ]);

        $settings = DashboardContent::saveSettings($data);

        return response()->json([
            'data' => [
                'settings' => $settings,
                'leaderboard' => DashboardContent::leaderboard($settings),
            ],
        ]);
    }

    private function validateBanner(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'min:3', 'max:80'],
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:160'],
            'tone' => ['sometimes', 'string', 'in:'.implode(',', DashboardContent::TONES)],
            'active' => ['sometimes', 'boolean'],
            'sortOrder' => ['sometimes', 'integer', 'min:0', 'max:99'],
        ]);
    }

    private function validatePromo(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'min:3', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:200'],
            'code' => ['sometimes', 'nullable', 'string', 'max:24'],
            'badge' => ['sometimes', 'nullable', 'string', 'max:24'],
            'validUntil' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'active' => ['sometimes', 'boolean'],
            'sortOrder' => ['sometimes', 'integer', 'min:0', 'max:99'],
        ]);
    }

    private function bannerChanges(array $data): array
    {
        $changes = [];

        foreach ([
            'title' => 'title',
            'subtitle' => 'subtitle',
            'tone' => 'tone',
            'active' => 'active',
            'sortOrder' => 'sort_order',
        ] as $field => $column) {
            if (array_key_exists($field, $data)) {
                $changes[$column] = $data[$field];
            }
        }

        return $changes;
    }

    private function findOrFail(string $table, string $id, string $message): object
    {
        abort_unless(ctype_digit($id), 422, 'Data tidak valid.');

        $row = DB::table($table)->where('id', (int) $id)->first();

        abort_if($row === null, 404, $message);

        return $row;
    }

    private function bannerRow(int $id): array
    {
        $row = DB::table('banners')->where('id', $id)->firstOrFail();

        return [
            'id' => (int) $row->id,
            'title' => $row->title,
            'subtitle' => (string) $row->subtitle,
            'tone' => $row->tone,
            'active' => (bool) $row->active,
            'sortOrder' => (int) $row->sort_order,
        ];
    }

    private function promoRow(int $id): array
    {
        $row = DB::table('promos')->where('id', $id)->firstOrFail();

        return [
            'id' => (int) $row->id,
            'title' => $row->title,
            'description' => (string) $row->description,
            'code' => (string) $row->code,
            'badge' => (string) $row->badge,
            'validUntil' => $row->valid_until ? (string) $row->valid_until : null,
            'active' => (bool) $row->active,
            'sortOrder' => (int) $row->sort_order,
        ];
    }
}
