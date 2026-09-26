<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Services\Ai\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WhatsAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/api/webhooks/whatsapp';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.whatsapp.verify_token', 'test-verify-token');
        config()->set('services.whatsapp.app_secret', 'test-app-secret');
        config()->set('services.whatsapp.phone_number_id', '999');
    }

    private function verify(array $params): TestResponse
    {
        return $this->call('GET', self::URI, $params);
    }

    private function postRaw(string $rawBody, ?string $secret = 'test-app-secret'): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        if ($secret !== null) {
            $server['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $rawBody, $secret);
        }

        return $this->call('POST', self::URI, [], [], [], $server, $rawBody);
    }

    private function envelope(array $messages = [], array $statuses = [], string $phoneNumberId = '999'): string
    {
        $value = [
            'messaging_product' => 'whatsapp',
            'metadata' => ['display_phone_number' => '15551234567', 'phone_number_id' => $phoneNumberId],
        ];

        if ($messages !== []) {
            $value['messages'] = $messages;
        }

        if ($statuses !== []) {
            $value['statuses'] = $statuses;
        }

        return json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [['id' => 'waba-1', 'changes' => [['value' => $value, 'field' => 'messages']]]],
        ], JSON_UNESCAPED_UNICODE);
    }

    private function textMessage(string $id, string $from, string $body): array
    {
        return [
            'from' => $from,
            'id' => $id,
            'timestamp' => '1750000000',
            'text' => ['body' => $body],
            'type' => 'text',
        ];
    }

    public function test_valid_verification_returns_exact_challenge(): void
    {
        $response = $this->verify([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'test-verify-token',
            'hub.challenge' => 'challenge-abc-123',
        ]);

        $response->assertStatus(200);
        $this->assertSame('challenge-abc-123', $response->getContent());
    }

    public function test_invalid_verify_token_is_rejected(): void
    {
        $this->verify([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'wrong-token',
            'hub.challenge' => 'challenge-abc-123',
        ])->assertStatus(403);
    }

    public function test_invalid_mode_is_rejected(): void
    {
        $this->verify([
            'hub.mode' => 'unsubscribe',
            'hub.verify_token' => 'test-verify-token',
            'hub.challenge' => 'challenge-abc-123',
        ])->assertStatus(403);
    }

    public function test_missing_verification_params_are_rejected(): void
    {
        $this->verify([])->assertStatus(403);
        $this->verify(['hub.mode' => 'subscribe'])->assertStatus(403);
    }

    public function test_unconfigured_verify_token_is_rejected(): void
    {
        config()->set('services.whatsapp.verify_token', '');

        $this->verify([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'anything',
            'hub.challenge' => 'challenge-abc-123',
        ])->assertStatus(403);
    }

    public function test_valid_signature_is_accepted(): void
    {
        $response = $this->postRaw(
            $this->envelope([$this->textMessage('wamid.1', '965000000001', 'مرحبا')])
        );

        $response->assertStatus(200);
        $response->assertJson(['received' => 1]);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $this->postRaw(
            $this->envelope([$this->textMessage('wamid.1', '965000000001', 'مرحبا')]),
            'wrong-secret'
        )->assertStatus(403);
    }

    public function test_missing_signature_is_rejected(): void
    {
        $this->postRaw(
            $this->envelope([$this->textMessage('wamid.1', '965000000001', 'مرحبا')]),
            null
        )->assertStatus(403);
    }

    public function test_signature_covers_the_exact_raw_body(): void
    {
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [['id' => 'waba-1', 'changes' => [['value' => [
                'messaging_product' => 'whatsapp',
                'messages' => [$this->textMessage('wamid.9', '965000000009', 'spacing matters')],
            ], 'field' => 'messages']]]],
        ];

        $pretty = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        // Signature over the exact (pretty-printed) bytes is accepted.
        $this->postRaw($pretty)->assertStatus(200);

        // The same logical payload with a signature over different bytes is rejected.
        $compact = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $pretty, 'test-app-secret'),
        ];
        $this->call('POST', self::URI, [], [], [], $server, $compact)->assertStatus(403);
    }

    public function test_webhook_for_configured_number_is_accepted(): void
    {
        $response = $this->postRaw(
            $this->envelope([$this->textMessage('wamid.1', '965000000001', 'مرحبا')])
        );

        $response->assertStatus(200);
        $response->assertJson(['received' => 1]);
    }

    public function test_webhook_for_another_number_returns_200_with_zero_received(): void
    {
        Http::fake();

        $response = $this->postRaw(
            $this->envelope([$this->textMessage('wamid.1', '965000000001', 'مرحبا')], [], 'other-number-id')
        );

        $response->assertStatus(200);
        $response->assertJson(['received' => 0]);
        Http::assertNothingSent();
    }

    public function test_multiple_text_messages_are_acknowledged(): void
    {
        $response = $this->postRaw($this->envelope([
            $this->textMessage('wamid.1', '965000000001', 'one'),
            $this->textMessage('wamid.2', '965000000002', 'two'),
        ]));

        $response->assertStatus(200);
        $response->assertJson(['received' => 2]);
    }

    public function test_status_only_webhook_returns_200_with_zero_received(): void
    {
        $response = $this->postRaw(
            $this->envelope([], [['id' => 'wamid.1', 'status' => 'delivered']])
        );

        $response->assertStatus(200);
        $response->assertJson(['received' => 0]);
    }

    public function test_unsupported_message_type_is_ignored(): void
    {
        $response = $this->postRaw($this->envelope([[
            'from' => '965000000001',
            'id' => 'wamid.img-1',
            'timestamp' => '1750000000',
            'type' => 'image',
            'image' => ['mime_type' => 'image/jpeg', 'id' => 'media-1'],
        ]]));

        $response->assertStatus(200);
        $response->assertJson(['received' => 0]);
    }

    public function test_malformed_structures_do_not_crash_the_endpoint(): void
    {
        $this->postRaw(json_encode(['entry' => 'not-an-array']))->assertStatus(200);
        $this->postRaw(json_encode(['entry' => [['changes' => 'nope']]]))->assertStatus(200);
        $this->postRaw('this is not json')->assertStatus(200);
    }

    public function test_webhook_does_not_call_ai_service(): void
    {
        $this->mock(AiService::class, function ($mock): void {
            $mock->shouldNotReceive('decide');
        });

        $this->postRaw(
            $this->envelope([$this->textMessage('wamid.1', '965000000001', 'مرحبا')])
        )->assertStatus(200);
    }

    public function test_webhook_does_not_send_a_whatsapp_reply(): void
    {
        Http::fake();

        $this->postRaw(
            $this->envelope([$this->textMessage('wamid.1', '965000000001', 'مرحبا')])
        )->assertStatus(200);

        Http::assertNothingSent();
    }

    public function test_webhook_does_not_alter_conversation_state(): void
    {
        $conversation = Conversation::create(['phone_number' => '965000000001']);

        $this->postRaw(
            $this->envelope([$this->textMessage('wamid.1', '965000000001', 'مرحبا')])
        )->assertStatus(200);

        $fresh = $conversation->fresh();

        $this->assertSame(Conversation::STATUS_ACTIVE, $fresh->status);
        $this->assertFalse($fresh->needs_human);
        $this->assertSame(0, $fresh->messages()->count());
    }
}
