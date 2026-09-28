<?php

namespace App\Jobs;

use App\Data\AiHistoryMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Ai\AiService;
use App\Services\BusinessProfileService;
use App\Services\WhatsApp\WhatsAppException;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    /**
     * No automatic retries: this job has customer-facing side effects
     * (WhatsApp replies) that must never be duplicated.
     */
    public int $tries = 1;

    /**
     * Stay under the queue's retry_after so a timed-out worker cannot
     * overlap with a re-reserved copy of this job.
     */
    public int $timeout = 60;

    public function __construct(
        public readonly string $whatsappMessageId,
        public readonly string $senderPhone,
        public readonly string $recipientPhoneNumberId,
        public readonly string $body,
    ) {}

    public function handle(AiService $ai, WhatsAppService $whatsapp, BusinessProfileService $profiles): void
    {
        // Defense-in-depth alongside the controller filter: a queued job must
        // never process a message for a number other than the configured one
        // (stale jobs, manual dispatches, test/prod mixups). Fail closed.
        $configured = config('services.whatsapp.phone_number_id');

        if (! is_string($configured) || $configured === '' || $this->recipientPhoneNumberId !== $configured) {
            Log::warning('WhatsApp job ignored: recipient number mismatch.', [
                'whatsapp_message_id' => $this->whatsappMessageId,
            ]);

            return;
        }

        // Short DB transaction only: no external HTTP calls inside.
        $stored = DB::transaction(function (): ?array {
            try {
                $conversation = Conversation::firstOrCreate(['phone_number' => $this->senderPhone]);
            } catch (QueryException) {
                $conversation = Conversation::where('phone_number', $this->senderPhone)->firstOrFail();
            }

            try {
                $incoming = $conversation->messages()->create([
                    'whatsapp_message_id' => $this->whatsappMessageId,
                    'direction' => Message::DIRECTION_INCOMING,
                    'content' => $this->body,
                    'ai_decision' => null,
                ]);
            } catch (QueryException $e) {
                if ($this->isDuplicateMessage($e)) {
                    return null;
                }

                throw $e;
            }

            $conversation->update(['last_message_at' => now()]);

            return [$conversation, $incoming];
        });

        if ($stored === null) {
            return;
        }

        [$conversation, $incoming] = $stored;

        // Once handed to a human, the AI stays silent until explicitly resumed.
        if ($conversation->needs_human || $conversation->status === Conversation::STATUS_WAITING_HUMAN) {
            return;
        }

        try {
            $profile = $profiles->load();
        } catch (Throwable $e) {
            Log::warning('WhatsApp processing failed: business profile unavailable.', ['error' => $e::class]);
            $this->handOff($conversation, $incoming);

            return;
        }

        // Previous messages only: the current incoming message is matched by
        // ID boundary, never by timestamps, and never included in history.
        $history = AiHistoryMessage::fromModels(
            $conversation->messages()
                ->where('id', '<', $incoming->id)
                ->orderByDesc('id')
                ->limit(10)
                ->get()
                ->reverse()
                ->values()
        );

        try {
            $decision = $ai->decide($profile, $history, $this->body);
        } catch (Throwable $e) {
            Log::warning('WhatsApp processing failed: AI decision error.', ['error' => $e::class]);
            $this->handOff($conversation, $incoming);

            return;
        }

        if (! $decision->isReply() || ! is_string($decision->reply) || trim($decision->reply) === '') {
            $this->handOff($conversation, $incoming);

            return;
        }

        $incoming->update(['ai_decision' => Message::DECISION_REPLY]);

        try {
            $metaId = $whatsapp->sendText($conversation->phone_number, $decision->reply);
        } catch (WhatsAppException $e) {
            Log::warning('WhatsApp reply send failed.', ['error' => $e::class]);
            $conversation->markWaitingHuman();

            return;
        }

        // Meta already accepted the message: NEVER send again from here.
        // If local persistence fails, fail closed to human handling instead.
        try {
            DB::transaction(function () use ($conversation, $metaId, $decision): void {
                $conversation->messages()->create([
                    'whatsapp_message_id' => $metaId,
                    'direction' => Message::DIRECTION_OUTGOING,
                    'content' => $decision->reply,
                    'ai_decision' => Message::DECISION_REPLY,
                ]);

                $conversation->update(['last_message_at' => now()]);
            });
        } catch (Throwable $e) {
            Log::error('WhatsApp reply sent but outgoing persistence failed.', [
                'conversation_id' => $conversation->id,
                'whatsapp_message_id' => $metaId,
                'error' => $e::class,
            ]);

            try {
                $conversation->markWaitingHuman();
            } catch (Throwable) {
                // Database unusable; nothing more can be done without risking a duplicate send.
            }
        }
    }

    private function handOff(Conversation $conversation, Message $incoming): void
    {
        // DB writes only, kept together so no partial handoff state remains.
        DB::transaction(function () use ($conversation, $incoming): void {
            $incoming->update(['ai_decision' => Message::DECISION_HANDOFF]);
            $conversation->markWaitingHuman();
        });
    }

    private function isDuplicateMessage(QueryException $e): bool
    {
        if (($e->errorInfo[1] ?? null) !== 1062) {
            return false;
        }

        // MySQL duplicate entry for THIS incoming message: either the message
        // ID appears in the entry value, or the messages unique index is named.
        // Anything else (including conversation-phone races) is rethrown.
        return str_contains($e->getMessage(), "Duplicate entry '{$this->whatsappMessageId}'")
            || str_contains($e->getMessage(), 'messages_whatsapp_message_id_unique');
    }
}
