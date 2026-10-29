<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Transport Mail Laravel lewat Kirisan Send API
 * (https://docs.kirisan.com/en/docs/api/send).
 *
 * Kirisan menerima satu penerima per permintaan, jadi alamat tujuan dikirim
 * satu per satu. Pesan tanpa template masuk lane "raw" (diantrekan Kirisan).
 * From ditentukan oleh token sender, bukan header pesan.
 */
class KirisanTransport implements TransportInterface
{
    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if (! $message instanceof Email) {
            throw new TransportException('Kirisan hanya bisa mengirim pesan email.');
        }

        $envelope ??= Envelope::create($message);
        $recipients = array_map(
            static fn ($address) => $address->getAddress(),
            $envelope->getRecipients(),
        );

        if ($recipients === []) {
            throw new TransportException('Email tanpa alamat tujuan.');
        }

        $token = (string) config('kirisan.token');
        $sender = (string) config('kirisan.email_token');

        if ($token === '' || $sender === '') {
            throw new TransportException('Kirisan belum dikonfigurasi: isi KIRISAN_API_TOKEN dan KIRISAN_EMAIL_TOKEN.');
        }

        $subject = (string) ($message->getSubject() ?? '');
        $text = $message->getTextBody();
        $html = $message->getHtmlBody();

        $content = array_filter([
            'type' => 'text',
            'subject' => $subject,
            'message' => is_string($text) && $text !== '' ? $text : null,
            'html' => is_string($html) && $html !== '' ? $html : null,
        ], static fn ($value) => $value !== null);

        foreach ($recipients as $recipient) {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(20)
                ->post(rtrim((string) config('kirisan.url'), '/').'/v1/send', [
                    'keys' => ['email' => ['token' => $sender]],
                    'content' => ['email' => ['message' => $content]],
                    'target' => ['email' => $recipient],
                ]);

            if ($response->failed() || $response->json('status') !== true) {
                $reason = $response->json('reason');

                Log::warning('Kirisan menolak email', [
                    'status' => $response->status(),
                    'reason' => $reason,
                ]);

                throw new TransportException(
                    'Kirisan menolak email: '.(is_string($reason) && $reason !== '' ? $reason : 'status '.$response->status())
                );
            }
        }

        return new SentMessage($message, $envelope);
    }

    public function __toString(): string
    {
        return 'kirisan';
    }
}
