<?php

/**
 * Structural guard: no control-flow construct in lib/ may have an empty body.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * WHY THIS TEST EXISTS.
 *
 * Commit 651a055f ("refactor: Replace else clauses with early returns —
 * remove ElseExpression suppressions") rewrote ~206 else-expressions
 * mechanically. The rewrite it applied to the default-then-override shape
 * was:
 *
 *     if (C) { $x = A; } else { $x = B; }     ->     $x = B;
 *                                                    if (C) { }
 *
 * It hoisted the else-body out and deleted BOTH the `else` keyword AND the
 * if-body. The condition survived; the statement it guarded did not. PHP
 * parses the result without complaint, PHPCS and PHPMD are satisfied
 * (there is no `else` left to object to), and every affected call site
 * silently collapsed to its default branch.
 *
 * Sixteen of these survived to `development`, including:
 *
 *   - two controller endpoints that returned HTTP 200 on a service error;
 *   - the ArchiMate import/export status the admin panel renders, pinned
 *     to an empty array;
 *   - the ambtenaar `?organisation=` filter, silently dropped;
 *   - the recipient's name in three outbound e-mails, pinned to
 *     "Gebruiker";
 *   - five `is_array($x) ? $x : $x->getObject()` guards, each of which now
 *     calls a method unconditionally on a value that may be an array — a
 *     latent fatal.
 *
 * None of the 64 Hydra gates detects an empty block, so this test is the
 * detector. It is deliberately structural rather than behavioural: the
 * defect class is "a branch body went missing", which is visible in the
 * token stream and cheap to assert over the whole tree, whereas
 * behavioural coverage of all sixteen sites would need live OpenRegister
 * and mail transports.
 *
 * EMPTY `catch` BLOCKS ARE EXCLUDED, and only those. `catch (\Throwable) {}`
 * is a deliberate, readable "swallow and continue" idiom and eight of them
 * predate the refactor. Every other construct — if / elseif / else / for /
 * foreach / while / switch — is in scope.
 */
final class NoEmptyControlFlowBlockTest extends TestCase {

	/**
	 * Constructs whose body must never be empty, keyed by token id.
	 *
	 * @return array<int, string> Token id to human-readable keyword.
	 */
	private function guardedConstructs(): array {
		return [
			T_IF => 'if',
			T_ELSEIF => 'elseif',
			T_ELSE => 'else',
			T_FOR => 'for',
			T_FOREACH => 'foreach',
			T_WHILE => 'while',
			T_SWITCH => 'switch',
		];

	}//end guardedConstructs()

	/**
	 * Find every empty guarded block in a single PHP source string.
	 *
	 * Works on the token stream rather than on text so that multi-line
	 * conditions, comments between the condition and the brace, and
	 * arbitrary indentation cannot hide a finding — the original
	 * hand-rolled grep for this defect missed sites for exactly those
	 * reasons.
	 *
	 * @param string $source The PHP source to scan.
	 *
	 * @return array<int, array{line: int, keyword: string}> The findings.
	 */
	private function findEmptyBlocks(string $source): array {
		$significant = [];
		foreach (token_get_all($source) as $token) {
			if (is_array($token) === true) {
				if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) === true) {
					continue;
				}

				$significant[] = [
					'id' => $token[0],
					'text' => $token[1],
					'line' => $token[2],
				];
				continue;
			}

			$significant[] = [
				'id' => null,
				'text' => $token,
				'line' => 0,
			];
		}

		$constructs = $this->guardedConstructs();
		$findings = [];
		$count = count($significant);

		for ($i = 0; $i < ($count - 1); $i++) {
			if ($significant[$i]['text'] !== '{' || $significant[$i + 1]['text'] !== '}') {
				continue;
			}

			// Walk back over the balanced condition parentheses, if any.
			$j = ($i - 1);
			if ($j >= 0 && $significant[$j]['text'] === ')') {
				$depth = 0;
				while ($j >= 0) {
					if ($significant[$j]['text'] === ')') {
						$depth++;
					}

					if ($significant[$j]['text'] === '(') {
						$depth--;
						if ($depth === 0) {
							$j--;
							break;
						}
					}

					$j--;
				}
			}

			if ($j < 0 || isset($constructs[$significant[$j]['id']]) === false) {
				continue;
			}

			$line = 0;
			for ($k = $j; $k <= $i; $k++) {
				if ($significant[$k]['line'] > 0) {
					$line = $significant[$k]['line'];
					break;
				}
			}

			$findings[] = [
				'line' => $line,
				'keyword' => $constructs[$significant[$j]['id']],
			];
		}//end for

		return $findings;
	}//end findEmptyBlocks()

	/**
	 * The scanner must be able to report a finding. Without this arm a
	 * broken scanner and a clean tree produce byte-identical output, and
	 * the assertion below would be permanently, silently green.
	 *
	 * @return void
	 */
	public function testTheScannerDetectsTheExactShapeTheRefactorLeftBehind(): void {
		$mangled = <<<'PHP'
<?php
function f($result) {
    $statusCode = 200;
    if (isset($result['error']) === true) {
    }
    return $statusCode;
}
PHP;

		$findings = $this->findEmptyBlocks($mangled);

		$this->assertCount(1, $findings, 'The scanner must find the empty if body.');
		$this->assertSame('if', $findings[0]['keyword']);

		// And it must NOT fire on the repaired shape, or it would report
		// every fixed site as still broken.
		$repaired = <<<'PHP'
<?php
function f($result) {
    $statusCode = 200;
    if (isset($result['error']) === true) {
        $statusCode = 500;
    }
    return $statusCode;
}
PHP;

		$this->assertSame([], $this->findEmptyBlocks($repaired));

		// An empty catch is explicitly out of scope.
		$emptyCatch = <<<'PHP'
<?php
function f() {
    try {
        g();
    } catch (\Throwable $e) {
    }
}
PHP;

		$this->assertSame([], $this->findEmptyBlocks($emptyCatch));

	}//end testTheScannerDetectsTheExactShapeTheRefactorLeftBehind()

	/**
	 * No shipped file under lib/ may contain an empty guarded block.
	 *
	 * @return void
	 */
	public function testNoShippedFileContainsAnEmptyGuardedBlock(): void {
		$libDir = dirname(__DIR__, 2) . '/lib';
		$this->assertDirectoryExists($libDir, 'lib/ must exist for this scan to mean anything.');

		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($libDir));
		$scanned = 0;
		$findings = [];

		foreach ($iterator as $file) {
			if ($file->isDir() === true || $file->getExtension() !== 'php') {
				continue;
			}

			$scanned++;
			$relative = substr($file->getPathname(), (strlen($libDir) - 3));

			foreach ($this->findEmptyBlocks(file_get_contents($file->getPathname())) as $finding) {
				$findings[] = $relative . ':' . $finding['line'] . ' — empty ' . $finding['keyword'] . ' body';
			}
		}

		// Positive control on the INPUT: a zero finding count is only
		// meaningful if the scan actually read files.
		$this->assertGreaterThan(
			50,
			$scanned,
			'Fewer than 50 PHP files were scanned under lib/ — the scan did not run over the real tree, '
			. 'so a clean result says nothing.'
		);

		$this->assertSame(
			[],
			$findings,
			'Empty control-flow bodies found in lib/. Each one is a branch whose only statement was '
			. "deleted, so the code always takes its default path:\n  " . implode("\n  ", $findings)
		);

	}//end testNoShippedFileContainsAnEmptyGuardedBlock()

}//end class
