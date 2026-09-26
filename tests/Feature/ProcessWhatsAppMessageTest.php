<?php

namespace Tests\Feature;

use App\Data\AiReplyDecision;
use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Ai\AiService;
use App\Services\BusinessProfileService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ProcessWhatsAppMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.whatsapp', [
            'access_token' => 'test-token',
            'phone_number_id' => '999',
            'api_version' => 'v22.0',
            'base_url' => 'https://graph.example.test',
        ]);
    }

    private function job(array $overrides = []): ProcessWhatsAppMessage
    {
        return new ProcessWhatsAppMessage(
            $overrides['whatsappMessageId'] ?? 'wamid.current-1',
            $overrides['senderPhone'] ?? '965000000001',
            $overrides['recipientPhoneNumberId'] ?? '999',
            $overrides['body'] ?? 'مرحبا',
        );
    }

    private function aiReply(string $reply = 'أهلا وسهلا.'): mixed
    {
        $ai = $this->mock(AiService::class);
        $ai->shouldReceive('decide')->once()->andReturn(AiReplyDecision::reply($reply, 'Supported.'));

        return $ai;
    }

    private function fakeSend(string $metaId = 'wamid.meta-1'): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => $metaId]]], 200)]);
    }

    public function test_new_customer_creates_conversation_and_stores_incoming(): void
    {
        $this->fakeSend();

        $this->job()->handle($this->aiReply(), WhatsAppService::fromConfig(), new BusinessProfileService);

        $this->assertDatabaseHas('conversations', ['phone_number' => '965000000001']);
        $this->assertDatabaseHas('messages', [
            'whatsapp_message_id' => 'wamid.current-1',
            'direction' => Message::DIRECTION_INCOMING,
            'content' => 'مرحبا',
        ]);
        $this->assertNotNull(Conversation::first()->fresh()->last_message_at);
    }

    public function test_ai_receives_profile_history_and_current_message(): void
    {
        $this->fakeSend();

        $captured = [];

        $ai = $this->mock(AiService::class);
        $ai->shouldReceive('decide')->once()->andReturnUsing(
            function (array $profile, array $history, string $message) use (&$captured) {
                $captured = compact('profile', 'history', 'message');

                return AiReplyDecision::reply('ok', 'r');
            }
        );

        Conversation::create(['phone_number' => '965000000001'])->messages()->create([
            'whatsapp_message_id' => 'wamid.old-1',
            'direction' => Message::DIRECTION_INCOMING,
            'content' => 'سؤال قديم',
        ]);

        $this->job(['body' => 'سؤال جديد'])->handle($ai, WhatsAppService::fromConfig(), new BusinessProfileService);

        $this->assertSame('DR.SCENT', $captured['profile']['business']['name']);
        $this->assertSame('سؤال جديد', $captured['message']);
        $this->assertSame([['role' => 'customer', 'content' => 'سؤال قديم']], $captured['history']);
    }

    public function test_history_contains_only_previous_10_messages_chronologically(): void
    {
        $this->fakeSend();

        $conversation = Conversation::create(['phone_number' => '965000000001']);

        for ($i = 1; $i <= 12; $i++) {
            $conversation->messages()->create([
                'whatsapp_message_id' => "wamid.old-{$i}",
                'direction' => $i === 6 ? Message::DIRECTION_OUTGOING : Message::DIRECTION_INCOMING,
                'content' => sprintf('old-%02d', $i),
            ]);
        }

        $captured = [];

        $ai = $this->mock(AiService::class);
        $ai->shouldReceive('decide')->once()->andReturnUsing(
            function (array $profile, array $history, string $message) use (&$captured) {
                $captured = compact('history', 'message');

                return AiReplyDecision::reply('ok', 'r');
            }
        );

        $this->job(['body' => 'current?'])->handle($ai, WhatsAppService::fromConfig(), new BusinessProfileService);

        $this->assertCount(10, $captured['history']);
        $this->assertSame('old-03', $captured['history'][0]['content']);
        $this->assertSame('old-12', $captured['history'][9]['content']);

        $contents = array_column($captured['history'], 'content');
        $this->assertNotContains('current?', $contents);
        $this->assertNotContains('old-01', $contents);
        $this->assertNotContains('old-02', $contents);

        $roles = array_column($captured['history'], 'role', 'content');
        $this->assertSame('assistant', $roles['old-06']);
        $this->assertSame('customer', $roles['old-07']);
    }

    public function test_blank_previous_messages_are_excluded_from_history(): void
    {
        $this->fakeSend();

        $conversation = Conversation::create(['phone_number' => '965000000001']);
        $conversation->messages()->create([
            'direction' => Message::DIRECTION_INCOMING,
            'content' => '   ',
        ]);

        $captured = [];

        $ai = $this->mock(AiService::class);
        $ai->shouldReceive('decide')->once()->andReturnUsing(
            function (array $profile, array $history) use (&$captured) {
                $captured = $history;

                return AiReplyDecision::reply('ok', 'r');
            }
        );

        $this->job()->handle($ai, WhatsAppService::fromConfig(), new BusinessProfileService);

        $this->assertSame([], $captured);
    }

    public function test_reply_sends_once_and_stores_outgoing_with_meta_id(): void
    {
        $this->fakeSend('wamid.meta-9');

        $this->job()->handle($this->aiReply('رد واضح.'), WhatsAppService::fromConfig(), new BusinessProfileService);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            return $request['to'] === '965000000001' && $request['text']['body'] === 'رد واضح.';
        });

        $this->assertDatabaseHas('messages', [
            'whatsapp_message_id' => 'wamid.meta-9',
            'direction' => Message::DIRECTION_OUTGOING,
            'content' => 'رد واضح.',
            'ai_decision' => Message::DECISION_REPLY,
        ]);
        $this->assertDatabaseHas('messages', [
            'whatsapp_message_id' => 'wamid.current-1',
            'ai_decision' => Message::DECISION_REPLY,
        ]);
    }

    public function test_handoff_sends_nothing_and_marks_waiting_human(): void
    {
        Http::fake();

        $ai = $this->mock(AiService::class);
        $ai->shouldReceive('decide')->once()->andReturn(AiReplyDecision::handoff('Missing info.'));

        $this->job()->handle($ai, WhatsAppService::fromConfig(), new BusinessProfileService);

        Http::assertNothingSent();

        $conversation = Conversation::first()->fresh();
        $this->assertSame(Conversation::STATUS_WAITING_HUMAN, $conversation->status);
        $this->assertTrue($conversation->needs_human);

        $this->assertDatabaseHas('messages', [
            'whatsapp_message_id' => 'wamid.current-1',
            'ai_decision' => Message::DECISION_HANDOFF,
        ]);
        $this->assertSame(1, Message::count());
    }

    public function test_waiting_human_message_is_stored_without_ai_or_send(): void
    {
        Http::fake();

        $conversation = Conversation::create(['phone_number' => '965000000001']);
        $conversation->markWaitingHuman();
        $conversation->messages()->create([
            'whatsapp_message_id' => 'wamid.old-1',
            'direction' => Message::DIRECTION_INCOMING,
            'content' => 'قديم',
        ]);

        $ai = $this->mock(AiService::class, function ($mock): void {
            $mock->shouldNotReceive('decide');
        });

        $this->job()->handle($ai, WhatsAppService::fromConfig(), new BusinessProfileService);

        Http::assertNothingSent();

        $this->assertSame(2, $conversation->messages()->count());
        $this->assertDatabaseHas('messages', [
            'whatsapp_message_id' => 'wamid.current-1',
            'ai_decision' => null,
        ]);

        $fresh = $conversation->fresh();
        $this->assertSame(Conversation::STATUS_WAITING_HUMAN, $fresh->status);
        $this->assertTrue($fresh->needs_human);
    }

    public function test_duplicate_processing_is_idempotent(): void
    {
        $this->fakeSend();

        $ai = $this->mock(AiService::class);
        $ai->shouldReceive('decide')->once()->andReturn(AiReplyDecision::reply('مرة واحدة.', 'r'));

        $service = WhatsAppService::fromConfig();
        $profiles = new BusinessProfileService;

        $this->job()->handle($ai, $service, $profiles);
        $this->job()->handle($ai, $service, $profiles);

        $this->assertSame(1, Message::where('direction', Message::DIRECTION_INCOMING)->count());
        $this->assertSame(2, Message::count());
        Http::assertSentCount(1);
    }

    public function test_profile_failure_marks_handoff_without_send(): void
    {
        Http::fake();

        $profiles = new class extends BusinessProfileService
        {
            public function load(): array
            {
                throw new RuntimeException('Profile unavailable.');
            }
        };

        $ai = $this->mock(AiService::class, function ($mock): void {
            $mock->shouldNotReceive('decide');
        });

        $this->job()->handle($ai, WhatsAppService::fromConfig(), $profiles);

        Http::assertNothingSent();

        $conversation = Conversation::first()->fresh();
        $this->assertSame(Conversation::STATUS_WAITING_HUMAN, $conversation->status);
        $this->assertDatabaseHas('messages', [
            'whatsapp_message_id' => 'wamid.current-1',
            'ai_decision' => Message::DECISION_HANDOFF,
        ]);
    }

    public function test_ai_error_marks_handoff_without_send(): void
    {
        Http::fake();

        $ai = $this->mock(AiService::class);
        $ai->shouldReceive('decide')->once()->andThrow(new RuntimeException('AI exploded.'));

        $this->job()->handle($ai, WhatsAppService::fromConfig(), new BusinessProfileService);

        Http::assertNothingSent();

        $conversation = Conversation::first()->fresh();
        $this->assertSame(Conversation::STATUS_WAITING_HUMAN, $conversation->status);
        $this->assertDatabaseHas('messages', [
            'whatsapp_message_id' => 'wamid.current-1',
            'ai_decision' => Message::DECISION_HANDOFF,
        ]);
    }

    public function test_send_failure_marks_waiting_human_without_retry_or_outgoing_row(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Boom.'], 500)]);

        $this->job()->handle($this->aiReply('سيتم الإرسال؟'), WhatsAppService::fromConfig(), new BusinessProfileService);

        Http::assertSentCount(1);

        $conversation = Conversation::first()->fresh();
        $this->assertSame(Conversation::STATUS_WAITING_HUMAN, $conversation->status);
        $this->assertTrue($conversation->needs_human);

        // The AI decision stands; transport failure does not rewrite it.
        $this->assertDatabaseHas('messages', [
            'whatsapp_message_id' => 'wamid.current-1',
            'ai_decision' => Message::DECISION_REPLY,
        ]);
        $this->assertSame(0, Message::where('direction', Message::DIRECTION_OUTGOING)->count());
    }

    public function test_ai_and_whatsapp_run_outside_db_transactions(): void
    {
        $this->fakeSend();

        // RefreshDatabase itself holds one open transaction; the job must not
        // open any additional transaction around the external HTTP calls.
        $baseline = DB::transactionLevel();

        $levels = [];

        $ai = $this->mock(AiService::class);
        $ai->shouldReceive('decide')->once()->andReturnUsing(function () use (&$levels) {
            $levels['ai'] = DB::transactionLevel();

            return AiReplyDecision::reply('ok', 'r');
        });

        Http::fake(function () use (&$levels) {
            $levels['http'] = DB::transactionLevel();

            return Http::response(['messages' => [['id' => 'wamid.meta-1']]], 200);
        });

        $this->job()->handle($ai, WhatsAppService::fromConfig(), new BusinessProfileService);

        $this->assertSame($baseline, $levels['ai']);
        $this->assertSame($baseline, $levels['http']);
    }

    public function test_job_does_not_retry_processing(): void
    {
        $job = new ProcessWhatsAppMessage('wamid.x', '965', '999', 'hi');

        $this->assertSame(1, $job->tries);
        $this->assertFalse(isset($job->backoff));
    }
}
