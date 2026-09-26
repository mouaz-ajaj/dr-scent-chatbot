<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('whatsapp:resume {phone : Exact customer phone string, never normalized}')]
#[Description('Resume AI automation for a handed-off WhatsApp conversation.')]
class WhatsappResume extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        /** @var string $phone */
        $phone = $this->argument('phone');

        $conversation = Conversation::where('phone_number', $phone)->first();

        if ($conversation === null) {
            $this->error("No conversation found for phone [{$phone}].");

            return self::FAILURE;
        }

        $conversation->resumeAutomation();

        $this->info("Automation resumed for [{$phone}].");

        return self::SUCCESS;
    }
}
