<?php
declare(strict_types=1);

/**
 * PHP-CS-Fixer Konfiguration für MailPilot AI.
 *
 * Folgt der globalen CLAUDE.md-Vorgabe: PSR-12 mit Tabs (nicht Spaces).
 * Plus declare(strict_types=1) Pflicht in jedem File.
 *
 * Local: `composer cs-fix`        — fixt direkt
 * CI:    `composer cs-check`      — fail bei Diff (--dry-run)
 */
$finder = (new PhpCsFixer\Finder())
	->in([__DIR__ . '/src', __DIR__ . '/bin', __DIR__ . '/tests'])
	->notPath('var')
	->name('*.php');

return (new PhpCsFixer\Config())
	->setUsingCache(false)
	->setRiskyAllowed(true)
	->setIndent("\t")
	->setLineEnding("\n")
	->setRules([
		// Stufe 1 (Minimal-Gate): nur Mandate-Pflichten + Mechanik.
		// @PSR12 absichtlich AUS — die existierende Code-Base würde sonst
		// in 90 Files gleichzeitig bulk-umformatiert (Multi-line methods,
		// blank-line-after-php-tag etc.). PSR-12-Bulk wäre ein eigener
		// Style-Cleanup-Sprint. Hier nur was CLAUDE.md absolut verlangt:
		'declare_strict_types'   => true,   // Mandate
		'no_trailing_whitespace' => true,   // universal
		'array_syntax'           => ['syntax' => 'short'],  // langsyntax verboten
	])
	->setFinder($finder);
