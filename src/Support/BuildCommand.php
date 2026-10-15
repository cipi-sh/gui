<?php

namespace CipiGui\Support;

/**
 * The asset build a Laravel app runs inside every deploy (`node_build`, e.g.
 * "npm ci && npm run build"). Same rule as the Cipi CLI (_validate_node_build_cmd)
 * and cipi/api (CipiValidationService::nodeBuildError), so the panel refuses
 * what the server would refuse before a request is sent.
 */
class BuildCommand
{
    public const MAX_LENGTH = 200;

    public const PLACEHOLDER = 'npm ci && npm run build';

    /** Why the command is refused, or null when it is valid (or empty). */
    public static function error(?string $command): ?string
    {
        if ($command === null || $command === '') {
            return null;
        }
        if (strlen($command) > self::MAX_LENGTH) {
            return 'The build command can be at most '.self::MAX_LENGTH.' characters.';
        }
        if (preg_match('/[|<>`]|\$\(/', $command)) {
            return 'Pipes, redirects, backticks and $( ) are not allowed in the build command.';
        }
        if (! preg_match('/^[a-zA-Z0-9_.\/= :&;\'"-]+$/', $command)) {
            return 'Use letters, digits, spaces and . / = : & ; \' " - _ only.';
        }
        if (! preg_match('/^(npm|npx|yarn|pnpm|bun|node)( |$)/', $command)) {
            return 'Start the build command with npm, npx, yarn, pnpm, bun or node.';
        }

        return null;
    }

    /** Validation rule for Livewire / Laravel validators. */
    public static function rule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value)) {
                return;
            }
            if (($message = self::error($value)) !== null) {
                $fail($message);
            }
        };
    }
}
