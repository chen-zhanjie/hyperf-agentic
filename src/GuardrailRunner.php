<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

use ChenZhanjie\Agentic\Contract\GuardrailInterface;

class GuardrailRunner
{
    /** @var GuardrailInterface[] */
    private array $guardrails = [];

    public function register(GuardrailInterface $guardrail): void
    {
        $this->guardrails[] = $guardrail;
    }

    /**
     * Load guardrails from class names, replacing any previously registered.
     *
     * @param array<class-string<GuardrailInterface>> $guardrailClasses
     */
    public function loadFromConfig(array $guardrailClasses): void
    {
        $this->guardrails = [];
        foreach ($guardrailClasses as $className) {
            if (!class_exists($className)) {
                continue;
            }
            $instance = new $className();
            if (!($instance instanceof GuardrailInterface)) {
                throw new \InvalidArgumentException(
                    "Class [{$className}] does not implement GuardrailInterface"
                );
            }
            $this->guardrails[] = $instance;
        }
    }

    /**
     * Run all input guardrails. Returns the first tripped result, or null if all pass.
     *
     * @param array $messages Conversation messages
     */
    public function checkInput(array $messages): ?GuardrailResult
    {
        foreach ($this->guardrails as $guardrail) {
            $result = $guardrail->checkInput($messages);
            if ($result->tripwire) {
                return $result;
            }
        }
        return null;
    }

    /**
     * Run all output guardrails. Returns the first tripped result, or null if all pass.
     */
    public function checkOutput(string $content): ?GuardrailResult
    {
        foreach ($this->guardrails as $guardrail) {
            $result = $guardrail->checkOutput($content);
            if ($result->tripwire) {
                return $result;
            }
        }
        return null;
    }
}
