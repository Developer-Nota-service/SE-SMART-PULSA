<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu kredensial integrasi yang disimpan admin. Kolom `value` memakai cast
 * `encrypted` sehingga isinya dienkripsi dengan APP_KEY — membaca tabel
 * langsung tidak memperlihatkan kunci aslinya.
 */
class IntegrationCredential extends Model
{
    protected $table = 'integration_credentials';

    protected $fillable = ['key', 'value', 'updated_by'];

    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
        ];
    }
}
