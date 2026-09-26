<?php

namespace App\Services\Ai;

use App\Data\AiReplyDecision;

interface AiService
{
    /**
     * Decide whether to reply to the customer or hand off to a human.
     *
     * @param  array<string, mixed>  $businessProfile  Already-loaded Business Profile data.
     * @param  list<array{role: string, content: string}>  $history  AI-safe history, customer/assistant roles only.
     */
    public function decide(array $businessProfile, array $history, string $message): AiReplyDecision;
}
