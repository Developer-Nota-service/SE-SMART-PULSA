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

        // Pricelist Digiflazz dibatasi 1x per 5 menit; jadwal otomatis cukup
        // menunggu tarikan berikutnya daripada menabrak batas supplier.
        $wait = $sync->cooldownRemaining();

        if ($wait > 0) {
            $this->info(sprintf('Dilewati: pricelist baru ditarik, coba lagi dalam %d detik.', $wait));

            return self::SUCCESS;
        }

        try {
            $result = $sync->sync();
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Digiflazz: %d produk tersimpan (%d aktif, %d baru, %d ganti kode), %d dilewati, %d dinonaktifkan.',
            $result['products'],
            $result['active'],
            $result['created'],
            $result['renamed'],
            $result['skipped'],
            $result['deactivated'],
        ));

        if ($result['disturbed'] > 0) {
            $this->info(sprintf(
                '%d produk berstatus gangguan (tidak bisa dibeli)%s.',
                $result['disturbed'],
                $result['disturbanceAlertSent'] ? ', notif WhatsApp terkirim' : '',
            ));
        }

        return self::SUCCESS;
    }
}
