<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsappResumeTest extends TestCase
{
    use RefreshDatabase;

    public function test_resume_changes_waiting_human_to_active(): void
    {
        $conversation = Conversation::create(['phone_number' => '965000000001']);
        $conversation->markWaitingHuman();

        $this->artisan('whatsapp:resume', ['phone' => '965000000001'])->assertSuccessful();

        $fresh = $conversation->fresh();
        $this->assertSame(Conversation::STATUS_ACTIVE, $fresh->status);
        $this->assertFalse($fresh->needs_human);
    }

    public function test_resume_does_not_delete_history(): void
    {
        $conversation = Conversation::create(['phone_number' => '965000000001']);
        $conversation->messages()->create([
            'whatsapp_message_id' => 'wamid.old-1',
            'direction' => Message::DIRECTION_INCOMING,
            'content' => 'قديم',
        ]);
        $conversation->markWaitingHuman();

        $this->artisan('whatsapp:resume', ['phone' => '965000000001'])->assertSuccessful();

        $this->assertSame(1, $conversation->messages()->count());
        $this->assertSame('965000000001', Conversation::sole()->phone_number);
    }

    public function test_unknown_phone_returns_failure(): void
    {
        $this->artisan('whatsapp:resume', ['phone' => '965000000000'])
            ->expectsOutput('No conversation found for phone [965000000000].')
            ->assertFailed();
    }
}
