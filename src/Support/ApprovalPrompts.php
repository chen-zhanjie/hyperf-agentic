<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Support;

/**
 * Customizable approval prompt templates for tool permission flow.
 *
 * Override static properties to customize messages (i18n, branding, etc.).
 * Template variables: {tool}, {reason}, {risk}, {arguments}.
 */
class ApprovalPrompts
{
    public static string $toolBlocked = 'Tool call blocked [{tool}]: {reason}';
    public static string $permissionDenied = 'Tool [{tool}] denied by permission policy';
    public static string $confirmationRequired = 'Tool [{tool}] requires human confirmation, but interactive confirmation is not supported';
    public static string $approvalPrompt = 'Tool [{tool}] requests execution permission (risk level: {risk})' . "\n" . 'Arguments: {arguments}';
    public static string $userDenied = 'Tool [{tool}] denied by user';
    public static string $outputBlocked = 'Tool output blocked [{tool}]: {reason}';

    public static string $choiceOnce = 'Allow this execution';
    public static string $choiceTool = 'Allow all operations for [{tool}]';
    public static string $choiceSession = 'Allow all operations for this session';

    /**
     * Bind template variables into a prompt string.
     */
    public static function bind(string $template, array $vars): string
    {
        return str_replace(
            array_map(fn (string $key) => '{' . $key . '}', array_keys($vars)),
            array_values($vars),
            $template,
        );
    }
}
