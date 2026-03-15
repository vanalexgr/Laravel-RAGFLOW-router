<?php

namespace App\Providers;

use App\Contracts\LlmClient;
use App\Services\AzureOpenAiLlmClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LlmClient::class, AzureOpenAiLlmClient::class);
    }


    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Laravel\Mcp\Facades\Mcp::local('vascular', \App\Mcp\VascularServer::class);
    }
}
