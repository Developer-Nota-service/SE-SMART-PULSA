<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Konteks satu kali login untuk ditampilkan di halaman profil: perangkat yang
 * dipakai (merek, tipe, OS, browser) dan perkiraan lokasi dari alamat IP.
 * Informasi saja — jangan dipakai sebagai dasar keputusan keamanan.
 */
class LoginContext
{
    /**
     * @return array{ip:?string,device:array<string,?string>,location:?string}
     */
    public static function capture(Request $request): array
    {
        $ip = self::clientIp($request);

        $location = self::location($ip);

        // IP privat (proxy dev / jaringan kantor) tidak bisa dilacak: beri label
        // jujur alih-alih "tidak diketahui".
        if ($location === null && ! self::isPublicIp($ip)) {
            $location = 'Jaringan lokal';
        }

        return [
            'ip' => $ip,
            'device' => self::device((string) $request->userAgent()),
            'location' => $location,
        ];
    }

    /**
     * IP publik klien. Di belakang proxy (Vite dev server, Apache hosting) IP
     * asli hanya ada di X-Forwarded-For / X-Real-IP, jadi header itu dibaca
     * lebih dulu. Nilainya informatif saja, bukan dasar keamanan.
     */
    private static function clientIp(Request $request): ?string
    {
        $candidates = array_map(
            static fn ($value) => trim((string) $value),
            explode(',', (string) $request->header('X-Forwarded-For', '')),
        );
        $candidates[] = trim((string) $request->header('X-Real-IP', ''));
        $candidates[] = (string) $request->ip();

        foreach ($candidates as $candidate) {
            if (self::isPublicIp($candidate)) {
                return $candidate;
            }
        }

        return $request->ip();
    }

    private static function isPublicIp(?string $ip): bool
    {
        return $ip !== null
            && $ip !== ''
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * @return array{label:string,type:?string,os:?string,browser:?string}
     */
    private static function device(string $ua): array
    {
        $ua = trim($ua);

        if ($ua === '') {
            return ['label' => 'Tidak diketahui', 'type' => null, 'os' => null, 'browser' => null];
        }

        $type = 'Komputer';
        if (preg_match('/iPad|Tablet/i', $ua) === 1) {
            $type = 'Tablet';
        } elseif (preg_match('/iPhone|Android|Mobile/i', $ua) === 1) {
            $type = 'Ponsel';
        }

        return [
            'label' => self::deviceLabel($ua, $type),
            'type' => $type,
            'os' => self::operatingSystem($ua),
            'browser' => self::browser($ua),
        ];
    }

    private static function deviceLabel(string $ua, string $type): string
    {
        if (preg_match('/iPhone/i', $ua) === 1) {
            return 'Apple iPhone';
        }
        if (preg_match('/iPad/i', $ua) === 1) {
            return 'Apple iPad';
        }

        $model = null;
        if (preg_match('/Android [\d.]+;\s*([^;)]+?)(?:\s+Build\/|\)|;)/i', $ua, $m) === 1) {
            $candidate = trim($m[1]);
            // Chrome memakai model "K" untuk sebagian perangkat (reduced UA).
            if (strlen($candidate) > 1 && ! in_array(strtoupper($candidate), ['K', 'WV', 'MOBILE'], true)) {
                $model = $candidate;
            }
        }

        if ($model === null) {
            return $type === 'Komputer' ? 'Komputer' : 'Perangkat '.$type;
        }

        $brand = self::brand($model);

        return $brand === null ? $model : $brand.' '.$model;
    }

    private static function brand(string $model): ?string
    {
        $upper = strtoupper($model);
        $prefixes = [
            'SM-' => 'Samsung',
            'SAMSUNG' => 'Samsung',
            'CPH' => 'OPPO',
            'OPPO' => 'OPPO',
            'RMX' => 'Realme',
            'REALME' => 'Realme',
            'V2' => 'vivo',
            'VIVO' => 'vivo',
            'REDMI' => 'Xiaomi',
            'POCO' => 'Xiaomi',
            'M2' => 'Xiaomi',
            'MI ' => 'Xiaomi',
            'INFINIX' => 'Infinix',
            'TECNO' => 'Tecno',
            'ELS-' => 'Huawei',
            'HUAWEI' => 'Huawei',
            'PIXEL' => 'Google',
            'ONEPLUS' => 'OnePlus',
            'ASUS' => 'Asus',
            'NOKIA' => 'Nokia',
            'MOTO' => 'Motorola',
        ];

        foreach ($prefixes as $prefix => $brand) {
            if (str_starts_with($upper, $prefix)) {
                return $brand;
            }
        }

        return null;
    }

    private static function operatingSystem(string $ua): ?string
    {
        if (preg_match('/iPhone OS ([\d_]+)/', $ua, $m) === 1) {
            return 'iOS '.str_replace('_', '.', $m[1]);
        }
        if (preg_match('/Android ([\d.]+)/', $ua, $m) === 1) {
            return 'Android '.$m[1];
        }
        if (preg_match('/Mac OS X ([\d_]+)/', $ua, $m) === 1) {
            return 'macOS '.str_replace('_', '.', $m[1]);
        }
        if (str_contains($ua, 'Windows')) {
            return 'Windows';
        }
        if (str_contains($ua, 'Linux')) {
            return 'Linux';
        }

        return null;
    }

    private static function browser(string $ua): ?string
    {
        $patterns = [
            '/Edg\/([\d]+)/' => 'Edge',
            '/OPR\/([\d]+)/' => 'Opera',
            '/SamsungBrowser\/([\d]+)/' => 'Samsung Internet',
            '/CriOS\/([\d]+)/' => 'Chrome',
            '/Chrome\/([\d]+)/' => 'Chrome',
            '/Firefox\/([\d]+)/' => 'Firefox',
            '/Version\/([\d]+)[^)]*Safari/' => 'Safari',
        ];

        foreach ($patterns as $pattern => $name) {
            if (preg_match($pattern, $ua, $m) === 1) {
                return $name.' '.$m[1];
            }
        }

        return null;
    }

    /**
     * Perkiraan lokasi dari IP publik lewat ipwho.is. Gagal atau IP lokal
     * (loopback/privat) dikembalikan sebagai null, bukan error.
     */
    private static function location(?string $ip): ?string
    {
        if (! self::isPublicIp($ip)) {
            return null;
        }

        try {
            $body = Http::timeout(3)
                ->get("https://ipwho.is/{$ip}", ['fields' => 'success,city,region,country'])
                ->json();
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($body) || ($body['success'] ?? false) !== true) {
            return null;
        }

        $parts = array_values(array_filter(array_map(
            static fn ($value) => trim((string) $value),
            [$body['city'] ?? '', $body['region'] ?? '', $body['country'] ?? ''],
        )));

        return $parts === [] ? null : implode(', ', $parts);
    }
}
