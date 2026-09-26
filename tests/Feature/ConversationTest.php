<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversation_can_be_created(): void
    {
        $conversation = Conversation::create([
            'phone_number' => '965000000001',
        ]);

        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'phone_number' => '965000000001',
        ]);
    }

    public function test_conversation_defaults_to_not_needing_human(): void
    {
        $conversation = Conversation::create([
            'phone_number' => '965000000002',
        ]);

        $this->assertFalse($conversation->needs_human);
        $this->assertSame(Conversation::STATUS_ACTIVE, $conversation->status);
        $this->assertFalse($conversation->fresh()->needs_human);
        $this->assertSame(Conversation::STATUS_ACTIVE, $conversation->fresh()->status);
    }

    public function test_conversation_can_be_marked_waiting_human(): void
    {
        $conversation = Conversation::create([
            'phone_number' => '965000000003',
        ]);

        $conversation->update([
            'status' => Conversation::STATUS_WAITING_HUMAN,
            'needs_human' => true,
        ]);

        $fresh = $conversation->fresh();

        $this->assertTrue($fresh->needs_human);
        $this->assertSame(Conversation::STATUS_WAITING_HUMAN, $fresh->status);
    }

    public function test_duplicate_conversation_phone_numbers_are_prevented(): void
    {
        Conversation::create([
            'phone_number' => '965000000010',
        ]);

        $this->expectException(QueryException::class);

        Conversation::create([
            'phone_number' => '965000000010',
        ]);
    }

    public function test_status_and_needs_human_change_together(): void
    {
        $conversation = Conversation::create([
            'phone_number' => '965000000011',
        ]);

        $conversation->markWaitingHuman();

        $fresh = $conversation->fresh();

        $this->assertSame(Conversation::STATUS_WAITING_HUMAN, $fresh->status);
        $this->assertTrue($fresh->needs_human);

        $conversation->resumeAutomation();

        $fresh = $conversation->fresh();

        $this->assertSame(Conversation::STATUS_ACTIVE, $fresh->status);
        $this->assertFalse($fresh->needs_human);
    }

    public function test_message_belongs_to_conversation(): void
    {
        $conversation = Conversation::create([
            'phone_number' => '965000000004',
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => Message::DIRECTION_INCOMING,
            'content' => 'عندكم خدمة إيجار؟',
        ]);

        $this->assertTrue($message->conversation->is($conversation));
    }

    public function test_conversation_has_many_messages(): void
    {
        $conversation = Conversation::create([
            'phone_number' => '965000000005',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => Message::DIRECTION_INCOMING,
            'content' => 'مرحبا',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => Message::DIRECTION_OUTGOING,
            'content' => 'أهلا وسهلا',
            'ai_decision' => Message::DECISION_REPLY,
        ]);

        $this->assertCount(2, $conversation->messages);
    }

    public function test_whatsapp_message_id_duplicate_protection(): void
    {
        $conversation = Conversation::create([
            'phone_number' => '965000000006',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'whatsapp_message_id' => 'wamid.duplicate-1',
            'direction' => Message::DIRECTION_INCOMING,
            'content' => 'أول رسالة',
        ]);

        $this->expectException(QueryException::class);

        Message::create([
            'conversation_id' => $conversation->id,
            'whatsapp_message_id' => 'wamid.duplicate-1',
            'direction' => Message::DIRECTION_INCOMING,
            'content' => 'نفس الرسالة مكررة',
        ]);
    }

    public function test_messages_without_whatsapp_id_can_be_stored(): void
    {
        $conversation = Conversation::create([
            'phone_number' => '965000000007',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => Message::DIRECTION_INCOMING,
            'content' => 'رسالة بدون معرف',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => Message::DIRECTION_OUTGOING,
            'content' => 'رد بدون معرف',
        ]);

        $this->assertCount(2, $conversation->fresh()->messages);
    }
}
