<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Contract;

interface HumanInputResolverInterface
{
    /**
     * @param string $message Message shown to user
     * @param array  $fields  Field definitions
     * @return array{confirmed: bool, values: array<string, mixed>, _other?: array}
     */
    public function ask(string $message, array $fields = []): array;

    /** Whether this resolver blocks synchronously (CLI=true, HTTP=false) */
    public function isBlocking(): bool;
}
