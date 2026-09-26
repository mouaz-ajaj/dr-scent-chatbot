<?php

namespace Tests\Unit;

use App\Data\AiHistoryMessage;
use App\Models\Message;
use App\Services\Ai\AiService;
use App\Services\Ai\GeminiService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiServiceTest extends TestCase
{
    private function service(array $overrides = []): GeminiService
    {
        config()->set('services.gemini', array_merge([
            'key' => 'test-key',
            'model' => 'test-model',
            'base_url' => 'https://example.test/v1beta',
            'timeout' => 5,
        ], $overrides));

        return GeminiService::fromConfig();
    }

    /**
     * @return array<string, mixed>
     */
    private function geminiResponse(mixed $decision): array
    {
        $text = is_string($decision) ? $decision : json_encode($decision);

        return [
            'candidates' => [
                ['content' => ['parts' => [['text' => $text]]]],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(): array
    {
        return ['business' => ['name' => 'DR.SCENT'], 'faq' => []];
    }

    public function test_supported_question_returns_reply(): void
    {
        Http::fake(['*' => Http::response($this->geminiResponse([
            'action' => 'reply',
            'reply' => 'نعم، عنا خدمة إيجار شهرية.',
            'reason' => 'Supported by business profile.',
        ]), 200)]);

        $decision = $this->service()->decide($this->profile(), [], 'عندكم إيجار؟');

        $this->assertTrue($decision->isReply());
        $this->assertSame('نعم، عنا خدمة إيجار شهرية.', $decision->reply);
    }

    public function test_missing_information_returns_handoff_with_null_reply(): void
    {
        Http::fake(['*' => Http::response($this->geminiResponse([
            'action' => 'handoff',
            'reply' => null,
            'reason' => 'Required information is missing.',
        ]), 200)]);

        $decision = $this->service()->decide($this->profile(), [], 'قديش سعر الجهاز؟');

        $this->assertTrue($decision->isHandoff());
        $this->assertNull($decision->reply);
    }

    public function test_handoff_discards_accidental_reply_text(): void
    {
        Http::fake(['*' => Http::response($this->geminiResponse([
            'action' => 'handoff',
            'reply' => 'Some text the model should not have sent.',
            'reason' => 'Missing info.',
        ]), 200)]);

        $decision = $this->service()->decide($this->profile(), [], 'قديش سعر الجهاز؟');

        $this->assertTrue($decision->isHandoff());
        $this->assertNull($decision->reply);
    }

    public function test_reply_with_blank_text_becomes_handoff(): void
    {
        Http::fake(['*' => Http::response($this->geminiResponse([
            'action' => 'reply',
            'reply' => '   ',
            'reason' => 'Empty.',
        ]), 200)]);

        $decision = $this->service()->decide($this->profile(), [], 'مرحبا');

        $this->assertTrue($decision->isHandoff());
        $this->assertNull($decision->reply);
    }

    public function test_unknown_action_becomes_handoff(): void
    {
        Http::fake(['*' => Http::response($this->geminiResponse([
            'action' => 'escalate',
            'reply' => 'Hello.',
            'reason' => 'Unknown.',
        ]), 200)]);

        $decision = $this->service()->decide($this->profile(), [], 'مرحبا');

        $this->assertTrue($decision->isHandoff());
        $this->assertNull($decision->reply);
    }

    public function test_malformed_json_becomes_handoff(): void
    {
        Http::fake(['*' => Http::response($this->geminiResponse('this is not json'), 200)]);

        $decision = $this->service()->decide($this->profile(), [], 'مرحبا');

        $this->assertTrue($decision->isHandoff());
        $this->assertNull($decision->reply);
    }

    public function test_missing_candidates_become_handoff(): void
    {
        Http::fake(['*' => Http::response(['candidates' => []], 200)]);

        $decision = $this->service()->decide($this->profile(), [], 'مرحبا');

        $this->assertTrue($decision->isHandoff());
        $this->assertNull($decision->reply);
    }

    public function test_http_failure_becomes_handoff_after_one_retry(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        $decision = $this->service()->decide($this->profile(), [], 'مرحبا');

        $this->assertTrue($decision->isHandoff());
        $this->assertNull($decision->reply);
        Http::assertSentCount(2);
    }

    public function test_transient_failure_recovers_with_one_retry(): void
    {
        Http::fake(['*' => Http::sequence()
            ->pushStatus(500)
            ->push($this->geminiResponse([
                'action' => 'reply',
                'reply' => 'Recovered.',
                'reason' => 'Retry worked.',
            ])),
        ]);

        $decision = $this->service()->decide($this->profile(), [], 'مرحبا');

        $this->assertTrue($decision->isReply());
        $this->assertSame('Recovered.', $decision->reply);
    }

    public function test_connection_failure_becomes_handoff(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out.');
        });

        $decision = $this->service()->decide($this->profile(), [], 'مرحبا');

        $this->assertTrue($decision->isHandoff());
        $this->assertNull($decision->reply);
    }

    public function test_connection_failure_recovers_with_one_retry(): void
    {
        $attempts = 0;

        Http::fake(function () use (&$attempts) {
            $attempts++;

            if ($attempts === 1) {
                throw new ConnectionException('Connection timed out.');
            }

            return Http::response($this->geminiResponse([
                'action' => 'reply',
                'reply' => 'Recovered.',
                'reason' => 'Retry worked.',
            ]), 200);
        });

        $decision = $this->service()->decide($this->profile(), [], 'مرحبا');

        $this->assertTrue($decision->isReply());
        $this->assertSame('Recovered.', $decision->reply);
        $this->assertSame(2, $attempts);
    }

    public function test_rate_limit_becomes_handoff_without_retry(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Quota exceeded.']], 429)]);

        $decision = $this->service()->decide($this->profile(), [], 'مرحبا');

        $this->assertTrue($decision->isHandoff());
        $this->assertNull($decision->reply);
        Http::assertSentCount(1);
    }

    public function test_request_contains_profile_history_and_message(): void
    {
        Http::fake(['*' => Http::response($this->geminiResponse([
            'action' => 'handoff',
            'reply' => null,
            'reason' => 'Missing info.',
        ]), 200)]);

        $history = [
            ['role' => 'customer', 'content' => 'عندكم إيجار؟'],
            ['role' => 'assistant', 'content' => 'نعم، عنا اشتراك شهري.'],
        ];

        $this->service()->decide($this->profile(), $history, 'شو بيشمل؟');

        Http::assertSent(function (Request $request) {
            $payload = $request->data();

            $this->assertStringContainsString('DR.SCENT', $payload['contents'][0]['parts'][0]['text']);
            $this->assertStringContainsString('BUSINESS PROFILE', $payload['contents'][0]['parts'][0]['text']);

            $this->assertSame('user', $payload['contents'][1]['role']);
            $this->assertSame('عندكم إيجار؟', $payload['contents'][1]['parts'][0]['text']);
            $this->assertSame('model', $payload['contents'][2]['role']);
            $this->assertSame('نعم، عنا اشتراك شهري.', $payload['contents'][2]['parts'][0]['text']);

            $last = $payload['contents'][count($payload['contents']) - 1];
            $this->assertSame('user', $last['role']);
            $this->assertStringContainsString('شو بيشمل؟', $last['parts'][0]['text']);

            return true;
        });
    }

    public function test_history_is_capped_to_recent_messages(): void
    {
        Http::fake(['*' => Http::response($this->geminiResponse([
            'action' => 'handoff',
            'reply' => null,
            'reason' => 'Missing info.',
        ]), 200)]);

        $history = [];
        for ($i = 1; $i <= 12; $i++) {
            $history[] = ['role' => 'customer', 'content' => "message {$i}"];
        }

        $this->service()->decide($this->profile(), $history, 'last?');

        Http::assertSent(function (Request $request) {
            $payload = $request->data();

            // 1 profile turn + 10 history turns + 1 current message turn.
            $this->assertCount(12, $payload['contents']);
            $this->assertSame('message 3', $payload['contents'][1]['parts'][0]['text']);

            return true;
        });
    }

    public function test_database_models_are_transformed_without_internal_metadata(): void
    {
        $incoming = new Message([
            'conversation_id' => 99,
            'whatsapp_message_id' => 'wamid.secret-1',
            'direction' => Message::DIRECTION_INCOMING,
            'content' => 'عندكم إيجار؟',
            'ai_decision' => Message::DECISION_HANDOFF,
        ]);
        $incoming->id = 7;

        $outgoing = new Message([
            'conversation_id' => 99,
            'direction' => Message::DIRECTION_OUTGOING,
            'content' => 'نعم.',
        ]);

        $history = AiHistoryMessage::fromModels([$incoming, $outgoing]);

        $this->assertSame([
            ['role' => 'customer', 'content' => 'عندكم إيجار؟'],
            ['role' => 'assistant', 'content' => 'نعم.'],
        ], $history);

        Http::fake(['*' => Http::response($this->geminiResponse([
            'action' => 'reply',
            'reply' => 'OK.',
            'reason' => 'Fine.',
        ]), 200)]);

        $this->service()->decide($this->profile(), $history, 'تمام');

        Http::assertSent(function (Request $request) {
            $raw = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            $this->assertStringNotContainsString('wamid.secret-1', $raw);
            $this->assertStringNotContainsString('conversation_id', $raw);
            $this->assertStringNotContainsString('ai_decision', $raw);

            return true;
        });
    }

    public function test_model_and_key_come_from_config(): void
    {
        Http::fake(['*' => Http::response($this->geminiResponse([
            'action' => 'handoff',
            'reply' => null,
            'reason' => 'Missing info.',
        ]), 200)]);

        $this->service(['key' => 'cfg-key', 'model' => 'cfg-model-123'])->decide($this->profile(), [], 'مرحبا');

        Http::assertSent(function (Request $request) {
            $this->assertStringContainsString('cfg-model-123', $request->url());
            $this->assertTrue($request->hasHeader('x-goog-api-key'));

            return true;
        });
    }

    public function test_request_uses_structured_output_schema(): void
    {
        Http::fake(['*' => Http::response($this->geminiResponse([
            'action' => 'handoff',
            'reply' => null,
            'reason' => 'Missing info.',
        ]), 200)]);

        $this->service()->decide($this->profile(), [], 'مرحبا');

        Http::assertSent(function (Request $request) {
            $schema = $request->data()['generationConfig']['responseSchema'];

            $this->assertSame('application/json', $request->data()['generationConfig']['responseMimeType']);
            $this->assertSame(['action', 'reply', 'reason'], array_keys($schema['properties']));
            $this->assertSame(['reply', 'handoff'], $schema['properties']['action']['enum']);

            return true;
        });
    }

    public function test_ai_service_resolves_to_gemini(): void
    {
        $this->assertInstanceOf(GeminiService::class, $this->app->make(AiService::class));
    }

    public function test_reply_with_missing_reason_becomes_handoff(): void
    {
        Http::fake(['*' => Http::response($this->geminiResponse([
            'action' => 'reply',
            'reply' => 'نعم.',
        ]), 200)]);

        $decision = $this->service()->decide($this->profile(), [], 'مرحبا');

        $this->assertTrue($decision->isHandoff());
        $this->assertNull($decision->reply);
    }

    public function test_reply_with_blank_reason_becomes_handoff(): void
    {
        Http::fake(['*' => Http::response($this->geminiResponse([
            'action' => 'reply',
            'reply' => 'نعم.',
            'reason' => '   ',
        ]), 200)]);

        $decision = $this->service()->decide($this->profile(), [], 'مرحبا');

        $this->assertTrue($decision->isHandoff());
        $this->assertNull($decision->reply);
    }

    public function test_handoff_with_missing_reason_becomes_handoff_safely(): void
    {
        Http::fake(['*' => Http::response($this->geminiResponse([
            'action' => 'handoff',
            'reply' => 'Text that must be discarded.',
        ]), 200)]);

        $decision = $this->service()->decide($this->profile(), [], 'مرحبا');

        $this->assertTrue($decision->isHandoff());
        $this->assertNull($decision->reply);
    }
}
