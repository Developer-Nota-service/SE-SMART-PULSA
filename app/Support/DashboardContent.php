<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Konten beranda pengguna yang diatur admin: banner, promo, dan peringkat
 * pembelian. Peringkat tidak disimpan di tabel sendiri — selalu dihitung dari
 * transaksi sukses sehingga otomatis mengikuti aktivitas terbaru.
 */
class DashboardContent
{
    /** Warna banner yang didukung tampilan beranda. */
    public const TONES = ['navy', 'blue', 'green', 'amber'];

    /** Pilihan jumlah baris peringkat yang boleh dipilih admin. */
    public const LIMITS = [3, 5, 10];

    /** Rentang hari peringkat yang boleh dipilih admin. */
    public const PERIODS = [7, 30, 90];

    /** Tipe akun yang ikut dihitung pada peringkat. */
    public const ACCOUNT_TYPES = ['all', 'reseller', 'personal'];

    private const DEFAULTS = [
        'leaderboardEnabled' => '1',
        'leaderboardLimit' => '5',
        'leaderboardPeriodDays' => '30',
        'leaderboardAccountType' => 'all',
    ];

    /** Pengaturan beranda yang tersimpan, sudah memakai nilai bawaan bila kosong. */
    public static function settings(): array
    {
        $rows = DB::table('dashboard_settings')->pluck('value', 'key')->all();
        $merged = array_merge(self::DEFAULTS, $rows);

        $limit = (int) $merged['leaderboardLimit'];
        $period = (int) $merged['leaderboardPeriodDays'];
        $accountType = (string) $merged['leaderboardAccountType'];

        return [
            'leaderboardEnabled' => (string) $merged['leaderboardEnabled'] === '1',
            'leaderboardLimit' => in_array($limit, self::LIMITS, true) ? $limit : 5,
            'leaderboardPeriodDays' => in_array($period, self::PERIODS, true) ? $period : 30,
            'leaderboardAccountType' => in_array($accountType, self::ACCOUNT_TYPES, true)
                ? $accountType
                : 'all',
        ];
    }

    /** Simpan sebagian pengaturan saja; kunci lain tidak berubah. */
    public static function saveSettings(array $values): array
    {
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, self::DEFAULTS)) {
                continue;
            }

            DB::table('dashboard_settings')->upsert(
                [[
                    'key' => $key,
                    'value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
                    'updated_at' => now(),
                ]],
                ['key'],
                ['value', 'updated_at'],
            );
        }

        return self::settings();
    }

    public static function banners(bool $onlyActive): array
    {
        return DB::table('banners')
            ->when($onlyActive, fn ($query) => $query->where('active', true))
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get(['id', 'title', 'subtitle', 'image_path', 'tone', 'active', 'sort_order'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'title' => $row->title,
                'subtitle' => (string) $row->subtitle,
                'imageUrl' => self::bannerImageUrl($row->image_path),
                'tone' => in_array($row->tone, self::TONES, true) ? $row->tone : 'navy',
                'active' => (bool) $row->active,
                'sortOrder' => (int) $row->sort_order,
            ])
            ->all();
    }

    /**
     * URL publik gambar banner. Nama file acak (32 hex) sehingga alamatnya tidak
     * bisa ditebak; dipakai langsung oleh <img> di beranda dan konsol admin.
     */
    public static function bannerImageUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return '/api/v1/banner/'.basename($path);
    }

    public static function promos(bool $onlyActive): array
    {
        $today = now()->toDateString();

        return DB::table('promos')
            ->when($onlyActive, fn ($query) => $query->where('active', true)
                ->where(fn ($inner) => $inner
                    ->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', $today)))
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get(['id', 'title', 'description', 'code', 'badge', 'valid_until', 'active', 'sort_order'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'title' => $row->title,
                'description' => (string) $row->description,
                'code' => (string) $row->code,
                'badge' => (string) $row->badge,
                'validUntil' => $row->valid_until ? (string) $row->valid_until : null,
                'active' => (bool) $row->active,
                'sortOrder' => (int) $row->sort_order,
            ])
            ->all();
    }

    /**
     * Peringkat pembelian: akun dengan transaksi sukses terbanyak pada rentang
     * yang dipilih. Nama disamarkan karena tampil ke seluruh pengguna.
     */
    public static function leaderboard(array $settings): array
    {
        $base = [
            'enabled' => (bool) $settings['leaderboardEnabled'],
            'limit' => (int) $settings['leaderboardLimit'],
            'periodDays' => (int) $settings['leaderboardPeriodDays'],
            'accountType' => (string) $settings['leaderboardAccountType'],
            'items' => [],
        ];

        if (! $base['enabled']) {
            return $base;
        }

        $rows = DB::table('transactions as t')
            ->join('users as u', 'u.id', '=', 't.user_id')
            ->where('t.status', 'success')
            ->where('t.created_at', '>=', now()->subDays($base['periodDays']))
            ->where('u.role', 'user')
            ->when(
                $base['accountType'] !== 'all',
                fn ($query) => $query->where('u.account_type', $base['accountType']),
            )
            ->groupBy('u.id', 'u.name', 'u.account_type')
            ->orderByRaw('COUNT(t.id) DESC')
            ->orderByRaw('SUM(t.amount) DESC')
            ->limit($base['limit'])
            ->get([
                'u.name',
                'u.account_type',
                DB::raw('COUNT(t.id) AS transactions'),
                DB::raw('SUM(t.amount) AS amount'),
            ]);

        $rank = 0;
        $base['items'] = $rows->map(function ($row) use (&$rank) {
            $rank++;

            return [
                'rank' => $rank,
                'name' => self::maskName($row->name),
                'accountType' => $row->account_type === 'personal' ? 'personal' : 'reseller',
                'transactions' => (int) $row->transactions,
                'amount' => (int) $row->amount,
            ];
        })->all();

        return $base;
    }

    /** Samarkan nama pembeli: "Budi Santoso" → "Bud*** S.". */
    public static function maskName(string $name): string
    {
        $parts = array_values(array_filter(preg_split('/\s+/', trim($name)) ?: []));

        if ($parts === []) {
            return 'Pengguna';
        }

        $first = mb_substr($parts[0], 0, min(3, mb_strlen($parts[0]))).'***';

        if (count($parts) === 1) {
            return $first;
        }

        return $first.' '.mb_strtoupper(mb_substr($parts[1], 0, 1)).'.';
    }
}
