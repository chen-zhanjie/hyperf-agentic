<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

use ChenZhanjie\Agentic\Contract\ToolGuardrailInterface;

/**
 * Runs tool-level guardrails before and after tool execution.
 */
class ToolGuardrailRunner
{
    /** @var ToolGuardrailInterface[] */
    private array $guardrails = [];

    public function register(ToolGuardrailInterface $guardrail): void
    {
        $this->guardrails[] = $guardrail;
    }

    /**
     * Run all tool guardrails on input arguments.
     * Returns the first blocked result, or null if all pass.
     * Modifies $arguments by reference when sanitization is requested.
     * Continues running subsequent guardrails after sanitization so they can
     * inspect the modified arguments.
     */
    public function checkToolInput(string $toolName, array &$arguments): ?ToolGuardrailResult
    {
        foreach ($this->guardrails as $guardrail) {
            $result = $guardrail->checkToolInput($toolName, $arguments);

            if ($result->blocked) {
                return $result;
            }

            if ($result->modifiedArguments !== null) {
                $arguments = $result->modifiedArguments;
            }
        }

        return null;
    }

    /**
     * Run all tool guardrails on output.
     * Returns the first blocked result, or null if all pass.
     * Modifies $result by reference when transformation is requested.
     * Continues running subsequent guardrails after transformation so they can
     * inspect the modified output.
     */
    public function checkToolOutput(string $toolName, array $arguments, string &$result): ?ToolGuardrailResult
    {
        foreach ($this->guardrails as $guardrail) {
            $guardResult = $guardrail->checkToolOutput($toolName, $arguments, $result);

            if ($guardResult->blocked) {
                return $guardResult;
            }

            if ($guardResult->modifiedOutput !== null) {
                $result = $guardResult->modifiedOutput;
            }
        }

        return null;
    }
}
