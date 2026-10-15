<?php

namespace CipiGui\Tests\Unit;

use CipiGui\Support\BuildCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class BuildCommandTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function validCommands(): array
    {
        return [
            'npm ci and build' => ['npm ci && npm run build'],
            'yarn' => ['yarn install --frozen-lockfile; yarn build'],
            'pnpm' => ['pnpm i && pnpm run build:prod'],
            'bun' => ['bun install && bun run build'],
            'npx' => ['npx vite build --mode=production'],
            'node alone' => ['node'],
            'quotes' => ['npm run build -- --base="/app/"'],
        ];
    }

    /** @return array<string, array{string, string}> */
    public static function invalidCommands(): array
    {
        return [
            'too long' => ['npm run '.str_repeat('a', 200), 'at most 200'],
            'pipe' => ['npm run build | tee build.log', 'Pipes'],
            'redirect' => ['npm run build > build.log', 'Pipes'],
            'backtick' => ['npm run `whoami`', 'Pipes'],
            'subshell' => ['npm run $(whoami)', 'Pipes'],
            'dollar' => ['npm run $HOME', 'letters, digits'],
            'newline' => ["npm ci\nnpm run build", 'letters, digits'],
            'other binary' => ['make assets', 'Start the build command'],
            'prefix only' => ['npmx build', 'Start the build command'],
        ];
    }

    #[Test]
    #[DataProvider('validCommands')]
    public function it_accepts_what_the_cli_accepts(string $command): void
    {
        $this->assertNull(BuildCommand::error($command));
    }

    #[Test]
    #[DataProvider('invalidCommands')]
    public function it_refuses_what_the_cli_refuses(string $command, string $message): void
    {
        $this->assertStringContainsString($message, (string) BuildCommand::error($command));
    }

    #[Test]
    public function empty_means_no_build(): void
    {
        $this->assertNull(BuildCommand::error(''));
        $this->assertNull(BuildCommand::error(null));
    }
}
