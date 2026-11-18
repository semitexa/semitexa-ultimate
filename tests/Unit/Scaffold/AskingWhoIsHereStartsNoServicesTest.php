<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A command that reads only the project's files starts no service
 * (tk-a-cli-command-starts-the-whole-stack).
 *
 * Reported by the review of an agent deploying from a fresh clone. With the
 * stack stopped, `bin/semitexa ai:orient` (a read-only dashboard, AGENTS.md
 * says) and `ai:agent join` ran `compose run --rm app` without --no-deps.
 * That created and started MySQL, Redis, NATS and setup, which took 15 s and
 * printed about 25 lines, and the services stayed up after the command.
 */
final class AskingWhoIsHereStartsNoServicesTest extends TestCase
{
    /** @return iterable<string, array{list<string>}> */
    public static function commandsThatNeedNoService(): iterable
    {
        yield 'ai:orient' => [['ai:orient', '--json']];
        yield 'ai:agent join' => [['ai:agent', 'join', '--name=codex', '--intent=deploy']];
        yield 'ai:state' => [['ai:state', '--with-framework']];
        yield 'ai:work' => [['ai:work', 'update', '--id=tk-x', '--status=done']];
        yield 'ai:work:approve' => [['ai:work:approve', '--id=tk-x']];
        yield 'ai:make-it' => [['ai:make-it', '--json']];
        yield 'ai:backlog' => [['ai:backlog', 'list']];
        yield 'ai:epic' => [['ai:epic', 'list']];
        yield 'ai:decision' => [['ai:decision', 'list']];
        yield 'list' => [['list']];
        yield 'help' => [['help', 'orm:sync']];
        yield 'an option before the command' => [['--no-ansi', 'ai:orient']];
        yield 'options alone' => [['--version']];
    }

    /** @return iterable<string, array{list<string>}> */
    public static function commandsThatNeedTheServices(): iterable
    {
        yield 'orm:sync' => [['orm:sync']];
        yield 'ai:verify' => [['ai:verify', '--trace=tk-x']];
        yield 'mcp:serve' => [['mcp:serve']];
        yield 'a name that only starts like one' => [['ai:orientation']];
        yield 'an option value read as the command' => [['--env', 'dev', 'ai:orient']];
    }

    /** @param list<string> $args */
    #[Test]
    #[DataProvider('commandsThatNeedNoService')]
    public function a_command_that_reads_only_the_projects_files_runs_alone(array $args): void
    {
        $run = $this->composeRunFor($args);

        self::assertStringContainsString(' run --rm -T --no-deps ', $run, 'no service is started for it');
        self::assertStringEndsWith(' app php vendor/bin/semitexa ' . implode(' ', $args), $run, 'the command runs as it was given');
    }

    /** @param list<string> $args */
    #[Test]
    #[DataProvider('commandsThatNeedTheServices')]
    public function any_other_command_gets_the_services_the_app_depends_on(array $args): void
    {
        $run = $this->composeRunFor($args);

        self::assertStringNotContainsString('--no-deps', $run);
        self::assertStringContainsString(' run --rm -T ', $run);
    }

    #[Test]
    public function a_terminal_run_starts_no_service_either(): void
    {
        $body = $this->functions()['proxy_run_one_off_app'];

        self::assertSame(2, substr_count($body, 'run --rm '), 'the terminal and the pipe branch');
        self::assertSame(2, preg_match_all('/ run --rm (?:-T )?\$_no_deps \$_agent_env /', $body), 'both pass the choice on');
    }

    /**
     * @param list<string> $args
     * @return string the compose command proxy_run_one_off_app ran, on one line
     */
    private function composeRunFor(array $args): string
    {
        $functions = $this->functions();
        $sh = 'compose_bin(){ echo compose_stub; }; compose_args(){ echo "-f docker-compose.yml"; }' . "\n"
            . 'agent_env_args(){ :; }; env_export_compose_context(){ :; }' . "\n"
            . 'compose_stub(){ echo "compose $*"; }' . "\n"
            . $functions['one_off_needs_no_services'] . "\n" . $functions['proxy_run_one_off_app'] . "\n"
            . 'proxy_run_one_off_app "$@" </dev/null';
        $out = (string) shell_exec(sprintf(
            'env -i PATH=/usr/bin:/bin sh -c %s sh %s 2>&1',
            escapeshellarg($sh),
            implode(' ', array_map(escapeshellarg(...), $args)),
        ));
        $lines = array_values(array_filter(explode("\n", $out), static fn (string $line): bool => $line !== ''));
        self::assertCount(1, $lines, $out);

        return $lines[0];
    }

    /** @return array<string, string> each function's whole definition, by name */
    private function functions(): array
    {
        $script = (string) file_get_contents(dirname(__DIR__, 3) . '/bin/semitexa');
        preg_match_all('/^(one_off_needs_no_services|proxy_run_one_off_app)\(\) \{\n.*?^\}$/ms', $script, $m);
        self::assertCount(2, $m[0], 'both functions must still exist in bin/semitexa');

        return array_combine($m[1], $m[0]);
    }
}
