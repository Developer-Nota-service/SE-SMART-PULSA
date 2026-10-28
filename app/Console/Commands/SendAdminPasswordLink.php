<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Kirim tautan sekali pakai untuk mengatur sandi akun admin lewat email.
 *
 * Token disimpan sebagai hash di `password_reset_tokens` — jalur yang sama
 * dengan `POST /api/v1/password/reset` — sehingga tautan hanya bisa dipakai
 * sekali dan kedaluwarsa sendiri. Tidak ada sandi default yang dikirim lewat
 * email; pengguna mengetik sandinya sendiri di halaman login.
 */
class SendAdminPasswordLink extends Command
{
    protected $signature = 'admin:password-link
        {email : Alamat email akun admin}
        {--url= : Alamat publik aplikasi untuk tautan (default APP_URL)}
        {--minutes=60 : Masa berlaku tautan dalam menit}';

    protected $description = 'Kirim tautan atur sandi untuk akun admin lewat email';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $user = DB::table('users')->where('email', $email)->first();

        if ($user === null) {
            $this->error("Akun {$email} tidak ditemukan.");

            return self::FAILURE;
        }

        if ((string) $user->role !== 'admin') {
            $this->error("Akun {$email} bukan admin.");

            return self::FAILURE;
        }

        $minutes = max(5, (int) $this->option('minutes'));
        $token = bin2hex(random_bytes(32));

        DB::table('password_reset_tokens')->updateOrInsert(
            ['user_id' => $user->id],
            [
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addMinutes($minutes),
                'created_at' => now(),
            ],
        );

        $base = rtrim((string) ($this->option('url') ?: config('app.url')), '/');
        $link = $base.'/login?resetToken='.$token;

        $mailer = (string) config('mail.default');

        // Sandbox tanpa SMTP menulis email ke log; tautannya ditampilkan di
        // sini supaya alurnya tetap bisa dicoba. Setelah SMTP diisi di
        // dashboard, baris ini tidak dipakai lagi.
        if (in_array($mailer, ['log', 'array'], true)) {
            $this->warn("MAIL_MAILER={$mailer}: email tidak benar-benar terkirim.");
            $this->line($link);
        }

        Mail::html($this->body($link, $minutes), function ($message) use ($email): void {
            $message->to($email)->subject('Atur sandi admin SE SMART PULSA');
        });

        $this->info("Tautan atur sandi untuk {$email} berlaku {$minutes} menit.");

        return self::SUCCESS;
    }

    private function body(string $link, int $minutes): string
    {
        $safe = e($link);

        return <<<HTML
        <div style="font-family:system-ui,-apple-system,'Segoe UI',sans-serif;line-height:1.6;color:#1e293b">
          <h2 style="margin:0 0 12px">Atur sandi admin SE SMART PULSA</h2>
          <p>Klik tombol di bawah untuk membuat sandi akun admin Anda.
             Tautan berlaku {$minutes} menit dan hanya bisa dipakai sekali.</p>
          <p style="margin:24px 0">
            <a href="{$safe}"
               style="background:#2563eb;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none;display:inline-block">
              Atur sandi sekarang
            </a>
          </p>
          <p style="font-size:13px;color:#64748b">
            Tidak meminta tautan ini? Abaikan email ini — sandi tidak berubah.
          </p>
        </div>
        HTML;
    }
}
