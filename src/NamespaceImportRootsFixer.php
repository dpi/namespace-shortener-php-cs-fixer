<?php

/*
 * This file is part of the dpi/namespace-shortener-php-cs-fixer project.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Dpi\NamespaceShortener\PhpCsFixer;

use Dpi\NamespaceShortener\Core\Config\ImportRootConfig;
use Dpi\NamespaceShortener\Core\Exception\InvalidImportRoot;
use Dpi\NamespaceShortener\Core\Rewrite\Rewriter;
use PhpCsFixer\ConfigurationException\InvalidFixerConfigurationException;
use PhpCsFixer\ConfigurationException\RequiredFixerConfigurationException;
use PhpCsFixer\Fixer\ConfigurableFixerInterface;
use PhpCsFixer\FixerConfiguration\FixerConfigurationResolver;
use PhpCsFixer\FixerConfiguration\FixerConfigurationResolverInterface;
use PhpCsFixer\FixerConfiguration\FixerOptionBuilder;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Tokenizer\Tokens;

/**
 * Adapts the rewriter to PHP-CS-Fixer. An exception from a fixer is what makes the run fail.
 *
 * @implements ConfigurableFixerInterface<array<string, mixed>, array<string, mixed>>
 */
final class NamespaceImportRootsFixer implements ConfigurableFixerInterface
{
    public const string NAME = 'Dpi/namespace_import_roots';

    private ?Rewriter $rewriter = null;

    public function configure(array $configuration): void
    {
        $unknown = array_diff(array_keys($configuration), ['roots', 'root_files']);

        if ([] !== $unknown) {
            throw new InvalidFixerConfigurationException(self::NAME, \sprintf('Unknown options: "%s".', implode('", "', $unknown)));
        }

        if (!isset($configuration['roots']) && !isset($configuration['root_files'])) {
            throw new InvalidFixerConfigurationException(self::NAME, 'The "roots" or "root_files" option is required.');
        }

        $roots = $configuration['roots'] ?? [];
        $files = $configuration['root_files'] ?? [];

        if (!\is_array($roots)) {
            throw new InvalidFixerConfigurationException(self::NAME, 'The "roots" option must be a list of import roots.');
        }

        if (!\is_array($files)) {
            throw new InvalidFixerConfigurationException(self::NAME, 'The "root_files" option must be a list of paths.');
        }

        try {
            $this->rewriter = new Rewriter(ImportRootConfig::load($roots, $files));
        } catch (InvalidImportRoot $exception) {
            throw new InvalidFixerConfigurationException(self::NAME, $exception->getMessage(), $exception);
        }
    }

    public function getConfigurationDefinition(): FixerConfigurationResolverInterface
    {
        return new FixerConfigurationResolver([
            (new FixerOptionBuilder('roots', 'The import roots: `A\B\C` (exact), `A\B\C as D` (aliased), or `A\B\C\*` (wildcard).'))
                ->setAllowedTypes(['list<string>'])
                ->setDefault([])
                ->getOption(),
            (new FixerOptionBuilder('root_files', 'Paths to JSON files of import roots, which `roots` overrides.'))
                ->setAllowedTypes(['list<string>'])
                ->setDefault([])
                ->getOption(),
        ]);
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Import the configured namespace roots, and rewrite usages to go through them.',
            [
                new CodeSample("<?php\nuse A\\B\\C\\D\\E;\nnew E();\n", ['roots' => ['A\B\C\*']]),
                new CodeSample("<?php\nuse G\\H\\I\\J\\K;\nnew K();\n", ['roots' => ['G\H\I']]),
                new CodeSample("<?php\nuse G\\H\\I\\J\\K;\nnew K();\n", ['roots' => ['G\H\I as L']]),
            ],
        );
    }

    public function getName(): string
    {
        return self::NAME;
    }

    /**
     * Runs before fixers that tidy imports, so they see the final result.
     */
    public function getPriority(): int
    {
        return 10;
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return true;
    }

    public function isRisky(): bool
    {
        return false;
    }

    public function supports(\SplFileInfo $file): bool
    {
        return true;
    }

    public function fix(\SplFileInfo $file, Tokens $tokens): void
    {
        if (null === $this->rewriter) {
            throw new RequiredFixerConfigurationException(self::NAME, 'The "roots" or "root_files" option is required.');
        }

        $tokens->setCode($this->rewriter->rewrite($tokens->generateCode()));
    }
}
