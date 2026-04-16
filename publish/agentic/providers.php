<?php
declare(strict_types=1);

/**
 * LLM 服务商配置模板
 * 复制到 config/autoload/agentic/providers.php 并填入实际 API Key。
 *
 * protocol 支持: 'openai' (默认) | 'anthropic'
 */
return [
    'default' => 'openai',

    'providers' => [
        'openai' => [
            'protocol' => 'openai',
            'api_key' => env('OPENAI_API_KEY', ''),
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o',
            'max_tokens' => 4096,
            'temperature' => 0.7,
        ],
    ],
];
