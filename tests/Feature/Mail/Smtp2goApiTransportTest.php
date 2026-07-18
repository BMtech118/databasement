<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;

function useSmtp2goMailer(): void
{
    config([
        'mail.default' => 'smtp2go',
        'mail.mailers.smtp2go' => ['transport' => 'smtp2go', 'key' => 'api-test-key'],
        'mail.from.address' => 'Backups@bmtechdev.co.uk',
        'mail.from.name' => 'BMT Backups',
    ]);
}

test('sends through the SMTP2GO API with the expected payload', function () {
    Http::fake(['api.smtp2go.com/*' => Http::response(['data' => ['succeeded' => 1, 'failed' => 0]], 200)]);
    useSmtp2goMailer();

    Mail::raw('a backup failed', fn ($m) => $m->to('ops@example.com')->subject('Backup failed'));

    Http::assertSent(function ($request) {
        $b = $request->data();

        return $request->url() === 'https://api.smtp2go.com/v3/email/send'
            && $b['api_key'] === 'api-test-key'
            && $b['subject'] === 'Backup failed'
            && $b['to'] === ['ops@example.com']
            && str_contains($b['sender'], 'Backups@bmtechdev.co.uk')
            && str_contains($b['text_body'], 'a backup failed');
    });
});

test('throws when the SMTP2GO API reports a failure', function () {
    Http::fake(['api.smtp2go.com/*' => Http::response(['data' => ['succeeded' => 0, 'failed' => 1, 'failures' => ['rejected']]], 200)]);
    useSmtp2goMailer();

    Mail::raw('x', fn ($m) => $m->to('a@b.com')->subject('s'));
})->throws(TransportException::class);
