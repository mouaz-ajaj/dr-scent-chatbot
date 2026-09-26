<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    public const DIRECTION_INCOMING = 'incoming';

    public const DIRECTION_OUTGOING = 'outgoing';

    public const DECISION_REPLY = 'reply';

    public const DECISION_HANDOFF = 'handoff';

    protected $fillable = [
        'conversation_id',
        'whatsapp_message_id',
        'direction',
        'content',
        'ai_decision',
    ];

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
