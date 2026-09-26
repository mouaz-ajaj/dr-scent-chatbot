<?php

namespace App\Services\WhatsApp;

use App\Data\IncomingWhatsAppMessage;

final class WhatsAppWebhookParser
{
    /**
     * Extract supported incoming text messages from a decoded Meta webhook payload.
     *
     * Handles batched payloads (entry[] > changes[] > value > messages[]) and
     * gracefully ignores non-WhatsApp objects, non-message fields, status
     * updates, unsupported message types, messages without a receiving phone
     * number ID, and malformed optional structures.
     *
     * @param  array<string, mixed>  $payload
     * @return list<IncomingWhatsAppMessage>
     */
    public function parse(array $payload): array
    {
        $messages = [];

        if (($payload['object'] ?? null) !== 'whatsapp_business_account') {
            return [];
        }

        $entries = $payload['entry'] ?? [];

        if (! is_array($entries)) {
            return [];
        }

        foreach ($entries as $entry) {
            $changes = is_array($entry) ? ($entry['changes'] ?? []) : [];

            if (! is_array($changes)) {
                continue;
            }

            foreach ($changes as $change) {
                if (! is_array($change) || ($change['field'] ?? null) !== 'messages') {
                    continue;
                }

                $value = $change['value'] ?? [];

                if (! is_array($value)) {
                    continue;
                }

                $metadata = $value['metadata'] ?? null;
                $recipientId = is_array($metadata) ? ($metadata['phone_number_id'] ?? null) : null;

                if (! is_string($recipientId) || $recipientId === '') {
                    continue;
                }

                $items = $value['messages'] ?? [];

                if (! is_array($items)) {
                    continue;
                }

                foreach ($items as $item) {
                    $message = $this->parseTextMessage($item, $recipientId);

                    if ($message !== null) {
                        $messages[] = $message;
                    }
                }
            }
        }

        return $messages;
    }

    private function parseTextMessage(mixed $item, string $recipientId): ?IncomingWhatsAppMessage
    {
        if (! is_array($item) || ($item['type'] ?? null) !== 'text') {
            return null;
        }

        $id = $item['id'] ?? null;
        $from = $item['from'] ?? null;
        $text = $item['text'] ?? null;
        $body = is_array($text) ? ($text['body'] ?? null) : null;

        if (! is_string($id) || $id === ''
            || ! is_string($from) || $from === ''
            || ! is_string($body) || $body === ''
        ) {
            return null;
        }

        return new IncomingWhatsAppMessage($id, $from, $recipientId, $body);
    }
}
