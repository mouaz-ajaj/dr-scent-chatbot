<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_WAITING_HUMAN = 'waiting_human';

    protected $fillable = [
        'phone_number',
        'status',
        'needs_human',
        'last_message_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
        'needs_human' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'needs_human' => 'boolean',
            'last_message_at' => 'datetime',
        ];
    }

    /**
     * Mark the conversation as handed off to a human.
     *
     * Invariant: waiting_human always implies needs_human = true.
     */
    public function markWaitingHuman(): void
    {
        $this->update([
            'status' => self::STATUS_WAITING_HUMAN,
            'needs_human' => true,
        ]);
    }

    /**
     * Resume automated handling of the conversation.
     *
     * Invariant: active always implies needs_human = false.
     */
    public function resumeAutomation(): void
    {
        $this->update([
            'status' => self::STATUS_ACTIVE,
            'needs_human' => false,
        ]);
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
