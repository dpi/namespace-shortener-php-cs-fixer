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

/**
 * Other rules must not undo this one, and the combination must settle after one run.
 */
final class OtherFixersTest extends CliTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function ruleSets(): iterable
    {
        yield 'no_unused_imports' => ["'no_unused_imports' => true"];
        yield 'ordered_imports' => ["'ordered_imports' => true"];
        yield 'fully_qualified_strict_types' => ["'fully_qualified_strict_types' => true"];
        yield 'fully_qualified_strict_types importing' => ["'fully_qualified_strict_types' => ['import_symbols' => true]"];
        yield 'global_namespace_import' => ["'global_namespace_import' => ['import_classes' => true]"];
        yield 'single_import_per_statement' => ["'single_import_per_statement' => true"];
        yield '@Symfony' => ["'@Symfony' => true"];
        yield '@PER-CS' => ["'@PER-CS' => true"];
    }

    #[DataProvider('ruleSets')]
    public function testRuleSurvivesAlongsideOtherRulesAndSettlesInOneRun(string $rules): void
    {
        $this->writeConfig("['roots' => ['A\\\\B\\\\C\\\\*']]", $rules);
        $path = $this->writeSource(<<<'PHP'
            <?php

            namespace App;

            use A\B\C\D\E;
            use A\B\C\D\F;

            /**
             * @param \A\B\C\D\G $g
             */
            function run(E $e, $g): F
            {
                return new F($e, new \A\B\C\D\H());
            }

            PHP);

        $result = $this->runFixer(['fix']);
        self::assertSame(0, $result['exitCode'], $result['output']);

        $code = file_get_contents($path);
        self::assertStringContainsString('use A\B\C\D;', $code);
        self::assertStringContainsString('D\E $e', $code);
        self::assertStringContainsString('D\F', $code);
        self::assertStringContainsString('new D\F(', $code);
        self::assertStringContainsString('new D\H(', $code);
        self::assertStringNotContainsString('use A\B\C\D\E;', $code);

        $second = $this->runFixer(['check']);
        self::assertSame(0, $second['exitCode'], $second['output'] . "\n" . $code);
    }

    public function testOrderedImportsSortsTheResultingImports(): void
    {
        $this->writeConfig("['roots' => ['A\\\\B\\\\C\\\\*']]", "'ordered_imports' => true");
        $path = $this->writeSource("<?php\n\nnamespace App;\n\nuse Z\\Y;\nuse A\\B\\C\\D\\E;\n\nnew E();\nnew Y();\n");

        self::assertSame(0, $this->runFixer(['fix'])['exitCode']);
        self::assertSame("<?php\n\nnamespace App;\n\nuse A\\B\\C\\D;\nuse Z\\Y;\n\nnew D\\E();\nnew Y();\n", file_get_contents($path));
    }

    public function testNoUnusedImportsKeepsTheRootImport(): void
    {
        $this->writeConfig("['roots' => ['A\\\\B\\\\C\\\\*']]", "'no_unused_imports' => true");
        $path = $this->writeSource("<?php\n\nnamespace App;\n\nuse A\\B\\C\\D\\E;\n\n/** @var E \$e */\n\$e = null;\n");

        self::assertSame(0, $this->runFixer(['fix'])['exitCode']);
        self::assertSame("<?php\n\nnamespace App;\n\nuse A\\B\\C\\D;\n\n/** @var D\\E \$e */\n\$e = null;\n", file_get_contents($path));
    }
}
