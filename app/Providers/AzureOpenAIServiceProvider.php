<?php

namespace App\Providers;

use App\Providers\LLM\AzureOpenAIProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Prism\Prism\PrismManager;

class AzureOpenAIServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        $manager = $this->app->make(PrismManager::class);
        $manager->extend('azure', function (Application $app, array $config) {
            return new AzureOpenAIProvider(
                apiKey: $config['api_key'] ?? config('services.azure_openai.api_key', ''),
                endpoint: $config['endpoint'] ?? config('services.azure_openai.endpoint', ''),
                deployment: $config['deployment'] ?? config('services.azure_openai.deployment', 'gpt-5-chat'),
                apiVersion: $config['api_version'] ?? config('services.azure_openai.api_version', '2024-12-01-preview'),
            );
        });
    }
}
