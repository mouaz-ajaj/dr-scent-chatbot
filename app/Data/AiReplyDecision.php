<?php

namespace App\Data;

use InvalidArgumentException;

final readonly class AiReplyDecision
{
    public const ACTION_REPLY = 'reply';

    public const ACTION_HANDOFF = 'handoff';

    public function __construct(
        public string $action,
        public ?string $reply,
        public string $reason,
    ) {}

    public static function reply(string $reply, string $reason = ''): self
    {
        return new self(self::ACTION_REPLY, $reply, $reason);
    }

    public static function handoff(string $reason = ''): self
    {
        return new self(self::ACTION_HANDOFF, null, $reason);
    }

    /**
     * Build a decision from decoded model output.
     *
     * Enforces the application invariants:
     * - only reply/handoff actions are accepted;
     * - reason must exist, be a string, and not be blank;
     * - handoff always carries a null reply, even if the model sent text;
     * - reply must be a non-blank string, otherwise the payload is rejected.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException When the payload violates the contract.
     */
    public static function fromArray(array $data): self
    {
        $action = $data['action'] ?? null;

        if ($action !== self::ACTION_REPLY && $action !== self::ACTION_HANDOFF) {
            throw new InvalidArgumentException('AI decision has an unknown action.');
        }

        $reason = $data['reason'] ?? null;

        if (! is_string($reason) || trim($reason) === '') {
            throw new InvalidArgumentException('AI decision has a missing or blank reason.');
        }

        if ($action === self::ACTION_HANDOFF) {
            return self::handoff($reason);
        }

        $reply = $data['reply'] ?? null;

        if (! is_string($reply) || trim($reply) === '') {
            throw new InvalidArgumentException('AI reply decision has a missing or blank reply.');
        }

        return self::reply($reply, $reason);
    }

    public function isReply(): bool
    {
        return $this->action === self::ACTION_REPLY;
    }

    public function isHandoff(): bool
    {
        return $this->action === self::ACTION_HANDOFF;
    }
}
