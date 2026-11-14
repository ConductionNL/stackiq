<?php

/**
 * Unit tests for StandardProtocolPaths.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Middleware\Support
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Middleware\Support;

use OCA\Stackiq\Middleware\Support\StandardProtocolPaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests which request paths are standard protocols that stay unversioned.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Middleware\Support
 *
 * @covers \OCA\Stackiq\Middleware\Support\StandardProtocolPaths
 */
class StandardProtocolPathsTest extends TestCase {

	/**
	 * Paths and their app-relative form.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function relativePaths(): array {
		return [
			'front controller'     => ['/index.php/apps/stackiq/api/health', '/api/health'],
			'pretty url'           => ['/apps/stackiq/api/metrics', '/api/metrics'],
			'no leading slash'     => ['apps/stackiq/api/organisaties', '/api/organisaties'],
			'already relative'     => ['/api/health', '/api/health'],
			'relative, no slash'   => ['api/health', '/api/health'],
			'another app'          => ['/apps/stackiqx/api/health', '/apps/stackiqx/api/health'],
			'app root only'        => ['/apps/stackiq', '/apps/stackiq'],
		];

	}//end relativePaths()

	/**
	 * The app root is stripped; anything else gets a single leading slash.
	 *
	 * @param string $path     The request path.
	 * @param string $expected The app-relative path.
	 *
	 * @return void
	 */
	#[DataProvider('relativePaths')]
	public function testAppRelative(string $path, string $expected): void {
		$this->assertSame($expected, StandardProtocolPaths::appRelative($path));

	}//end testAppRelative()

	/**
	 * Paths and whether they are a standard protocol.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public static function protocolPaths(): array {
		return [
			'progress stream'          => ['/index.php/apps/stackiq/api/progress/op-1/stream', true],
			'health'                   => ['/apps/stackiq/api/health', true],
			'metrics'                  => ['/api/metrics', true],
			'progress without stream'  => ['/apps/stackiq/api/progress/op-1', false],
			'stream of nested id'      => ['/apps/stackiq/api/progress/a/b/stream', false],
			'health sub path'          => ['/apps/stackiq/api/health/db', false],
			'versioned health'         => ['/apps/stackiq/api/v1/health', false],
			'ordinary endpoint'        => ['/apps/stackiq/api/organisaties', false],
		];

	}//end protocolPaths()

	/**
	 * Only the exact stream, health and metrics paths match.
	 *
	 * @param string $path     The request path.
	 * @param bool   $expected Whether it is a standard protocol.
	 *
	 * @return void
	 */
	#[DataProvider('protocolPaths')]
	public function testMatches(string $path, bool $expected): void {
		$this->assertSame($expected, StandardProtocolPaths::matches($path));

	}//end testMatches()

}//end class
