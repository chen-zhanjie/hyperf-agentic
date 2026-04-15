<?php
declare(strict_types=1);

/**
 * Agent 定义配置模板
 * 复制到 config/autoload/agentic/agents.php 并定义你的 Agents。
 */
return [
    'default' => [
        'persona' => 'default.md',
        'tools' => ['*'],
        'skills' => [],
        'guardrails' => [],
        'max_iterations' => null, // null = 使用全局默认
    ],
];
