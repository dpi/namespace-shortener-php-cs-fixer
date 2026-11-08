<?php

/*
 * This file is part of the dpi/namespace-shortener-php-cs-fixer project.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Dpi\NamespaceShortener\PhpCsFixer\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;

use function Safe\file_get_contents;
use function Safe\file_put_contents;
use function Safe\mkdir;

/**
 * Nothing unresolvable may pass silently: every failure must surface as a bad exit code.
 */
final class ExitCodeTest extends CliTestCase
{
    private const string FIXABLE = "<?php\n\nnamespace App;\n\nuse A\\B\\C\\D\\E;\n\nnew E();\n";

    private const string FIXED = "<?php\n\nnamespace App;\n\nuse A\\B\\C\\D;\n\nnew D\\E();\n";

    private const string ROOTS = "['roots' => ['A\\\\B\\\\C\\\\*']]";

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function modes(): iterable
    {
        yield 'fix, sequential' => ['fix', false];
        yield 'fix, parallel' => ['fix', true];
        yield 'check, sequential' => ['check', false];
        yield 'check, parallel' => ['check', true];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeSources(): iterable
    {
        yield 'reserved name' => ["<?php\n\nnamespace App;\n\nuse A\\B\\C\\String\\E;\n\nnew E();\n"];
        yield 'keyword as bare name' => ["<?php\n\nnamespace App;\n\nnew \\A\\B\\C\\List();\n"];
        yield 'import collision' => ["<?php\n\nnamespace App;\n\nuse X\\D;\nuse A\\B\\C\\D\\E;\n\nnew D();\nnew E();\n"];
        yield 'declared class' => ["<?php\n\nnamespace App;\n\nuse A\\B\\C\\D\\E;\n\nclass D extends E\n{\n}\n"];
        yield 'implicit namespace name' => ["<?php\n\nnamespace App;\n\nuse A\\B\\C\\D\\E;\n\nnew D();\nnew E();\n"];
    }

    #[DataProvider('modes')]
    public function testUnresolvableConflictExitsWithExceptionStatusInEveryMode(string $command, bool $parallel): void
    {
        foreach (self::unsafeSources() as $label => [$source]) {
            $this->writeConfig(self::ROOTS, parallel: $parallel);
            $path = $this->writeSource($source);

            $result = $this->runFixer([$command]);

            self::assertSame(64, $result['exitCode'] & 64, $label . ': ' . $result['output']);
            self::assertSame($source, file_get_contents($path), $label . ': a failed file must not change');
        }
    }

    /**
     * PHP-CS-Fixer only prints the reason for a failure from -vv upwards.
     */
    #[DataProvider('modes')]
    public function testConflictMessageReachesTheUserWhenVeryVerbose(string $command, bool $parallel): void
    {
        $this->writeConfig(self::ROOTS, parallel: $parallel);
        $this->writeSource("<?php\n\nnamespace App;\n\nuse A\\B\\C\\D\\E;\n\nnew D();\nnew E();\n");

        self::assertStringContainsString('would change it to mean something else', $this->runFixer([$command], '-vv')['output']);
    }

    public function testFailedFileIsNamedAtDefaultVerbosity(): void
    {
        $this->writeConfig(self::ROOTS);
        $this->writeSource("<?php\n\nnamespace App;\n\nuse A\\B\\C\\D\\E;\n\nnew D();\nnew E();\n");

        self::assertStringContainsString('Source.php', $this->runFixer(['fix'])['output']);
    }

    public function testFailureIsNotHiddenByTheCacheOnASecondRun(): void
    {
        $this->writeConfig(self::ROOTS);
        $this->writeSource("<?php\n\nnamespace App;\n\nuse A\\B\\C\\D\\E;\n\nnew D();\nnew E();\n");

        self::assertNotSame(0, $this->runFixer(['fix'])['exitCode']);
        self::assertNotSame(0, $this->runFixer(['fix'])['exitCode']);
    }

    public function testOneBadFileFailsTheRunButGoodFilesAreStillFixed(): void
    {
        $this->writeConfig(self::ROOTS);
        $good = $this->writeSource(self::FIXABLE, 'Good.php');
        $this->writeSource("<?php\n\nnamespace App;\n\nuse A\\B\\C\\D\\E;\n\nnew D();\nnew E();\n", 'Bad.php');

        self::assertNotSame(0, $this->runFixer(['fix'])['exitCode']);
        self::assertSame(self::FIXED, file_get_contents($good));
    }

    public function testFixAppliesTheChangeAndExitsZero(): void
    {
        $this->writeConfig(self::ROOTS);
        $path = $this->writeSource(self::FIXABLE);

        $result = $this->runFixer(['fix']);

        self::assertSame(0, $result['exitCode'], $result['output']);
        self::assertSame(self::FIXED, file_get_contents($path));
    }

    public function testCheckReportsAFixableFileWithChangedFilesStatusAndLeavesItAlone(): void
    {
        $this->writeConfig(self::ROOTS);
        $path = $this->writeSource(self::FIXABLE);

        $result = $this->runFixer(['check']);

        self::assertSame(8, $result['exitCode'], $result['output']);
        self::assertSame(self::FIXABLE, file_get_contents($path));
    }

    public function testCheckPassesOnceFixed(): void
    {
        $this->writeConfig(self::ROOTS);
        $this->writeSource(self::FIXED);

        $result = $this->runFixer(['check']);

        self::assertSame(0, $result['exitCode'], $result['output']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'malformed root' => ["['roots' => ['A\\\\*\\\\B']]", 'malformed'];
        yield 'aliased wildcard' => ["['roots' => ['A\\\\* as X']]", 'cannot have an alias'];
        yield 'reserved alias' => ["['roots' => ['A\\\\B as String']]", 'cannot use "String"'];
        yield 'no roots' => ["['roots' => []]", 'At least one'];
        yield 'duplicate roots' => ["['roots' => ['A\\\\B', 'A\\\\B as C']]", 'more than once'];
        yield 'non-string root' => ["['roots' => [1]]", 'must be a string'];
        yield 'unknown option' => ["['roots' => ['A\\\\B'], 'nope' => true]", 'Unknown options'];
        yield 'missing roots option' => ["['other' => 1]", 'Unknown options'];
        yield 'missing root file' => ["['root_files' => ['nope.json']]", 'cannot be read'];
        yield 'root_files not a list' => ["['root_files' => 'nope.json']", '"root_files" option must'];
    }

    #[DataProvider('invalidConfigurations')]
    public function testInvalidConfigurationExitsWithInvalidFixerConfigStatus(string $rule, string $expectedMessage): void
    {
        $this->writeConfig($rule);
        $this->writeSource(self::FIXABLE);

        $result = $this->runFixer(['fix']);

        self::assertSame(32, $result['exitCode'], $result['output']);
        self::assertStringContainsString($expectedMessage, $result['output']);
    }

    /**
     * PHP-CS-Fixer drops such a file before fixers run. The docs warn about this, so a change should be noticed.
     */
    public function testSyntaxErrorInSourceFailsCheckButNotFix(): void
    {
        $this->writeConfig(self::ROOTS);
        $this->writeSource("<?php\nclass {\n");

        self::assertSame(4, $this->runFixer(['check'])['exitCode']);
        self::assertSame(0, $this->runFixer(['fix'])['exitCode']);
    }

    public function testRootsCanComeFromAFileAndBeOverridden(): void
    {
        mkdir($this->directory . '/lib');
        file_put_contents($this->directory . '/lib/library.json', '{"roots": ["A\\\\B\\\\C\\\\D", "G\\\\H\\\\I"]}');
        file_put_contents($this->directory . '/roots.json', '{"include": ["lib/library.json"], "roots": ["G\\\\H\\\\I as L"]}');
        $this->writeConfig("['root_files' => ['roots.json']]");
        $path = $this->writeSource("<?php\n\nnamespace App;\n\nuse A\\B\\C\\D\\E;\nuse G\\H\\I\\J;\n\nnew E();\nnew J();\n");

        $result = $this->runFixer(['fix']);

        self::assertSame(0, $result['exitCode'], $result['output']);
        self::assertSame("<?php\n\nnamespace App;\n\nuse A\\B\\C\\D;\nuse G\\H\\I as L;\n\nnew D\\E();\nnew L\\J();\n", file_get_contents($path));
    }

    public function testTheRootsOptionOverridesAFile(): void
    {
        file_put_contents($this->directory . '/roots.json', '{"roots": ["A\\\\B\\\\C\\\\D"]}');
        $this->writeConfig("['root_files' => ['roots.json'], 'roots' => ['A\\\\B\\\\C\\\\D as Q']]");
        $path = $this->writeSource(self::FIXABLE);

        self::assertSame(0, $this->runFixer(['fix'])['exitCode']);
        self::assertSame("<?php\n\nnamespace App;\n\nuse A\\B\\C\\D as Q;\n\nnew Q\\E();\n", file_get_contents($path));
    }

    public function testRootFilesWorkInParallelWorkers(): void
    {
        file_put_contents($this->directory . '/roots.json', '{"roots": ["A\\\\B\\\\C\\\\*"]}');
        $this->writeConfig("['root_files' => ['roots.json']]", parallel: true);
        $path = $this->writeSource(self::FIXABLE);

        $result = $this->runFixer(['fix']);

        self::assertSame(0, $result['exitCode'], $result['output']);
        self::assertSame(self::FIXED, file_get_contents($path));
    }

    /**
     * PHP-CS-Fixer's cache only knows the rule's options, not the files they name. The docs warn about this, so a change should be noticed.
     */
    public function testTheCacheDoesNotNoticeAChangedRootFile(): void
    {
        file_put_contents($this->directory . '/roots.json', '{"roots": ["X\\\\Y"]}');
        $this->writeConfig("['root_files' => ['roots.json']]");
        $this->writeSource(self::FIXABLE);
        self::assertSame(0, $this->runFixer(['check'])['exitCode']);

        file_put_contents($this->directory . '/roots.json', '{"roots": ["A\\\\B\\\\C\\\\*"]}');

        self::assertSame(['stale' => 0, 'fresh' => 8], [
            'stale' => $this->runFixer(['check'])['exitCode'],
            'fresh' => $this->runFixer(['check', '--using-cache=no'])['exitCode'],
        ]);
    }

    public function testEnablingTheRuleWithoutRootsIsNotASilentNoOp(): void
    {
        $this->writeConfig('true');
        $this->writeSource(self::FIXABLE);

        $result = $this->runFixer(['fix'], '-vv');

        self::assertSame(64, $result['exitCode'], $result['output']);
        self::assertStringContainsString('"roots" or "root_files" option is required', $result['output']);
    }
}
