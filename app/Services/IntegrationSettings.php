<?php

namespace App\Services;

use App\Models\IntegrationCredential;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Kredensial integrasi (Midtrans, Digiflazz, Fonnte, email).
 *
 * Sumber nilai ada dua: environment (dashboard secrets / `.env`) dan nilai
 * yang disimpan admin di tabel `integration_credentials`. Nilai tersimpan
 * menang, lalu ditimpa ke config saat boot sehingga seluruh service yang sudah
 * membaca `config()` ikut memakai kredensial baru tanpa restart.
 */
class IntegrationSettings
{
    /** Cache nilai tersimpan; dihapus setiap kali admin menyimpan. */
    private const CACHE_KEY = 'integration.credentials';

    /** Umur cache (detik) — batas perubahan terlihat oleh container lain. */
    private const CACHE_TTL = 300;

    /** Definisi field datar: key => definisi (dari config/integrations.php). */
    public function fields(): array
    {
        static $fields = null;

        if ($fields !== null) {
            return $fields;
        }

        $fields = [];
        foreach ((array) config('integrations.groups', []) as $group) {
            foreach ($group['fields'] ?? [] as $field) {
                $fields[$field['key']] = $field;
            }
        }

        return $fields;
    }

    /**
     * Timpa config dengan nilai yang disimpan admin. Dipanggil sekali saat
     * boot; aman bila tabel belum ada (mis. saat `artisan migrate`).
     */
    public function applyOverrides(): void
    {
        foreach ($this->storedValues() as $key => $value) {
            $field = $this->fields()[$key] ?? null;

            if ($field === null) {
                continue;
            }

            config([$field['config'] => $this->forConfig($field, $value)]);
        }
    }

    /**
     * Status semua kredensial untuk konsol admin. Nilai rahasia hanya
     * dikirim dalam bentuk tersamarkan — kunci asli tidak pernah keluar API.
     */
    public function status(): array
    {
        $stored = $this->storedValues();
        $groups = [];

        foreach ((array) config('integrations.groups', []) as $slug => $group) {
            $fields = [];

            foreach ($group['fields'] ?? [] as $field) {
                $key = $field['key'];
                $fromDashboard = array_key_exists($key, $stored);
                $envValue = env($key);

                // `env()` kosong bila config di-cache; variabel proses tetap
                // terbaca lewat getenv() sehingga sumber nilai tidak salah tampil.
                if ($envValue === null) {
                    $processValue = getenv($key);
                    $envValue = $processValue === false ? null : $processValue;
                }

                $envConfigured = $envValue !== null && $envValue !== '';
                $effective = $fromDashboard ? $stored[$key] : $envValue;
                $configured = $fromDashboard
                    ? trim((string) $stored[$key]) !== ''
                    : $envConfigured;

                $fields[] = [
                    'key' => $key,
                    'label' => $field['label'],
                    'type' => $field['type'],
                    'hint' => $field['hint'] ?? null,
                    'options' => $field['options'] ?? null,
                    'configured' => $configured,
                    'source' => $fromDashboard ? 'dashboard' : ($envConfigured ? 'env' : 'unset'),
                    'value' => $this->display($field, $effective),
                ];
            }

            $groups[] = [
                'key' => $slug,
                'label' => $group['label'],
                'description' => $group['description'] ?? null,
                'fields' => $fields,
            ];
        }

        return ['groups' => $groups];
    }

    /**
     * Simpan nilai baru. `null` menghapus nilai tersimpan (kembali ke env),
     * string kosong diabaikan (tidak mengubah apa pun).
     *
     * @return array<int, string> kunci yang berubah
     *
     * @throws ValidationException
     */
    public function update(array $values, int $adminId): array
    {
        $fields = $this->fields();
        $errors = [];
        $clean = [];

        foreach ($values as $key => $value) {
            $field = $fields[$key] ?? null;

            if ($field === null) {
                $errors["values.$key"] = ['Kunci integrasi tidak dikenal.'];
                continue;
            }

            if ($value === null) {
                $clean[$key] = null;
                continue;
            }

            if (! is_scalar($value)) {
                $errors["values.$key"] = ['Nilai tidak valid.'];
                continue;
            }

            $text = trim((string) $value);

            // Kosong = tidak diubah, supaya nilai lama tidak terhapus tak sengaja.
            if ($text === '') {
                continue;
            }

            if (mb_strlen($text) > 500) {
                $errors["values.$key"] = ['Nilai terlalu panjang (maks 500 karakter).'];
                continue;
            }

            if ($field['type'] === 'number' && (! ctype_digit($text) || (int) $text < 1 || (int) $text > 65535)) {
                $errors["values.$key"] = ['Harus berupa angka 1-65535.'];
                continue;
            }

            if ($field['type'] === 'toggle') {
                $normalized = filter_var($text, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

                if ($normalized === null) {
                    $errors["values.$key"] = ['Pilih aktif atau nonaktif.'];
                    continue;
                }

                $text = $normalized ? '1' : '0';
            }

            if ($field['type'] === 'select' && ! array_key_exists($text, $field['options'] ?? [])) {
                $errors["values.$key"] = ['Pilihan tidak dikenal.'];
                continue;
            }

            $clean[$key] = $text;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if ($clean === []) {
            return [];
        }

        DB::transaction(function () use ($clean, $adminId) {
            foreach ($clean as $key => $value) {
                if ($value === null) {
                    IntegrationCredential::query()->where('key', $key)->delete();
                    continue;
                }

                IntegrationCredential::query()->updateOrCreate(
                    ['key' => $key],
                    ['value' => $value, 'updated_by' => $adminId],
                );
            }

            // Jejak audit tanpa nilai kredensial — hanya kunci yang berubah.
            DB::table('audit_logs')->insert([
                'user_id' => $adminId,
                'action' => 'admin.integration.update',
                'detail' => json_encode(['keys' => array_keys($clean)]),
                'created_at' => now(),
            ]);
        });

        Cache::forget(self::CACHE_KEY);

        return array_keys($clean);
    }

    /**
     * Nilai tersimpan (sudah didekripsi lewat cast model), di-cache singkat.
     *
     * @return array<string, string>
     */
    public function storedValues(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
                // Lewati query bila tabel belum dimigrasi (mis. saat
                // `artisan migrate`/`db:seed` boot) supaya PostgreSQL tidak
                // mencatat error "relation does not exist" untuk tiap request.
                if (! Schema::hasTable((new IntegrationCredential)->getTable())) {
                    return [];
                }

                return IntegrationCredential::query()
                    ->get()
                    ->mapWithKeys(fn (IntegrationCredential $row) => [$row->key => (string) $row->value])
                    ->all();
            });
        } catch (\Throwable) {
            // Tabel belum dimigrasi atau DB belum siap.
            return [];
        }
    }

    private function forConfig(array $field, string $value): mixed
    {
        return match ($field['type']) {
            'toggle' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'number' => (int) $value,
            default => $value,
        };
    }

    private function display(array $field, mixed $value): string
    {
        if ($field['type'] === 'toggle') {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Aktif' : 'Nonaktif';
        }

        $text = $value === null ? '' : trim((string) $value);

        if (! empty($field['secret'])) {
            if ($text === '') {
                return '';
            }

            return mb_strlen($text) <= 6 ? '••••' : '••••••'.mb_substr($text, -4);
        }

        return $text;
    }
}
