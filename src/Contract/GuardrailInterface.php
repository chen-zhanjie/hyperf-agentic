<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Contract;

use ChenZhanjie\Agentic\GuardrailResult;

interface GuardrailInterface
{
    public function name(): string;
    public function checkInput(array $messages): GuardrailResult;
    public function checkOutput(string $content): GuardrailResult;
}
