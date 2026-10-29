<?php

namespace App\Console\Commands;

use App\Services\DigiflazzProductSync;
use Illuminate\Console\Command;

/**
 * Sinkron harga modal Digiflazz dari CLI. Logikanya ada di
 * `App\Services\DigiflazzProductSync` supaya tombol "Ambil harga terbaru" di
 * konsol admin memakai jalur yang sama.
 */
class SyncDigiflazzProducts extends Command
{
    protected $signature = 'digiflazz:sync-products';

    protected $description = 'Sinkronkan daftar produk & harga modal dari Digiflazz';

    public function handle(DigiflazzProductSync $sync): int
    {
        if (! $sync->isConfigured()) {
            $this->error('Digiflazz belum dikonfigurasi: isi DIGIFLAZZ_USERNAME dan DIGIFLAZZ_API_KEY.');

            return self::FAILURE;
        }

        try {
            $result = $sync->sync();
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Digiflazz: %d produk tersimpan (%d aktif), %d dilewati, %d dinonaktifkan.',
            $result['products'],
            $result['active'],
            $result['skipped'],
            $result['deactivated'],
        ));

        return self::SUCCESS;
    }
}
