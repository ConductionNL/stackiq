<?php

/**
 * Unit tests for AangebodenGebruikService::addQueryFilters: which options
 * reach the query, under which key, and in which shape.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\AangebodenGebruikService;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Pins the query filter mapping.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Stackiq\Service\AangebodenGebruikService
 */
class AangebodenGebruikQueryFiltersTest extends TestCase {

	/**
	 * Pagination options are cast to int; the underscored spelling wins over the plain one.
	 *
	 * @return void
	 */
	public function testPaginationIsCastAndTheUnderscoredSpellingWins(): void {
		$query = $this->filter(['base' => 1], ['_limit' => '20', 'limit' => 5, 'offset' => '40', '_page' => '3']);

		$this->assertSame(['base' => 1, '_limit' => 20, '_offset' => 40, '_page' => 3], $query);
	}//end testPaginationIsCastAndTheUnderscoredSpellingWins()

	/**
	 * Non-numeric pagination is dropped, and a null underscored value falls to the plain one.
	 *
	 * @return void
	 */
	public function testNonNumericPaginationIsDropped(): void {
		$query = $this->filter([], ['_limit' => null, 'limit' => '7', '_offset' => 'abc', 'offset' => '9', '_page' => 'x']);

		// `_offset` is set, so `offset` is never consulted, and `abc` is not numeric.
		$this->assertSame(['_limit' => 7], $query);
	}//end testNonNumericPaginationIsDropped()

	/**
	 * The pass-through filters are copied as-is when non-empty, in a fixed order after pagination.
	 *
	 * @return void
	 */
	public function testNonEmptyFiltersPassThroughInOrder(): void {
		$query = $this->filter(
			[],
			[
				'endDate' => '2026-12-31',
				'startDate' => '2026-01-01',
				'suite' => ['a', 'b'],
				'status' => 'active',
				'_source' => 'database',
				'limit' => 0,
			]
		);

		$this->assertSame(
			[
				'_limit' => 0,
				'_source' => 'database',
				'status' => 'active',
				'suite' => ['a', 'b'],
				'startDate' => '2026-01-01',
				'endDate' => '2026-12-31',
			],
			$query
		);
	}//end testNonEmptyFiltersPassThroughInOrder()

	/**
	 * Empty pass-through values leave the base query untouched, and unknown options are ignored.
	 *
	 * @return void
	 */
	public function testEmptyAndUnknownOptionsAreIgnored(): void {
		$base = ['status' => 'keep', 'suite' => 'keep'];
		$query = $this->filter($base, ['status' => '', 'suite' => '0', '_source' => null, 'startDate' => [], 'endDate' => false, 'other' => 'x']);

		$this->assertSame($base, $query);
	}//end testEmptyAndUnknownOptionsAreIgnored()

	/**
	 * Run the private filter mapping.
	 *
	 * @param array<string, mixed> $base    The base query.
	 * @param array<string, mixed> $options The options.
	 *
	 * @return array<string, mixed>
	 */
	private function filter(array $base, array $options): array {
		$service = new AangebodenGebruikService(
			config: $this->createMock(IAppConfig::class),
			appManager: $this->createMock(IAppManager::class),
			container: $this->createMock(ContainerInterface::class),
			logger: $this->createMock(LoggerInterface::class),
			settingsService: $this->createMock(RegisterConfigReaderInterface::class),
			userSession: $this->createMock(IUserSession::class)
		);

		$method = new ReflectionMethod(AangebodenGebruikService::class, 'addQueryFilters');
		return $method->invoke($service, $base, $options);
	}//end filter()
}//end class
