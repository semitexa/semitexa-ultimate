<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Semitexa\Ultimate\Application\Console\Command\InitCommand;

/**
 * Regression guard preventing the empty `src/infrastructure/{database,migrations}`
 * scaffold from coming back. The ORM does not consume that directory; it was a
 * speculative folder that the installer kept creating until ep-update-dev-orm-ownership
 * removed it.
 *
 * If the InitCommand starts referencing `src/infrastructure/...` again, this test
 * fires. ORM owns schema migrations through `[Resource]`-attributed entities — there
 * is no canonical migrations directory in a Semitexa project.
 *
 * Historical context: epic ep-update-dev-orm-ownership.
 */
final class NoInfrastructureScaffoldTest extends TestCase
{
    public function testScaffoldFileMapHasNoInfrastructureKeys(): void
    {
        $reflection = new ReflectionClass(InitCommand::class);
        $constants = $reflection->getReflectionConstants();

        $found = null;
        foreach ($constants as $constant) {
            if ($constant->getName() === 'SCAFFOLD_FILE_MAP') {
                $found = $constant;
                break;
            }
        }

        self::assertNotNull($found, 'InitCommand::SCAFFOLD_FILE_MAP not found.');

        /** @var array<string, string> $map */
        $map = $found->getValue();

        $offending = array_values(array_filter(
            array_keys($map),
            static fn (string $key): bool => str_contains($key, 'infrastructure/'),
        ));

        self::assertSame(
            [],
            $offending,
            'InitCommand::SCAFFOLD_FILE_MAP must not scaffold src/infrastructure/. '
            . 'ORM does not consume that directory. Offending keys: '
            . implode(', ', $offending),
        );
    }

    public function testInitCommandSourceContainsNoInfrastructurePath(): void
    {
        $sourcePath = __DIR__ . '/../../../src/Application/Console/Command/InitCommand.php';
        $source = file_get_contents($sourcePath);

        self::assertNotFalse($source, sprintf('Failed to read %s.', $sourcePath));

        self::assertStringNotContainsString(
            'src/infrastructure/',
            $source,
            'InitCommand source must not reference src/infrastructure/. '
            . 'That scaffold directory is not consumed by ORM and must not be created.',
        );
    }
}
