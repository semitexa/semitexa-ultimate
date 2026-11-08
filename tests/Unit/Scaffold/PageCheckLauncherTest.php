<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\TestCase;

/**
 * bin/semitexa's side of the page check semitexa/dev ships: it asks the app which
 * pages to open, runs resources/page-check in the e2e-runner with the two variables
 * the spec reads, and leaves a reason whenever no page was filed. The spec and
 * PageCheckResult agree on the rest (semitexa-dev PageCheckVerificationTest).
 * Read from the copy this package ships, so no installer checkout is needed.
 */
final class PageCheckLauncherTest extends TestCase
{
    private function bin(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/bin/semitexa');
    }

    public function test_it_hands_the_spec_its_pages_and_where_to_file_them(): void
    {
        $bin = $this->bin();

        foreach (['PAGE_CHECK_PAGES', 'PAGE_CHECK_OUT'] as $env) {
            self::assertStringContainsString("-e {$env}=", $bin);
        }
        // Where PageCheckResult looks: var/run/verify-e2e/<run>/pages.
        self::assertStringContainsString('PAGE_CHECK_OUT="/var/www/html/var/run/verify-e2e/$_vrun/pages"', $bin);
        self::assertStringContainsString('vendor/semitexa/dev/resources/page-check/playwright.config.ts', $bin);
        self::assertStringContainsString('ai:verify "$@" --list-pages', $bin);
    }

    /**
     * REGRESSION (review installer#72): the spec makes pages/ before it opens anything, so an
     * existing directory proved nothing, and an empty list left no reason behind.
     */
    public function test_no_page_filed_always_leaves_a_reason(): void
    {
        $bin = $this->bin();

        self::assertStringContainsString('ls "$_vdir/pages/"*.json', $bin, 'a page filed, not a directory made');
        self::assertStringContainsString('ai:verify --list-pages named no page', $bin);
        self::assertStringContainsString('this semitexa/dev has no page check', $bin, 'an older semitexa/dev says so');
    }

    /** REGRESSION (reviews update#77, installer#73): a proxy note or a blank line on stdout read as one more path. */
    public function test_a_list_call_keeps_stdout_to_paths(): void
    {
        $bin = $this->bin();

        self::assertStringContainsString('info() { printf "%s[INFO]%s  %s\n" "$C_CYAN" "$C_RESET" "$*" >&2; }', $bin);
        self::assertStringContainsString("grep -v '^[[:space:]]*$'", $bin);
    }
}
