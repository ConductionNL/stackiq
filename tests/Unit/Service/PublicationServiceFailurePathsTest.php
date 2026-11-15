<?php

/**
 * Unit tests for the failure paths of PublicationService.
 *
 * Covers what publish() and depublish() answer, and log, when OpenRegister
 * is absent, the type has no register/schema, the entry cannot be found or
 * the save fails; plus the exact save call and the publication date
 * normalisation.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/open-data-publishing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Stackiq\Service\PublicationService;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Failure-path cases for PublicationService.
 *
 * @covers \OCA\Stackiq\Service\PublicationService
 *
 * @spec openspec/specs/open-data-publishing/spec.md
 */
class PublicationServiceFailurePathsTest extends TestCase {

	/**
	 * The DI container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * The register/schema locator.
	 *
	 * @var ObjectTypeLocatorInterface&MockObject
	 */
	private ObjectTypeLocatorInterface&MockObject $locator;

	/**
	 * The OpenRegister object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * Every log call as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logs = [];

	/**
	 * The service under test.
	 *
	 * @var PublicationService
	 */
	private PublicationService $service;

	/**
	 * Build the service with boundary doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->container = $this->createMock(ContainerInterface::class);
		$this->locator   = $this->createMock(ObjectTypeLocatorInterface::class);
		$this->objects   = $this->createMock(ObjectServiceInterface::class);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, (string) $message, $context];
				}
			);
		}

		$this->service = new PublicationService($this->container, $this->locator, $logger);

	}//end setUp()

	/**
	 * Configure a resolvable entry with the given data.
	 *
	 * @param array<string, mixed> $data The entry data.
	 *
	 * @return void
	 */
	private function entryExists(array $data): void {
		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($this->objects);
		$this->locator->method('getSchemaIdForObjectType')->with('module')->willReturn(8);
		$this->locator->method('getRegisterIdForObjectType')->with('module')->willReturn(2);

		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($data);
		$this->objects->method('find')->willReturn($entity);

	}//end entryExists()

	/**
	 * Without OpenRegister nothing resolves, and the failure is logged.
	 *
	 * @return void
	 */
	public function testMissingObjectServiceMakesTheEntryUnresolvable(): void {
		$this->container->method('get')->willThrowException(new RuntimeException('not installed'));
		$this->locator->expects($this->never())->method('getSchemaIdForObjectType');

		$this->assertNull($this->service->resolveEntry(objectType: 'module', uuid: 'u-1'));
		$this->assertSame(
			[['error', 'PublicationService: Failed to get ObjectService', ['exception' => 'not installed']]],
			$this->logs
		);

	}//end testMissingObjectServiceMakesTheEntryUnresolvable()

	/**
	 * A type without a configured schema is not resolvable, with a warning.
	 *
	 * @return void
	 */
	public function testUnconfiguredSchemaMakesTheEntryUnresolvable(): void {
		$this->container->method('get')->willReturn($this->objects);
		$this->locator->method('getSchemaIdForObjectType')->willReturn(null);
		$this->locator->method('getRegisterIdForObjectType')->willReturn(2);
		$this->objects->expects($this->never())->method('find');

		$result = $this->service->publish(objectType: 'connection', uuid: 'u-2');

		$this->assertSame(['ok' => false, 'reason' => 'entry not resolvable', 'publicationDate' => null], $result);
		$this->assertSame(
			[['warning', 'PublicationService: register/schema not configured', ['objectType' => 'connection']]],
			$this->logs
		);

	}//end testUnconfiguredSchemaMakesTheEntryUnresolvable()

	/**
	 * A lookup that throws is answered as not found and logged at debug level.
	 *
	 * @return void
	 */
	public function testFindThatThrowsMakesTheEntryUnresolvable(): void {
		$this->container->method('get')->willReturn($this->objects);
		$this->locator->method('getSchemaIdForObjectType')->willReturn(8);
		$this->locator->method('getRegisterIdForObjectType')->willReturn(2);
		$this->objects->method('find')->willThrowException(new RuntimeException('gone'));
		$this->objects->expects($this->never())->method('saveObject');

		$result = $this->service->depublish(objectType: 'module', uuid: 'u-3');

		$this->assertSame(['ok' => false, 'reason' => 'entry not resolvable', 'publicationDate' => null], $result);
		$this->assertSame(
			[['debug', 'PublicationService: entry not found', ['objectType' => 'module', 'uuid' => 'u-3']]],
			$this->logs
		);

	}//end testFindThatThrowsMakesTheEntryUnresolvable()

	/**
	 * A lookup that finds nothing is not resolvable.
	 *
	 * @return void
	 */
	public function testFindReturningNothingMakesTheEntryUnresolvable(): void {
		$this->container->method('get')->willReturn($this->objects);
		$this->locator->method('getSchemaIdForObjectType')->willReturn(8);
		$this->locator->method('getRegisterIdForObjectType')->willReturn(2);
		$this->objects->expects($this->once())
			->method('find')
			->with('u-4', [], false, '2', '8')
			->willReturn(null);

		$this->assertNull($this->service->resolveEntry(objectType: 'module', uuid: 'u-4'));

	}//end testFindReturningNothingMakesTheEntryUnresolvable()

	/**
	 * A resolved entry carries its integer register and schema and its data.
	 *
	 * @return void
	 */
	public function testResolvedEntryCarriesRegisterSchemaAndData(): void {
		$this->entryExists(['name' => 'Zaaksysteem']);

		$this->assertSame(
			['register' => 2, 'schema' => 8, 'data' => ['name' => 'Zaaksysteem']],
			$this->service->resolveEntry(objectType: 'module', uuid: 'u-5')
		);

	}//end testResolvedEntryCarriesRegisterSchemaAndData()

	/**
	 * A failing save answers not ok with the save's message, and logs it.
	 *
	 * @return void
	 */
	public function testFailingSaveAnswersNotOkWithTheReason(): void {
		$this->entryExists(['name' => 'X']);
		$this->objects->method('saveObject')->willThrowException(new RuntimeException('locked'));

		$result = $this->service->publish(objectType: 'module', uuid: 'u-6');

		$this->assertSame(['ok' => false, 'reason' => 'locked', 'publicationDate' => null], $result);
		$this->assertSame(
			[['error', 'PublicationService: save failed', ['objectType' => 'module', 'uuid' => 'u-6', 'error' => 'locked']]],
			$this->logs
		);

	}//end testFailingSaveAnswersNotOkWithTheReason()

	/**
	 * Publishing saves the data bag under the entry's register, schema and uuid
	 * with the given moment normalised to UTC ISO-8601.
	 *
	 * @return void
	 */
	public function testPublishSavesTheNormalisedMomentUnderTheEntry(): void {
		$this->entryExists(['name' => 'X', 'depublicationDate' => '2020-01-01T00:00:00+00:00']);
		$this->objects->expects($this->once())
			->method('saveObject')
			->with(
				['name' => 'X', 'depublicationDate' => null, 'publicationDate' => '2030-05-01T10:00:00+00:00'],
				[],
				2,
				8,
				'u-7'
			);

		$result = $this->service->publish(objectType: 'module', uuid: 'u-7', when: '2030-05-01T12:00:00+02:00');

		$this->assertSame(['ok' => true, 'reason' => 'published', 'publicationDate' => '2030-05-01T10:00:00+00:00'], $result);
		$this->assertSame(
			[['info', 'PublicationService: published', ['objectType' => 'module', 'uuid' => 'u-7', 'publicationDate' => '2030-05-01T10:00:00+00:00']]],
			$this->logs
		);

	}//end testPublishSavesTheNormalisedMomentUnderTheEntry()

	/**
	 * An unparseable or blank moment publishes now instead.
	 *
	 * @return void
	 */
	public function testUnparseableOrBlankMomentPublishesNow(): void {
		$this->entryExists([]);

		foreach (['not a date', '   '] as $when) {
			$before = gmdate('Y-m-d\TH:i:sP');
			$result = $this->service->publish(objectType: 'module', uuid: 'u-8', when: $when);
			$after  = gmdate('Y-m-d\TH:i:sP');

			$this->assertTrue($result['ok']);
			$this->assertGreaterThanOrEqual($before, $result['publicationDate']);
			$this->assertLessThanOrEqual($after, $result['publicationDate']);
		}

	}//end testUnparseableOrBlankMomentPublishesNow()
}//end class
