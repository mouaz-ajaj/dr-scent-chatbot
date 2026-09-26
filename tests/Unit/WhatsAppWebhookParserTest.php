<?php

namespace Tests\Unit;

use App\Data\IncomingWhatsAppMessage;
use App\Services\WhatsApp\WhatsAppWebhookParser;
use Tests\TestCase;

class WhatsAppWebhookParserTest extends TestCase
{
    private function envelope(array $messages = [], array $statuses = [], string $phoneNumberId = '999'): array
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

        return [
            'object' => 'whatsapp_business_account',
            'entry' => [
                ['id' => 'waba-1', 'changes' => [['value' => $value, 'field' => 'messages']]],
            ],
        ];
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

    public function test_one_text_message_parses_correctly(): void
    {
        $messages = (new WhatsAppWebhookParser)->parse(
            $this->envelope([$this->textMessage('wamid.1', '965000000001', 'مرحبا')])
        );

        $this->assertCount(1, $messages);
        $this->assertInstanceOf(IncomingWhatsAppMessage::class, $messages[0]);
        $this->assertSame('wamid.1', $messages[0]->whatsappMessageId);
        $this->assertSame('965000000001', $messages[0]->senderPhone);
        $this->assertSame('مرحبا', $messages[0]->body);
    }

    public function test_multiple_text_messages_across_batches_all_parse(): void
    {
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                ['id' => 'waba-1', 'changes' => [
                    ['value' => ['metadata' => ['phone_number_id' => '999'], 'messages' => [
                        $this->textMessage('wamid.1', '965000000001', 'one'),
                        $this->textMessage('wamid.2', '965000000002', 'two'),
                    ]], 'field' => 'messages'],
                ]],
                ['id' => 'waba-1', 'changes' => [
                    ['value' => ['metadata' => ['phone_number_id' => '999'], 'messages' => [
                        $this->textMessage('wamid.3', '965000000001', 'three'),
                    ]], 'field' => 'messages'],
                ]],
            ],
        ];

        $messages = (new WhatsAppWebhookParser)->parse($payload);

        $this->assertSame(['wamid.1', 'wamid.2', 'wamid.3'], array_map(
            fn (IncomingWhatsAppMessage $m) => $m->whatsappMessageId, $messages
        ));
    }

    public function test_status_only_webhook_produces_zero_messages(): void
    {
        $messages = (new WhatsAppWebhookParser)->parse(
            $this->envelope([], [['id' => 'wamid.1', 'status' => 'delivered']])
        );

        $this->assertSame([], $messages);
    }

    public function test_unsupported_message_type_is_ignored(): void
    {
        $messages = (new WhatsAppWebhookParser)->parse(
            $this->envelope([[
                'from' => '965000000001',
                'id' => 'wamid.img-1',
                'timestamp' => '1750000000',
                'type' => 'image',
                'image' => ['mime_type' => 'image/jpeg', 'id' => 'media-1'],
            ]])
        );

        $this->assertSame([], $messages);
    }

    public function test_malformed_optional_structures_return_empty_list(): void
    {
        $parser = new WhatsAppWebhookParser;

        $this->assertSame([], $parser->parse([]));
        $this->assertSame([], $parser->parse(['entry' => 'not-an-array']));
        $this->assertSame([], $parser->parse(['entry' => [['changes' => 'nope']]]));
        $this->assertSame([], $parser->parse(['entry' => [['changes' => [['value' => ['messages' => 'nope']]]]]]));
        $this->assertSame([], $parser->parse(['entry' => [['changes' => [['value' => ['messages' => [['type' => 'text']]]]]]]]));
        $this->assertSame([], $parser->parse(['entry' => [['changes' => [['value' => ['messages' => [['type' => 'text', 'id' => 'wamid.1', 'from' => '965', 'text' => 'oops']]]]]]]]));
    }

    public function test_sender_and_message_id_are_preserved_exactly(): void
    {
        $messages = (new WhatsAppWebhookParser)->parse(
            $this->envelope([$this->textMessage('wamid.HBgMNTY1-fake-ID_123', '96536548601', 'test')])
        );

        $this->assertSame('wamid.HBgMNTY1-fake-ID_123', $messages[0]->whatsappMessageId);
        $this->assertSame('96536548601', $messages[0]->senderPhone);
    }

    public function test_recipient_phone_number_id_is_preserved_exactly(): void
    {
        $messages = (new WhatsAppWebhookParser)->parse(
            $this->envelope([$this->textMessage('wamid.1', '965', 'hi')], [], 'phone-id-ABC-123')
        );

        $this->assertCount(1, $messages);
        $this->assertSame('phone-id-ABC-123', $messages[0]->recipientPhoneNumberId);
    }

    public function test_message_without_metadata_phone_number_id_is_ignored(): void
    {
        $parser = new WhatsAppWebhookParser;
        $message = [$this->textMessage('wamid.1', '965', 'hi')];

        $noMetadata = ['object' => 'whatsapp_business_account', 'entry' => [
            ['id' => 'waba-1', 'changes' => [['value' => ['messages' => $message], 'field' => 'messages']]],
        ]];
        $this->assertSame([], $parser->parse($noMetadata));

        $emptyMetadata = ['object' => 'whatsapp_business_account', 'entry' => [
            ['id' => 'waba-1', 'changes' => [['value' => ['metadata' => [], 'messages' => $message], 'field' => 'messages']]],
        ]];
        $this->assertSame([], $parser->parse($emptyMetadata));

        $blankId = ['object' => 'whatsapp_business_account', 'entry' => [
            ['id' => 'waba-1', 'changes' => [['value' => ['metadata' => ['phone_number_id' => ''], 'messages' => $message], 'field' => 'messages']]],
        ]];
        $this->assertSame([], $parser->parse($blankId));
    }

    public function test_non_whatsapp_object_is_ignored(): void
    {
        $payload = $this->envelope([$this->textMessage('wamid.1', '965', 'hi')]);
        $payload['object'] = 'page';

        $this->assertSame([], (new WhatsAppWebhookParser)->parse($payload));
    }

    public function test_non_message_field_is_ignored(): void
    {
        $payload = $this->envelope([$this->textMessage('wamid.1', '965', 'hi')]);
        $payload['entry'][0]['changes'][0]['field'] = 'history';

        $this->assertSame([], (new WhatsAppWebhookParser)->parse($payload));
    }
}
