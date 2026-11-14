<?php

/**
 * Golden test for the query options the aanbod and aangeboden-gebruik
 * listings forward to their services.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/aanbod-listings/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\AanbodController;
use OCA\Stackiq\Controller\AangebodenGebruikController;
use OCA\Stackiq\Service\AanbodService;
use OCA\Stackiq\Service\AangebodenGebruikService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Records every log call as [level, message, context].
 */
class ListingQueryLogSpy extends AbstractLogger {
	/**
	 * Recorded log calls.
	 *
	 * @var array<int, array{0:string,1:string,2:array}>
	 */
	public array $records = [];

	/**
	 * Record one log call.
	 *
	 * @param mixed              $level   The level.
	 * @param string|\Stringable $message The message.
	 * @param array              $context The context.
	 *
	 * @return void
	 */
	public function log($level, string|\Stringable $message, array $context=[]): void {
		$this->records[] = [(string)$level, (string)$message, $context];
	}
}

/**
 * Pins the parsed pagination and filter options and their debug log line.
 *
 * @spec openspec/specs/aanbod-listings/spec.md
 */
class ListingQueryOptionsGoldenTest extends TestCase {
	/**
	 * Call a listing with the given query parameters.
	 *
	 * @param string $listing The listing key.
	 * @param array  $params  The query parameters.
	 *
	 * @return array [forwarded options, debug log lines, requested parameter names]
	 */
	private function callListing(string $listing, array $params): array {
		$asked   = [];
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(
			function (string $name) use ($params, &$asked) {
				$asked[] = $name;
				return $params[$name] ?? null;
			}
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));
		$log       = new ListingQueryLogSpy();
		$forwarded = [];
		$reply     = function (array $options) use (&$forwarded) {
			$forwarded = $options;
			return ['results' => [], 'total' => 0];
		};

		if ($listing === 'aanbod') {
			$service = $this->createMock(AanbodService::class);
			$service->method('getAanbod')->willReturnCallback($reply);
			(new AanbodController('stackiq', $request, $session, $service, $log))->getAanbod();
		} else {
			$service = $this->createMock(AangebodenGebruikService::class);
			$service->method('getGebruiksWhereAfnemer')->willReturnCallback($reply);
			(new AangebodenGebruikController('stackiq', $request, $session, $service, $log, $this->createMock(IGroupManager::class)))->getGebruiksWhereAfnemer();
		}

		$debug = array_values(array_filter($log->records, fn (array $record): bool => $record[0] === 'debug'));
		return [$forwarded, $debug, $asked];
	}

	/**
	 * Pagination with and without underscore, numeric checks, and the filters.
	 *
	 * @return void
	 */
	public function testParsedOptions(): void {
		$cases = [
			'none'        => [],
			'plain'       => ['limit' => '10', 'offset' => '5', 'page' => '2'],
			'underscored' => ['_limit' => '30', 'limit' => '10', '_offset' => '0', '_page' => '3', 'page' => '9'],
			'non-numeric' => ['limit' => 'ten', 'offset' => '', 'page' => '1.5'],
			'numeric-str' => ['limit' => ' 7', '_offset' => '1e1', 'page' => '-2'],
			'filters'     => ['status' => ' actief ', 'startDate' => '2026-01-01', 'endDate' => ' ', 'limit' => 4],
		];
		$out = [];
		foreach (['aanbod', 'gebruik'] as $listing) {
			foreach ($cases as $name => $params) {
				$out[$listing . '/' . $name] = $this->callListing(listing: $listing, params: $params);
			}
		}

		$path   = dirname(__DIR__, 2) . '/fixtures/object-actions/listing-options.json';
		$actual = json_decode((string)json_encode($out, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR), $actual);
	}
}
