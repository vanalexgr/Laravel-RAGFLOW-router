<?php

namespace App\Facades;

use App\Services\RAGFlow\RAGFlowClient;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \App\Services\RAGFlow\ChatResource chat()
 * @method static \App\Services\RAGFlow\DatasetResource datasets()
 * @method static \App\Services\RAGFlow\DocumentResource documents()
 * @method static array get(string $endpoint, array $data = [])
 * @method static array post(string $endpoint, array $data = [])
 * @method static array put(string $endpoint, array $data = [])
 * @method static array delete(string $endpoint, array $data = [])
 *
 * @see \App\Services\RAGFlow\RAGFlowClient
 */
class RAGFlow extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'ragflow';
    }
}
