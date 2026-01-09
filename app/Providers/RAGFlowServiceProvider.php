<?php

namespace App\Providers;

use App\Services\RAGFlow\RAGFlowClient;
use Illuminate\Support\ServiceProvider;

class RAGFlowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            config_path('ragflow.php'),
            'ragflow'
        );

        $this->app->singleton('ragflow', function ($app) {
            $apiKey = config('ragflow.api_key');
            $endpoint = config('ragflow.api_endpoint');
            $timeout = config('ragflow.request_timeout', 30);

            if (empty($apiKey)) {
                throw new \InvalidArgumentException(
                    'RAGFlow API key is missing. Please set RAGFLOW_API_KEY in your .env file.'
                );
            }

            if (empty($endpoint)) {
                throw new \InvalidArgumentException(
                    'RAGFlow API endpoint is missing. Please set RAGFLOW_ENDPOINT in your .env file.'
                );
            }

            return new RAGFlowClient($apiKey, $endpoint, $timeout);
        });

        $this->app->alias('ragflow', RAGFlowClient::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/ragflow.php' => config_path('ragflow.php'),
            ], 'ragflow-config');
        }
    }

    public function provides(): array
    {
        return ['ragflow', RAGFlowClient::class];
    }
}
