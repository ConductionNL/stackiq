<?php

/**
 * Unit tests for saving a GebruikBulkHandler batch.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service\AangebodenGebruik
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\AangebodenGebruik;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\AangebodenGebruik\GebruikBulkHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A valid batch is saved item by item, and the outcome counts what was created and what failed.
 *
 * @covers \OCA\Stackiq\Service\AangebodenGebruik\GebruikBulkHandler
 */
class GebruikBulkSaveTest extends TestCase {
	/**
	 * A saved object with a uuid.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntityInterface The object.
	 */
	private function saved(string $uuid): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getUuid')->willReturn($uuid);
		return $entity;
	}

	/**
	 * Every item is saved in the given register and schema.
	 *
	 * @return void
	 */
	public function testEveryItemIsSavedAndCounted(): void {
		$items         = [['consumer' => 'c1', 'provider' => 'p1'], ['consumer' => 'c2', 'provider' => 'p2', 'note' => 'x']];
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$saved         = [];
		$objectService->expects($this->exactly(2))->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null) use (&$saved): ObjectEntityInterface {
				$saved[] = [$object, $register, $schema];
				return $this->saved('u-'.count($saved));
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$result = (new GebruikBulkHandler($objectService, $logger))->bulkCreate($items, 'stackiq', 'gebruik');

		self::assertSame(['success' => true, 'created' => 2, 'failed' => 0, 'errors' => []], $result);
		self::assertSame([[$items[0], 'stackiq', 'gebruik'], [$items[1], 'stackiq', 'gebruik']], $saved);
	}

	/**
	 * A failing item is reported by index and does not stop the others.
	 *
	 * @return void
	 */
	public function testAFailingItemIsReportedByIndex(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntityInterface {
				if ($object['consumer'] === 'bad') {
					throw new RuntimeException('schema validation failed');
				}

				return $this->saved('u');
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('GebruikBulkHandler: Failed to create bulk item', ['index' => 1, 'exception' => 'schema validation failed']);

		$result = (new GebruikBulkHandler($objectService, $logger))->bulkCreate(
			[['consumer' => 'ok', 'provider' => 'p'], ['consumer' => 'bad', 'provider' => 'p'], ['consumer' => 'ok', 'provider' => 'q']],
			'r',
			's'
		);

		self::assertSame(['success' => false, 'created' => 2, 'failed' => 1, 'errors' => ['[1] schema validation failed']], $result);
	}
}
