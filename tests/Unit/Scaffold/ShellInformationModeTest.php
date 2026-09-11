<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\TestCase;

final class ShellInformationModeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-information-' . bin2hex(random_bytes(8));
        foreach (['bin', 'stubs', 'vendor/bin'] as $directory) {
            mkdir($this->root . '/' . $directory, 0755, true);
        }
        $ultimate = dirname(__DIR__, 3);
        $source = dirname($ultimate) . '/semitexa-installer/scaffold/bin/semitexa';
        copy(is_file($source) ? $source : $ultimate . '/bin/semitexa', $this->root . '/bin/semitexa');
        file_put_contents($this->root . '/.env', "APP_ENV=dev\nDB_DATABASE=live\nDB_TEST_DATABASE=isolated_test\n");
        file_put_contents($this->root . '/composer.json', '{}');
        foreach (['docker-compose.yml', 'docker-compose.test.yml', 'vendor/bin/semitexa', 'vendor/bin/phpunit'] as $file) {
            file_put_contents($this->root . '/' . $file, 'fixture');
        }
        file_put_contents($this->root . '/stubs/docker', <<<'SH'
#!/bin/sh
printf 'docker %s\n' "$*" >> "$SEMITEXA_INFORMATION_LOG"
case " $* " in
    *' version '*) exit 0 ;;
    *' ps '*) [ "${SEMITEXA_INFORMATION_RUNNING:-0}" != 1 ] || printf 'running\n'; exit 0 ;;
    *' exec '*) printf 'runtime information\n'; exit "${SEMITEXA_INFORMATION_EXIT:-0}" ;;
    *) exit 91 ;;
esac
SH);
        file_put_contents($this->root . '/stubs/php', <<<'SH'
#!/bin/sh
printf 'php %s\n' "$*" >> "$SEMITEXA_INFORMATION_LOG"
printf 'local information\n'
exit "${SEMITEXA_INFORMATION_EXIT:-0}"
SH);
        chmod($this->root . '/stubs/docker', 0755);
        chmod($this->root . '/stubs/php', 0755);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testNativeHelpNeverCallsDockerOrPhp(): void
    {
        foreach (['server:start', 'server:stop', 'server:restart', 'install', 'init', 'demo:install', 'local-domain:add', 'local-app:remove', 'local-router:restart', 'self-test'] as $command) {
            [$exit, $output, $calls] = $this->runShell([$command, '--help']);
            self::assertSame(0, $exit, $command . ': ' . $output);
            self::assertStringContainsString('Usage:', $output);
            self::assertSame('', $calls, $command);
        }
        [$exit, , $calls] = $this->runShell(['--help']);
        self::assertSame(0, $exit);
        self::assertSame('', $calls);
    }

    public function testPhpunitInformationOnlyUsesAnExistingRuntime(): void
    {
        foreach (['--help', '--version', '--list-tests', '--list-suites', '--list-groups', '--list-tests-xml=inventory.xml'] as $flag) {
            [$exit, $output, $calls] = $this->runShell(['test:run', $flag], ['SEMITEXA_INFORMATION_RUNNING' => '1']);
            self::assertSame(0, $exit, $flag . ': ' . $output);
            self::assertStringContainsString('exec -T -e DB_DATABASE=isolated_test -e APP_ENV=dev app php vendor/bin/phpunit ' . $flag, $calls);
            self::assertStringNotContainsString('orm:sync', $calls);
            self::assertStringNotContainsString('Phase 1/2', $output);
            self::assertDoesNotMatchRegularExpression('/\b(run|up|down|build|restart|e2e-runner)\b/', $calls);
        }
    }

    public function testHelpWorksWithoutComposeFilesAndPreservesRuntimeFailure(): void
    {
        unlink($this->root . '/docker-compose.yml');
        unlink($this->root . '/docker-compose.test.yml');
        [$exit, , $calls] = $this->runShell(['test:run', '--', '--help'], ['SEMITEXA_INFORMATION_EXIT' => '7']);
        self::assertSame(7, $exit);
        self::assertStringContainsString('vendor/bin/phpunit --help', $calls);
        self::assertStringNotContainsString('docker ', $calls);
    }

    public function testNativeVersionAndPhpHelpDoNotStartAnEnvironment(): void
    {
        foreach ([['--version'], ['server:restart', '--version'], ['ai:verify', '--help']] as $args) {
            [$exit, , $calls] = $this->runShell($args);
            self::assertSame(0, $exit);
            self::assertStringContainsString('vendor/bin/semitexa', $calls);
            self::assertDoesNotMatchRegularExpression('/\b(run|up|down|build|restart)\b/', $calls);
        }
    }

    /** @param list<string> $args @param array<string, string> $overrides @return array{int, string, string} */
    private function runShell(array $args, array $overrides = []): array
    {
        $log = $this->root . '/calls';
        file_put_contents($log, '');
        $env = array_merge(getenv(), [
            'PATH' => $this->root . '/stubs:' . getenv('PATH'),
            'SEMITEXA_INFORMATION_LOG' => $log,
            'SEMITEXA_INFORMATION_RUNNING' => '0',
            'SEMITEXA_INFORMATION_EXIT' => '0',
            'SEMITEXA_BIN_SOURCE_ONLY' => '0',
        ], $overrides);
        $process = proc_open(['sh', $this->root . '/bin/semitexa', ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $this->root, $env);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        return [proc_close($process), $output, file_get_contents($log)];
    }
}
