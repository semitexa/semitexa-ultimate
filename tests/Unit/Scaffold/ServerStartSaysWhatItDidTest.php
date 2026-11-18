<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a first start and a fresh clone print, from an OpenAI Codex run deploying
 * from a fresh clone (reported by the Guard project, 16.11):
 * tk-first-start-warns-about-an-ordinary-port-choice and
 * tk-install-prints-a-literal-backslash-n.
 */
final class ServerStartSaysWhatItDidTest extends TestCase
{
    /** The URL printed before the service checks, so it read as the end of the start. */
    #[Test]
    public function the_start_says_started_and_the_url_after_the_service_checks(): void
    {
        $start = $this->function('cmd_server_start');
        $positions = [];
        foreach (['server_start_wait_for_app || exit 1', 'wait_for_mysql', 'wait_for_service "Redis"', 'success "Semitexa environment started!"', 'info "App:'] as $needle) {
            $position = strpos($start, $needle);
            self::assertIsInt($position, $needle . ' must still be in cmd_server_start');
            $positions[$needle] = $position;
        }

        $inOrder = $positions;
        asort($inOrder);
        self::assertSame(array_keys($positions), array_keys($inOrder), 'the app, then the services, then "started!" and the URL');
    }

    /** info() prints with %s, so a "\n" at the end of its text showed as a backslash and an n. */
    #[Test]
    public function no_message_ends_in_a_literal_backslash_n(): void
    {
        self::assertSame(0, preg_match_all('/^\s*(?:info|warn|error|success)\s+"[^"\n]*\\\\n"\s*$/m', $this->script(), $matches), implode("\n", $matches[0]));
    }

    #[Test]
    public function a_command_before_the_first_install_says_that_is_expected_and_what_install_does(): void
    {
        $sh = "info() { printf 'INFO %s\\n' \"\$*\"; }\nerror() { printf 'ERROR %s\\n' \"\$*\"; }\n" . $this->function('say_vendor_missing') . "\nsay_vendor_missing";

        self::assertSame([
            'ERROR vendor/bin/semitexa not found: expected on a fresh clone, before the first install.',
            'INFO Run bin/semitexa install (it builds the image and runs composer install in a container; it touches only this Compose project), then repeat this command.',
        ], array_values(array_filter(explode("\n", (string) shell_exec('env -i PATH=/usr/bin:/bin sh -c ' . escapeshellarg($sh))))));
        self::assertSame(2, substr_count($this->script(), "        say_vendor_missing\n"), 'both commands that need vendor/ say it');
    }

    private function function(string $name): string
    {
        self::assertSame(1, preg_match('/^' . $name . '\(\) \{\n.*?^\}$/ms', $this->script(), $match), $name . ' must still exist in bin/semitexa');

        return $match[0] ?? '';
    }

    private function script(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/bin/semitexa');
    }
}
