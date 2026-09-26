<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class WhatsAppService
{
    public function __construct(
        private readonly string $accessToken,
        private readonly string $phoneNumberId,
        private readonly string $apiVersion,
        private readonly string $baseUrl = 'https://graph.facebook.com',
        private readonly int $timeout = 15,
    ) {}

    public static function fromConfig(): self
    {
        /** @var array<string, mixed> $config */
        $config = config('services.whatsapp', []);

        return new self(
            accessToken: (string) ($config['access_token'] ?? ''),
            phoneNumberId: (string) ($config['phone_number_id'] ?? ''),
            apiVersion: (string) ($config['api_version'] ?? ''),
            baseUrl: (string) ($config['base_url'] ?? 'https://graph.facebook.com'),
            timeout: 15,
        );
    }

    /**
     * Send a single text message through the WhatsApp Cloud API.
     *
     * Exactly one send request is made: there is deliberately no automatic
     * retry, because a transport failure may occur after Meta already accepted
     * the message, and a blind retry could deliver it twice.
     *
     * @return string Meta's WhatsApp message ID for the sent message.
     *
     * @throws WhatsAppException On missing configuration, transport failure, or API error.
     */
    public function sendText(string $to, string $body): string
    {
        if (trim($this->accessToken) === '' || trim($this->phoneNumberId) === '') {
            throw new WhatsAppException('WhatsApp is not configured.');
        }

        if (trim($this->apiVersion) === '') {
            throw new WhatsAppException('WhatsApp API version is not configured.');
        }

        if (trim($to) === '') {
            throw new WhatsAppException('WhatsApp recipient is missing.');
        }

        if (trim($body) === '') {
            throw new WhatsAppException('WhatsApp message body is missing.');
        }

        $url = "{$this->baseUrl}/{$this->apiVersion}/{$this->phoneNumberId}/messages";

        try {
            $response = Http::timeout($this->timeout)
                ->withToken($this->accessToken)
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'to' => $to,
                    'type' => 'text',
                    'text' => ['body' => $body],
                ]);
        } catch (Throwable $e) {
            Log::warning('WhatsApp send failed.', ['error' => $e::class]);

            throw new WhatsAppException('WhatsApp send failed.', 0, $e);
        }

        $this->ensureSuccess($response);

        $id = data_get($response->json(), 'messages.0.id');

        if (! is_string($id) || $id === '') {
            Log::warning('WhatsApp send response missing message ID.');

            throw new WhatsAppException('WhatsApp send response was malformed.');
        }

        return $id;
    }

    /**
     * @throws WhatsAppException
     */
    private function ensureSuccess(Response $response): void
    {
        if ($response->failed()) {
            Log::warning('WhatsApp send failed.', ['status' => $response->status()]);

            throw new WhatsAppException("WhatsApp send failed with status {$response->status()}.");
        }
    }
}
