<?php
return [
    'enabled' => env('VIZRA_ADK_ENABLED', true),
    'logging' => [
        'enabled' => env('VIZRA_ADK_LOGGING_ENABLED', true),
        'level' => 'warning',
        'components' => [
            'vector_memory' => false,
            'agents' => true,
            'tools' => true,
            'mcp' => false,
            'traces' => true,
        ],
    ],
    'default_provider' => env('VIZRA_ADK_DEFAULT_PROVIDER', 'openai'),
    'default_model' => env('VIZRA_ADK_DEFAULT_MODEL', env('AZURE_OPENAI_DEPLOYMENT', 'gpt-5-chat')),
    'default_generation_params' => [
        'temperature' => null, 'max_tokens' => null, 'top_p' => null,
    ],
    'http' => ['timeout' => 120, 'connect_timeout' => 10],
    'providers' => [
        'azure' => [
            'api_key' => env('AZURE_OPENAI_API_KEY'),
            'endpoint' => env('AZURE_OPENAI_ENDPOINT'),
            'deployment' => env('AZURE_OPENAI_DEPLOYMENT'),
            'api_version' => env('AZURE_OPENAI_VERSION', '2024-12-01-preview'),
        ],
        'openai' => [
            'url' => env(
                'OPENAI_URL',
                ($endpoint = rtrim((string) env('AZURE_OPENAI_ENDPOINT', ''), '/')) !== ''
                    ? $endpoint.'/openai/v1'
                    : 'https://api.openai.com/v1'
            ),
            'api_key' => env('OPENAI_API_KEY', env('AZURE_OPENAI_API_KEY', '')),
            'organization' => env('OPENAI_ORGANIZATION', null),
            'project' => env('OPENAI_PROJECT', null),
        ],
    ],
    'agents' => [
        'vascular_consult' => [
            'provider' => env('VASCULAR_AGENT_PROVIDER', env('VIZRA_ADK_DEFAULT_PROVIDER', 'openai')),
            'model' => env('VASCULAR_AGENT_MODEL', env('VIZRA_ADK_DEFAULT_MODEL', env('AZURE_OPENAI_DEPLOYMENT', 'gpt-5-chat'))),
        ],
    ],
    'max_delegation_depth' => 5,
    'tables' => [
        'agent_sessions' => 'agent_sessions',
        'agent_messages' => 'agent_messages',
        'agent_memories' => 'agent_memories',
        'agent_vector_memories' => 'agent_vector_memories',
        'agent_trace_spans' => 'agent_trace_spans',
    ],
    'tracing' => ['enabled' => true, 'cleanup_days' => 30],
    'namespaces' => [
        'agents' => 'App\Agents',
        'tools' => 'App\Tools',
        'evaluations' => 'App\Evaluations',
    ],
    'routes' => [
        'enabled' => true,
        'prefix' => 'api/vizra-adk',
        'middleware' => ['api'],
        'web' => ['enabled' => true, 'prefix' => 'vizra', 'middleware' => ['web']],
    ],
    'openai_model_mapping' => [],
    'default_chat_agent' => 'chat_agent',
    'mcp_servers' => [],
    'prompts' => [
        'use_database' => false,
        'storage_path' => resource_path('prompts'),
        'track_usage' => false,
        'cache_ttl' => 300,
        'default_version' => 'default',
    ],
    'vector_memory' => [
        'enabled' => false,
        'driver' => 'pgvector',
        'embedding_provider' => 'openai',
        'embedding_models' => ['openai' => 'text-embedding-3-small'],
        'dimensions' => ['text-embedding-3-small' => 1536],
        'drivers' => ['pgvector' => ['connection' => 'pgsql']],
        'chunking' => ['strategy' => 'sentence', 'chunk_size' => 1000, 'overlap' => 200, 'separators' => ["\n"], 'keep_separators' => true],
        'rag' => ['context_template' => "{context}\n\n{query}", 'max_context_length' => 4000, 'include_metadata' => true],
    ],
];
