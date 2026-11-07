<?php

declare(strict_types=1);

namespace Semitexa\Ultimate\Tests\Unit\Scaffold;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * bin/semitexa reads .env as the framework does (Environment::parseEnvValue):
 * an unquoted value ends at a # after whitespace, a quoted one at its quote.
 *
 * REGRESSION (review, 2026-11-07). `CACHE_DRIVER=redis # shared cache` was
 * exported to Compose with its comment, and the cache driver it named did not
 * exist; the app, reading the same line in PHP, got `redis`.
 */
final class EnvValueShellTest extends TestCase
{
    private const PARSER = <<<'SH'
        sed -e 's/^"\(\([^"]*[^"\\]\)\{0,1\}\)"[[:space:]]\{1,\}#.*$/\1/' -e t -e 's/^"\(.*\)"$/\1/' -e t -e '/^"/b' -e "s/^'\([^']*\)'[[:space:]]\{1,\}#.*\$/\1/" -e t -e "s/^'\(.*\)'\$/\1/" -e t -e "/^'/b" -e 's/[[:space:]]#.*$//' -e 's/[[:space:]]*$//'
        SH;

    /** @return iterable<string, array{string, string}> */
    public static function lines(): iterable
    {
        yield 'a trailing comment' => ['K=redis # shared cache', 'redis'];
        yield 'a quoted value and a comment' => ['K="a b" # note', 'a b'];
        yield 'a hash inside double quotes' => ['K="x # y"', 'x # y'];
        yield 'a hash inside single quotes' => ["K='lit # x'", 'lit # x'];
        yield 'a hash with no space before it' => ['K=#fff', '#fff'];
        yield 'a fragment in a URL' => ['K=https://x/#frag', 'https://x/#frag'];
        yield 'a quote in the comment' => ['K="a" # "b"', 'a'];
        yield 'trailing spaces' => ['K=plain  ', 'plain'];
        // REGRESSION (review): an escaped quote is not the closing one. The value stays what
        // the parser before this one gave, which a MySQL volume may have been set up with.
        yield 'an escaped quote before a hash' => ['K="abc\\" #suffix"', 'abc\\" #suffix'];
        yield 'an empty quoted value is no value' => ['K="" # none', 'fallback'];
        // REGRESSION (review): a quoted line neither quote rule takes is left whole, as before
        // comments were read at all; cutting it at its first ` #` kept the quote and lost the rest.
        yield 'an escaped backslash before the closing quote' => ['K="a\\\\" # c', '"a\\\\" # c'];
        yield 'an escaped quote inside, then a comment' => ['K="x\\" # y" # z', '"x\\" # y" # z'];
        yield 'text after a single-quoted value' => ["K='a'b # c", "'a'b # c"];
    }

    #[Test]
    #[DataProvider('lines')]
    public function env_val_reads_a_line_as_the_framework_does(string $line, string $value): void
    {
        $script = $this->script();
        self::assertSame(1, preg_match('/^env_val\(\) \{\n.*?^\}$/ms', $script, $m), 'env_val must still exist in bin/semitexa');

        $sh = 'env_merged_lines(){ printf "%s\n" "$LINE"; }' . "\n" . $m[0] . "\nprintf '[%s]' \"\$(env_val K fallback)\"";
        $out = (string) shell_exec(sprintf('env -i PATH=/usr/bin:/bin LINE=%s sh -c %s 2>&1', escapeshellarg($line), escapeshellarg($sh)));

        self::assertSame("[{$value}]", $out);
    }

    #[Test]
    public function every_reader_of_env_lines_uses_the_same_parser(): void
    {
        // env_val, the Compose export and the third reader: one that kept the old parser would export the comment again.
        self::assertSame(3, substr_count($this->script(), trim(self::PARSER)));
        self::assertStringNotContainsString("sed -e 's/^\"\\(.*\\)\"\$/\\1/' -e t -e \"s/^'", $this->script());
    }

    private function script(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/bin/semitexa');
    }
}
