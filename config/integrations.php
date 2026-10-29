<?php

/*
|--------------------------------------------------------------------------
| Katalog Integrasi (API Key)
|--------------------------------------------------------------------------
|
| Daftar kredensial pihak ketiga yang bisa diubah admin dari konsol admin.
| Setiap field memetakan kunci environment ke path config yang dipakai
| aplikasi, sehingga nilai yang disimpan admin langsung berlaku tanpa
| mengubah file .env atau me-restart container.
|
| `type`: text | secret | number | toggle | select
| `secret`: nilai disamarkan di respons API (hanya 4 karakter terakhir).
| `config`: path config yang ditimpa ketika admin menyimpan nilai baru.
|
*/

return [

    'groups' => [

        'digiflazz' => [
            'label' => 'Digiflazz',
            'description' => 'Supplier pulsa, data, dan voucher. Kredensial dari Dashboard Digiflazz > Atur Koneksi > API.',
            'fields' => [
                [
                    'key' => 'DIGIFLAZZ_USERNAME',
                    'label' => 'Username buyer',
                    'config' => 'digiflazz.username',
                    'type' => 'text',
                ],
                [
                    'key' => 'DIGIFLAZZ_API_KEY',
                    'label' => 'API key',
                    'config' => 'digiflazz.api_key',
                    'type' => 'secret',
                    'secret' => true,
                    'hint' => 'Tanpa API key, transaksi nyata tidak dikirim ke supplier.',
                ],
                [
                    'key' => 'DIGIFLAZZ_WEBHOOK_SECRET',
                    'label' => 'Webhook secret',
                    'config' => 'digiflazz.webhook_secret',
                    'type' => 'secret',
                    'secret' => true,
                    'hint' => 'Wajib agar update status transaksi dari Digiflazz diterima.',
                ],
                [
                    'key' => 'DIGIFLAZZ_TESTING',
                    'label' => 'Mode development',
                    'config' => 'digiflazz.testing',
                    'type' => 'toggle',
                    'hint' => 'Matikan saat memakai Production Key agar transaksi benar-benar dikirim.',
                ],
            ],
        ],

        'midtrans' => [
            'label' => 'Midtrans',
            'description' => 'Pembayaran deposit dan pembelian langsung. Kredensial dari Dashboard Midtrans > Settings > Access Keys.',
            'fields' => [
                [
                    'key' => 'MIDTRANS_SERVER_KEY',
                    'label' => 'Server key',
                    'config' => 'services.midtrans.server_key',
                    'type' => 'secret',
                    'secret' => true,
                    'hint' => 'Dipakai membuat pembayaran Snap dan memverifikasi signature webhook.',
                ],
                [
                    'key' => 'MIDTRANS_CLIENT_KEY',
                    'label' => 'Client key',
                    'config' => 'services.midtrans.client_key',
                    'type' => 'secret',
                    'secret' => true,
                ],
                [
                    'key' => 'MIDTRANS_IS_PRODUCTION',
                    'label' => 'Mode produksi',
                    'config' => 'services.midtrans.is_production',
                    'type' => 'toggle',
                    'hint' => 'Matikan selama masih memakai kunci sandbox (SB-).',
                ],
            ],
        ],

        'fonnte' => [
            'label' => 'Fonnte (WhatsApp)',
            'description' => 'Pengiriman kode OTP WhatsApp saat pendaftaran dan verifikasi akun. Token dari fonnte.com > Device > Token.',
            'fields' => [
                [
                    'key' => 'FONNTE_TOKEN',
                    'label' => 'Token device',
                    'config' => 'fonnte.token',
                    'type' => 'secret',
                    'secret' => true,
                    'hint' => 'Tanpa token, kode OTP tidak terkirim dan akun baru tidak bisa verifikasi.',
                ],
            ],
        ],

        'email' => [
            'label' => 'Email',
            'description' => 'Pengiriman kode verifikasi email dan tautan atur sandi admin.',
            'fields' => [
                [
                    'key' => 'MAIL_MAILER',
                    'label' => 'Transport',
                    'config' => 'mail.default',
                    'type' => 'select',
                    'options' => [
                        'smtp' => 'SMTP',
                        'kirisan' => 'Kirisan',
                        'log' => 'Log (tanpa kirim)',
                    ],
                ],
                [
                    'key' => 'MAIL_HOST',
                    'label' => 'Host SMTP',
                    'config' => 'mail.mailers.smtp.host',
                    'type' => 'text',
                ],
                [
                    'key' => 'MAIL_PORT',
                    'label' => 'Port SMTP',
                    'config' => 'mail.mailers.smtp.port',
                    'type' => 'number',
                    'hint' => '587 untuk TLS, 465 untuk SSL.',
                ],
                [
                    'key' => 'MAIL_SCHEME',
                    'label' => 'Skema SMTP',
                    'config' => 'mail.mailers.smtp.scheme',
                    'type' => 'select',
                    'options' => [
                        'smtp' => 'smtp (port 587)',
                        'smtps' => 'smtps (port 465)',
                    ],
                ],
                [
                    'key' => 'MAIL_USERNAME',
                    'label' => 'Username SMTP',
                    'config' => 'mail.mailers.smtp.username',
                    'type' => 'text',
                ],
                [
                    'key' => 'MAIL_PASSWORD',
                    'label' => 'Password SMTP',
                    'config' => 'mail.mailers.smtp.password',
                    'type' => 'secret',
                    'secret' => true,
                    'hint' => 'Gmail memakai App Password, bukan password akun.',
                ],
                [
                    'key' => 'MAIL_FROM_ADDRESS',
                    'label' => 'Alamat pengirim',
                    'config' => 'mail.from.address',
                    'type' => 'text',
                ],
                [
                    'key' => 'MAIL_FROM_NAME',
                    'label' => 'Nama pengirim',
                    'config' => 'mail.from.name',
                    'type' => 'text',
                ],
            ],
        ],

        'kirisan' => [
            'label' => 'Kirisan',
            'description' => 'Alternatif SMTP untuk email (set transport Email ke Kirisan). Token dari dash.kirisan.com.',
            'fields' => [
                [
                    'key' => 'KIRISAN_API_TOKEN',
                    'label' => 'Token akun',
                    'config' => 'kirisan.token',
                    'type' => 'secret',
                    'secret' => true,
                ],
                [
                    'key' => 'KIRISAN_EMAIL_TOKEN',
                    'label' => 'Token sender email',
                    'config' => 'kirisan.email_token',
                    'type' => 'secret',
                    'secret' => true,
                ],
            ],
        ],

    ],

];
