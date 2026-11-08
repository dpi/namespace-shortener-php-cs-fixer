<?php

/*
 * This file is part of the dpi/namespace-shortener-php-cs-fixer project.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Dpi\NamespaceShortener\PhpCsFixer\Tests\Integration;

use Dpi\NamespaceShortener\PhpCsFixer\NamespaceImportRootsFixer;
use Dpi\NamespaceShortener\Tests\Support\CliTestCase as BaseCliTestCase;

use function Safe\file_put_contents;

abstract class CliTestCase extends BaseCliTestCase
{
    /**
     * @param string $rule the rule's configuration, as PHP source
     * @param string $extraRules further entries for setRules(), as PHP source
     */
    protected function writeConfig(string $rule, string $extraRules = '', bool $parallel = false): void
    {
        $autoload = self::vendorPath('autoload.php');
        $parallelConfig = $parallel
            ? '->setParallelConfig(new PhpCsFixer\Runner\Parallel\ParallelConfig(2, 1))'
            : '->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::sequential())';
        $ruleName = NamespaceImportRootsFixer::NAME;

        file_put_contents($this->directory . '/.php-cs-fixer.php', <<<PHP
            <?php
            require_once '{$autoload}';

            return (new PhpCsFixer\\Config())
                ->registerCustomFixers([new Dpi\\NamespaceShortener\\PhpCsFixer\\NamespaceImportRootsFixer()])
                {$parallelConfig}
                ->setRiskyAllowed(true)
                ->setUnsupportedPhpVersionAllowed(true)
                ->setUsingCache(true)
                ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache')
                ->setFinder(PhpCsFixer\\Finder::create()->in(__DIR__ . '/src'))
                ->setRules(['{$ruleName}' => {$rule}, {$extraRules}]);
            PHP);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{exitCode: int, output: string}
     */
    protected function runFixer(array $arguments, string $verbosity = '-v'): array
    {
        return $this->runCommand([\PHP_BINARY, self::vendorPath('bin/php-cs-fixer'), ...$arguments, '--config=.php-cs-fixer.php', '--no-interaction', $verbosity]);
    }
}
