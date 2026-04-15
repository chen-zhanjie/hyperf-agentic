<?php
declare(strict_types=1);

/**
 * CLI configuration for agent:chat command.
 */
return [
    'commands' => [
        \ChenZhanjie\Agentic\Command\AgentChatCommand::class,
    ],
    'default_agent' => 'default',
];
