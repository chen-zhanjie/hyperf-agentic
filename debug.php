#!/usr/bin/env php
<?php
/**
 * SDK Local Debug CLI
 *
 * Interactive agent chat for local debugging. Reads config from .env.test.
 *
 * Usage:
 *   php debug.php                        # OpenAI protocol, default model
 *   php debug.php --protocol anthropic   # Anthropic protocol
 *   php debug.php --model gpt-4o-mini    # Override model
 *   php debug.php --stream               # Streaming mode
 *   php debug.php --help
 */
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

// ── Load .env.test ──

loadEnv(__DIR__ . '/.env.test');

// ── Parse args ──

$args = parseArgs($_SERVER['argv']);
$protocol = $args['--protocol'] ?? 'openai';
$model = $args['--model'] ?? null;
$stream = isset($args['--stream']);
$help = isset($args['--help']) || isset($args['-h']);

if ($help) {
    echo <<<HELP
Usage: php debug.php [options]

Options:
  --protocol <openai|anthropic>  LLM protocol (default: openai)
  --model <name>                 Override model name
  --stream                       Enable streaming output
  -h, --help                     Show this help

Environment:
  Reads from .env.test (same as integration tests).
  See .env.test.example for required variables.

Commands (during chat):
  /quit, /exit   Exit the session
  /reset         Clear conversation history
  /stream        Toggle streaming mode
  /model <name>  Switch model mid-session

HELP;
    exit(0);
}

// ── Build LLM Client ──

$toolRegistry = buildToolRegistry();

// Set up interactive resolver for ask tool
$cliIo = new \Symfony\Component\Console\Style\SymfonyStyle(
    new \Symfony\Component\Console\Input\ArgvInput([]),
    new \Symfony\Component\Console\Output\ConsoleOutput(),
);
$runner = new \ChenZhanjie\Agentic\AgentRunner(
    llmClient: buildClient($protocol, $model),
    promptBuilder: new \ChenZhanjie\Agentic\PromptBuilder(),
    toolRegistry: $toolRegistry,
    guardrailRunner: new \ChenZhanjie\Agentic\GuardrailRunner(),
    middleware: new \ChenZhanjie\Agentic\MiddlewarePipeline(),
    toolGuardrailRunner: new \ChenZhanjie\Agentic\ToolGuardrailRunner(),
    permissionPolicy: new \ChenZhanjie\Agentic\Policy\ConfigToolPermissionPolicy(),
);
$runner->setHumanInputResolver(new \ChenZhanjie\Agentic\Resolver\CliHumanInputResolver($cliIo));

$messages = [];
$toolNames = $toolRegistry->getAvailableNames();
echo "\n  SDK Debug CLI — protocol: {$protocol}, model: " . ($model ?? 'default') . ", stream: " . ($stream ? 'on' : 'off') . "\n";
echo "  Tools: " . (empty($toolNames) ? 'none' : implode(', ', $toolNames)) . "\n";
echo "  Type /quit to exit, /help for commands\n\n";

// ── Interactive Loop ──

while (true) {
    $input = readline("\033[1;34mYou\033[0m> ");
    if ($input === false || $input === '/quit' || $input === '/exit') {
        break;
    }
    if (trim($input) === '') {
        continue;
    }
    if ($input === '/help') {
        echo "  /quit /exit /reset /stream /model <name>\n";
        continue;
    }
    if ($input === '/reset') {
        $messages = [];
        echo "  Conversation reset.\n";
        continue;
    }
    if ($input === '/stream') {
        $stream = !$stream;
        echo "  Streaming " . ($stream ? 'enabled' : 'disabled') . ".\n";
        continue;
    }
    if (str_starts_with($input, '/model ')) {
        $model = trim(substr($input, 7));
        $client = buildClient($protocol, $model);
        $runner = new \ChenZhanjie\Agentic\AgentRunner(
            llmClient: $client,
            promptBuilder: new \ChenZhanjie\Agentic\PromptBuilder(),
            toolRegistry: $toolRegistry,
            guardrailRunner: new \ChenZhanjie\Agentic\GuardrailRunner(),
            middleware: new \ChenZhanjie\Agentic\MiddlewarePipeline(),
            toolGuardrailRunner: new \ChenZhanjie\Agentic\ToolGuardrailRunner(),
            permissionPolicy: new \ChenZhanjie\Agentic\Policy\ConfigToolPermissionPolicy(),
        );
        echo "  Model switched to: {$model}\n";
        continue;
    }

    $messages[] = ['role' => 'user', 'content' => $input];

    try {
        if ($stream) {
            echo "\033[1;32mAgent\033[0m> ";
            $result = $runner->runStream($messages, [
                'max_iterations' => 5,
                'system_prompt' => '',
                'scene' => 'cli',
                'tools' => $toolNames,
            ], [], function (string $type, array $payload) {
                if ($type === 'thinking') {
                    echo "\033[90m";
                } elseif ($type === 'reasoning_delta') {
                    echo $payload['content'] ?? '';
                } elseif ($type === 'text_delta') {
                    echo "\033[0m" . ($payload['content'] ?? '');
                } elseif ($type === 'tool_call') {
                    echo "\033[0m";
                    $name = $payload['name'];
                    if ($name === 'ask') {
                        $msg = $payload['arguments']['message'] ?? '';
                        echo "\n  \033[1;33m[ask] {$msg}\033[0m" . PHP_EOL;
                    } else {
                        echo "\n  \033[33m[call: {$name}](" . json_encode($payload['arguments'] ?? [], JSON_UNESCAPED_UNICODE) . ")\033[0m" . PHP_EOL;
                    }
                } elseif ($type === 'tool_result') {
                    $result = $payload['result'] ?? '';
                    $truncated = mb_strlen($result) > 200 ? mb_substr($result, 0, 200) . '...' : $result;
                    echo "  \033[36m[result: {$truncated}]\033[0m" . PHP_EOL;
                } elseif ($type === 'complete') {
                    echo "\033[0m";
                }
            });
            echo "\n";
            $messages[] = ['role' => 'assistant', 'content' => $result->content];
            printUsage($result);
        } else {
            $result = $runner->run($messages, [
                'max_iterations' => 5,
                'system_prompt' => '',
                'scene' => 'cli',
                'tools' => $toolNames,
            ], [], function (string $type, array $payload) {
                if ($type === 'thinking') {
                    echo "  \033[90m[thinking]\033[0m" . PHP_EOL;
                } elseif ($type === 'reasoning_delta') {
                    echo "  \033[90m  {$payload['content']}\033[0m" . PHP_EOL;
                } elseif ($type === 'tool_call') {
                    $name = $payload['name'];
                    if ($name === 'ask') {
                        $msg = $payload['arguments']['message'] ?? '';
                        echo "\n  \033[1;33m[ask] {$msg}\033[0m" . PHP_EOL;
                    } else {
                        echo "  \033[33m[call: {$name}](" . json_encode($payload['arguments'] ?? [], JSON_UNESCAPED_UNICODE) . ")\033[0m" . PHP_EOL;
                    }
                } elseif ($type === 'tool_result') {
                    $result = $payload['result'] ?? '';
                    $truncated = mb_strlen($result) > 200 ? mb_substr($result, 0, 200) . '...' : $result;
                    echo "  \033[36m[result: {$truncated}]\033[0m" . PHP_EOL;
                }
            });
            echo "\033[1;32mAgent\033[0m> {$result->content}\n";
            $messages[] = ['role' => 'assistant', 'content' => $result->content];
            printUsage($result);
        }
    } catch (\Throwable $e) {
        echo "\033[1;31mError\033[0m: {$e->getMessage()}\n";
        // Remove the failed user message so it doesn't pollute history
        array_pop($messages);
    }
}

echo "\n  Bye!\n";

// ── Helpers ──

function buildToolRegistry(): \ChenZhanjie\Agentic\ToolRegistry
{
    $registry = new \ChenZhanjie\Agentic\ToolRegistry();

    // ── get_time ──
    $registry->register(new class implements \ChenZhanjie\Agentic\Contract\ToolInterface {
        public function name(): string { return 'get_time'; }
        public function description(): string { return 'Get the current date and time in a given timezone'; }
        public function parameters(): array {
            return [
                'type' => 'object',
                'properties' => [
                    'timezone' => ['type' => 'string', 'description' => 'IANA timezone, e.g. Asia/Shanghai, America/New_York'],
                ],
                'required' => ['timezone'],
            ];
        }
        public function execute(array $arguments): string {
            $tz = $arguments['timezone'] ?? 'UTC';
            try {
                return (new \DateTime('now', new \DateTimeZone($tz)))->format('Y-m-d H:i:s');
            } catch (\Exception) {
                return "Invalid timezone: {$tz}";
            }
        }
        public function isEnabled(): bool { return true; }
        public function isParallelAllowed(): bool { return true; }
    });

    // ── calculate ──
    $registry->register(new class implements \ChenZhanjie\Agentic\Contract\ToolInterface {
        public function name(): string { return 'calculate'; }
        public function description(): string { return 'Evaluate a math expression and return the result'; }
        public function parameters(): array {
            return [
                'type' => 'object',
                'properties' => [
                    'expression' => ['type' => 'string', 'description' => 'Math expression like "42 * 17" or "sqrt(144)"'],
                ],
                'required' => ['expression'],
            ];
        }
        public function execute(array $arguments): string {
            $expr = $arguments['expression'] ?? '';
            if (!preg_match('/^[\d\s\+\-\*\/\(\)\.\,sqrtpiew]+$/', $expr)) {
                return 'Error: only safe math characters allowed';
            }
            try {
                $result = eval("return {$expr};");
                return "{$expr} = {$result}";
            } catch (\Throwable) {
                return 'Error: could not evaluate expression';
            }
        }
        public function isEnabled(): bool { return true; }
        public function isParallelAllowed(): bool { return true; }
    });

    // ── ask ──
    $registry->register(new \ChenZhanjie\Agentic\Tool\Builtin\AskTool());

    return $registry;
}

function buildClient(string $protocol, ?string $model): \ChenZhanjie\Agentic\LlmClient
{
    $prefix = strtoupper($protocol);
    $apiKey = getenv("AGENTIC_TEST_{$prefix}_API_KEY") ?: '';
    $baseUrl = getenv("AGENTIC_TEST_{$prefix}_BASE_URL") ?: '';
    $defaultModel = getenv("AGENTIC_TEST_{$prefix}_MODEL") ?: 'gpt-4o';

    if ($apiKey === '' || $baseUrl === '') {
        fwrite(STDERR, "Error: AGENTIC_TEST_{$prefix}_API_KEY and AGENTIC_TEST_{$prefix}_BASE_URL must be set in .env.test\n");
        exit(1);
    }

    return new \ChenZhanjie\Agentic\LlmClient(
        providerConfigs: [
            'debug' => [
                'protocol' => $protocol,
                'api_key' => $apiKey,
                'base_url' => $baseUrl,
                'model' => $model ?? $defaultModel,
            ],
        ],
        defaultProvider: 'debug',
    );
}

function printUsage(\ChenZhanjie\Agentic\AgentResult $result): void
{
    $iter = $result->iterations;
    $tools = $result->toolCalls;
    $prompt = $result->promptTokens;
    $completion = $result->completionTokens;
    $ms = $result->elapsedMs;
    echo "  \033[90m[iter:{$iter} tools:{$tools} tokens:{$prompt}+{$completion} {$ms}ms]\033[0m\n";
}

function loadEnv(string $path): void
{
    if (!file_exists($path)) {
        fwrite(STDERR, "Error: {$path} not found. Copy .env.test.example to .env.test and fill in values.\n");
        exit(1);
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (getenv($key) === false) {
            putenv("{$key}={$value}");
        }
    }
}

function parseArgs(array $argv): array
{
    $args = [];
    for ($i = 1; $i < count($argv); $i++) {
        if (str_starts_with($argv[$i], '--')) {
            $parts = explode('=', $argv[$i], 2);
            if (count($parts) === 2) {
                $args[$parts[0]] = $parts[1];
            } else {
                $next = $argv[$i + 1] ?? null;
                if ($next !== null && !str_starts_with($next, '-')) {
                    $args[$argv[$i]] = $next;
                    $i++;
                } else {
                    $args[$argv[$i]] = true;
                }
            }
        } elseif (str_starts_with($argv[$i], '-')) {
            $args[$argv[$i]] = true;
        }
    }
    return $args;
}
