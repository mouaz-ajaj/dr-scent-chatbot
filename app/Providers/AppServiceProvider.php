<?php

namespace App\Providers;

use App\Services\Ai\AiService;
use App\Services\Ai\GeminiService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AiService::class, fn () => GeminiService::fromConfig());
        $this->app->bind(WhatsAppService::class, fn () => WhatsAppService::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
