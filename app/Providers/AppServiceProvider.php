<?php

namespace App\Providers;

use App\Contracts\LlmClient;
use App\Services\AzureOpenAiLlmClient;
use App\Services\BridgeRerankService;
use App\Services\ChunkSelectionService;
use App\Services\ClinicalInterpreterService;
use App\Services\GapDetectionService;
use App\Services\GraphRagService;
use App\Services\GuidelineRouterService;
use App\Services\PHIScrubberService;
use App\Services\TaxonomyExpanderService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LlmClient::class, AzureOpenAiLlmClient::class);

        // PHIScrubberService resets state per call; this binding is not safe under Octane concurrency.
        $this->app->singleton(PHIScrubberService::class);
        $this->app->singleton(GraphRagService::class);
        $this->app->singleton(ClinicalInterpreterService::class);
        $this->app->singleton(TaxonomyExpanderService::class);
        $this->app->singleton(GapDetectionService::class);
        $this->app->singleton(ChunkSelectionService::class);
        $this->app->singleton(BridgeRerankService::class);
        $this->app->singleton(GuidelineRouterService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Laravel\Mcp\Facades\Mcp::local('vascular', \App\Mcp\VascularServer::class);
    }
}
