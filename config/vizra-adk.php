<?php

return [
    'enabled' => true,

    'logging' => [
        'enabled' => true,
        'level' => 'warning',
        'components' => [
            'vector_memory' => false,
            'agents' => true,
            'tools' => true,
            'mcp' => false,
            'traces' => true,
        ],
    ],

    // ✅ Azure works best if we keep the "openai" driver (Vizra expects it)
    'default_provider' => 'openai',
    'default_model' => 'gpt-5-chat',

    'default_generation_params' => [
        'temperature' => null,
        'max_tokens' => null,
        'top_p' => null,
    ],

    'http' => [
        'timeout' => 120,
        'connect_timeout' => 10,
    ],

    'providers' => [
        'openai' => [
            // 1️⃣ API key
            'api_key' => env('AZURE_OPENAI_KEY', '7Prp8cQXAmcr7d5B43RreIEIZJtjhjfvOnsbUGvGflTqT8F4lmv5JQQJ99BHACfhMk5XJ3w3AAAAACOGzxcj'),

            // 2️⃣ Azure endpoint BASE (no /chat/completions here)
            'base_url' => 'https://alexiouv-5401-resource.cognitiveservices.azure.com/models',

            // 3️⃣ This keeps Vizra happy — avoids null crash
            'url' => 'https://alexiouv-5401-resource.cognitiveservices.azure.com/models',

            'organization' => null,

            // 4️⃣ Azure-specific headers and query string
            'client_options' => [
                'headers' => [
                    'api-key' => env('AZURE_OPENAI_KEY', '7Prp8cQXAmcr7d5B43RreIEIZJtjhjfvOnsbUGvGflTqT8F4lmv5JQQJ99BHACfhMk5XJ3w3AAAAACOGzxcj'),
                    'Content-Type' => 'application/json',
                ],
                'query' => [
                    'api-version' => '2024-12-01-preview',
                ],
            ],

            // 5️⃣ Override model mapping (ensures /chat/completions works)
            'endpoints' => [
                'chat' => '/chat/completions',
                'completions' => '/completions',
                'embeddings' => '/embeddings',
            ],
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
        'agents' => 'App\\Agents',
        'tools' => 'App\\Tools',
        'evaluations' => 'App\\Evaluations',
    ],

    'routes' => [
        'enabled' => true,
        'prefix' => 'api/vizra-adk',
        'middleware' => ['api'],
        'web' => [
            'enabled' => true,
            'prefix' => 'vizra',
            'middleware' => ['web'],
        ],
    ],

    'openai_model_mapping' => [
        // optional explicit alias mapping
        'gpt-5-chat' => 'gpt-5-chat',
    ],

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
        'chunking' => [
            'strategy' => 'sentence',
            'chunk_size' => 1000,
            'overlap' => 200,
            'separators' => ["\n"],
            'keep_separators' => true,
        ],
        'rag' => [
            'context_template' => "{context}\n\n{query}",
            'max_context_length' => 4000,
            'include_metadata' => true,
        ],
    ],
];
