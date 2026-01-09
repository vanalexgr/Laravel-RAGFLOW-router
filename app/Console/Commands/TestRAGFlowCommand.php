<?php

namespace App\Console\Commands;

use App\Facades\RAGFlow;
use Illuminate\Console\Command;

class TestRAGFlowCommand extends Command
{
    protected $signature = 'ragflow:test';
    protected $description = 'Test RAGFlow API connection';

    public function handle(): int
    {
        $this->info('Testing RAGFlow API connection...');

        try {
            $apiKey = config('ragflow.api_key');
            $endpoint = config('ragflow.api_endpoint');

            $this->info("Endpoint: {$endpoint}");
            $this->info("API Key configured: " . (empty($apiKey) ? 'No' : 'Yes (hidden)'));

            if (empty($apiKey) || empty($endpoint)) {
                $this->error('RAGFlow API key or endpoint is not configured.');
                $this->line('Please set RAGFLOW_API_KEY and RAGFLOW_ENDPOINT in your .env file.');
                return Command::FAILURE;
            }

            $this->info('Attempting to list datasets...');
            $response = RAGFlow::datasets()->list();
            
            $this->info('Connection successful!');
            $this->info('Response: ' . json_encode($response, JSON_PRETTY_PRINT));
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Connection failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
