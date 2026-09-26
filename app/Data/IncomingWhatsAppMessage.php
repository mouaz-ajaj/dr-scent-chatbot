<?php

namespace App\Data;

final readonly class IncomingWhatsAppMessage
{
    public function __construct(
        public string $whatsappMessageId,
        public string $senderPhone,
        public string $body,
    ) {}
}
