<?php

namespace App\Data;

use App\Models\Message;

final readonly class AiHistoryMessage
{
    public const ROLE_CUSTOMER = 'customer';

    public const ROLE_ASSISTANT = 'assistant';

    public function __construct(
        public string $role,
        public string $content,
    ) {}

    /**
     * Convert one Eloquent message into its minimal AI-safe representation.
     *
     * Only role + content survive: database IDs, timestamps, WhatsApp IDs,
     * statuses, and internal AI decisions are never exposed to the model.
     */
    public static function fromMessage(Message $message): self
    {
        $role = $message->direction === Message::DIRECTION_OUTGOING
            ? self::ROLE_ASSISTANT
            : self::ROLE_CUSTOMER;

        return new self($role, (string) $message->content);
    }

    /**
     * @param  iterable<Message>  $messages
     * @return list<array{role: string, content: string}>
     */
    public static function fromModels(iterable $messages): array
    {
        $history = [];

        foreach ($messages as $message) {
            $item = self::fromMessage($message);

            if (trim($item->content) === '') {
                continue;
            }

            $history[] = ['role' => $item->role, 'content' => $item->content];
        }

        return $history;
    }
}
