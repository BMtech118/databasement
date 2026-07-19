<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;

/**
 * Sends mail through the SMTP2GO HTTP API (v3/email/send) instead of SMTP.
 *
 * Selected via `MAIL_MAILER=smtp2go`; the API key comes from the mailer config
 * (`SMTP2GO_API_KEY`). Used because BMT sends all mail via the SMTP2GO API, and
 * the account's API keys are send-scoped (they can't be used for SMTP auth).
 */
class Smtp2goApiTransport extends AbstractTransport
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $endpoint = 'https://api.smtp2go.com/v3/email/send',
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $to = $this->formatAddresses($email->getTo());

        if ($to === []) {
            throw new TransportException('SMTP2GO: message has no "to" recipients.');
        }

        $from = $email->getFrom();

        $payload = array_filter([
            'api_key' => $this->apiKey,
            'sender' => $from !== [] ? $this->formatAddress($from[0]) : null,
            'to' => $to,
            'cc' => $this->formatAddresses($email->getCc()) ?: null,
            'bcc' => $this->formatAddresses($email->getBcc()) ?: null,
            'subject' => $email->getSubject() ?? '',
            'html_body' => $this->bodyToString($email->getHtmlBody()) ?: null,
            'text_body' => $this->bodyToString($email->getTextBody()) ?: null,
        ], fn ($v) => $v !== null);

        $response = Http::asJson()
            ->timeout(20)
            ->post($this->endpoint, $payload);

        $failed = (int) ($response->json('data.failed') ?? 1);

        if ($response->failed() || $failed > 0) {
            throw new TransportException('SMTP2GO API send failed: '.$response->body());
        }
    }

    /**
     * @param  array<int, Address>  $addresses
     * @return array<int, string>
     */
    private function formatAddresses(array $addresses): array
    {
        return array_map(fn (Address $a) => $this->formatAddress($a), $addresses);
    }

    private function formatAddress(Address $address): string
    {
        return $address->getName() !== ''
            ? sprintf('%s <%s>', $address->getName(), $address->getAddress())
            : $address->getAddress();
    }

    /**
     * Email bodies may be a string or a stream resource.
     */
    private function bodyToString(mixed $body): string
    {
        if (is_resource($body)) {
            return (string) stream_get_contents($body);
        }

        return (string) $body;
    }

    public function __toString(): string
    {
        return 'smtp2go://api.smtp2go.com';
    }
}
