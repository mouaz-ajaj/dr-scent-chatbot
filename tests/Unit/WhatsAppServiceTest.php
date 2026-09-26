<?php

namespace Tests\Unit;

use App\Services\WhatsApp\WhatsAppException;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppServiceTest extends TestCase
{
    private function service(array $overrides = []): WhatsAppService
    {
        config()->set('services.whatsapp', array_merge([
            'access_token' => 'test-access-token',
            'phone_number_id' => '123456789',
            'api_version' => 'v22.0',
            'base_url' => 'https://graph.example.test',
        ], $overrides));

        return WhatsAppService::fromConfig();
    }

    public function test_sends_expected_graph_api_request(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.sent-1']]], 200)]);

        $id = $this->service()->sendText('965000000001', 'مرحبا');

        $this->assertSame('wamid.sent-1', $id);

        Http::assertSent(function (Request $request) {
            $this->assertSame('https://graph.example.test/v22.0/123456789/messages', $request->url());
            $this->assertSame('Bearer test-access-token', $request->header('Authorization')[0]);
            $this->assertSame([
                'messaging_product' => 'whatsapp',
                'to' => '965000000001',
                'type' => 'text',
                'text' => ['body' => 'مرحبا'],
            ], $request->data());

            return true;
        });
    }

    public function test_configured_version_and_phone_number_id_are_used(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.sent-2']]], 200)]);

        $this->service(['api_version' => 'v99.0', 'phone_number_id' => '555'])->sendText('965', 'hi');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/v99.0/555/messages'));
    }

    public function test_client_error_produces_controlled_failure(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Bad request.']], 400)]);

        $this->expectException(WhatsAppException::class);

        try {
            $this->service()->sendText('965', 'hi');
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_server_error_produces_controlled_failure_without_retry(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        try {
            $this->service()->sendText('965', 'hi');

            $this->fail('Expected a WhatsAppException.');
        } catch (WhatsAppException) {
            Http::assertSentCount(1);
        }
    }

    public function test_malformed_success_response_fails_safely(): void
    {
        Http::fake(['*' => Http::response(['messages' => []], 200)]);

        $this->expectException(WhatsAppException::class);

        $this->service()->sendText('965', 'hi');
    }

    public function test_missing_configuration_fails_safely(): void
    {
        Http::fake();

        try {
            $this->service(['access_token' => ''])->sendText('965', 'hi');

            $this->fail('Expected a WhatsAppException for a missing token.');
        } catch (WhatsAppException) {
            // Expected.
        }

        try {
            $this->service(['phone_number_id' => ''])->sendText('965', 'hi');

            $this->fail('Expected a WhatsAppException for a missing phone number ID.');
        } catch (WhatsAppException) {
            // Expected.
        }

        Http::assertNothingSent();
    }
}
