<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use DateTime;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Stackiq\Service\EntryConfirmationRefused;
use OCA\Stackiq\Service\EntryConfirmationService;
use OCA\Stackiq\Service\Settings\CatalogueLocator;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * REQ-CDQ-002: confirming an entry sets lastConfirmedAt to now and nothing else.
 *
 * @covers \OCA\Stackiq\Service\EntryConfirmationService
 * @covers \OCA\Stackiq\Service\EntryConfirmationRefused
 */
final class EntryConfirmationServiceTest extends TestCase {
	/** @var ObjectServiceInterface&MockObject */
	private ObjectServiceInterface&MockObject $objects;

	/** @var array<int, array<string, mixed>> */
	private array $saves = [];

	/** @var list<string> */
	private array $apps = ['openregister'];

	/**
	 * Set up the doubles for each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->objects->method('find')->willReturnCallback(
			function (): ObjectEntityInterface {
				$entity = $this->createMock(originalClassName: ObjectEntityInterface::class);
				$entity->method('getObject')->willReturn(
					['name' => 'Zaaksysteem', 'provider' => 'org-1', 'lastConfirmedAt' => '2025-09-01T00:00:00+00:00', '@self' => ['id' => 'x'], 'id' => 'x']
				);
				return $entity;
			}
		);
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true): ObjectEntityInterface {
				$this->saves[] = [
					'object'   => $object,
					'register' => $options->target->register,
					'schema'   => $options->target->schema,
					'uuid'     => $options->uuid,
					'_rbac'    => $_rbac,
				];
				return $this->createMock(originalClassName: ObjectEntityInterface::class);
			}
		);
	}//end setUp()

	/**
	 * The service.
	 *
	 * @return EntryConfirmationService
	 */
	private function service(): EntryConfirmationService {
		$catalogue = $this->createMock(originalClassName: CatalogueLocator::class);
		$catalogue->method('getVoorzieningenConfig')->willReturn(['register' => '5', 'module_schema' => '11', 'gebruik_schema' => '12']);
		$apps = $this->createMock(originalClassName: IAppManager::class);
		$apps->method('getInstalledApps')->willReturnCallback(fn (): array => $this->apps);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (): object => $this->objects);
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime('2026-11-14T09:30:00+00:00'));
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new EntryConfirmationService(
			catalogue: $catalogue,
			appManager: $apps,
			container: $container,
			time: $time,
			l10n: $l10n,
			logger: new NullLogger()
		);
	}//end service()

	/**
	 * The refusal of.
	 *
	 * @param callable $call The call.
	 *
	 * @return EntryConfirmationRefused
	 */
	private function refusalOf(callable $call): EntryConfirmationRefused {
		try {
			$call();
		} catch (EntryConfirmationRefused $refusal) {
			return $refusal;
		}

		self::fail(message: 'No refusal was raised.');
	}//end refusalOf()

	/**
	 * Confirming sets last confirmed at to now and nothing else.
	 *
	 * @return void
	 */
	public function testConfirmingSetsLastConfirmedAtToNowAndNothingElse(): void {
		$answer = $this->service()->confirm(kind: 'module', id: 'm-1');

		self::assertSame(expected: ['lastConfirmedAt' => '2026-11-14T09:30:00+00:00'], actual: $answer);
		self::assertCount(expectedCount: 1, haystack: $this->saves);
		$expected = ['name' => 'Zaaksysteem', 'provider' => 'org-1', 'lastConfirmedAt' => '2026-11-14T09:30:00+00:00'];
		self::assertSame(expected: $expected, actual: $this->saves[0]['object']);
		self::assertSame(expected: 5, actual: $this->saves[0]['register']);
		self::assertSame(expected: 11, actual: $this->saves[0]['schema']);
		self::assertSame(expected: 'm-1', actual: $this->saves[0]['uuid']);
		self::assertTrue(condition: $this->saves[0]['_rbac'], message: 'Only a user who may update the entry can confirm it.');
	}//end testConfirmingSetsLastConfirmedAtToNowAndNothingElse()

	/**
	 * A usage is confirmed in the usage schema.
	 *
	 * @return void
	 */
	public function testAUsageIsConfirmedInTheUsageSchema(): void {
		$this->service()->confirm(kind: 'usage', id: 'u-1');

		self::assertSame(expected: 12, actual: $this->saves[0]['schema']);
	}//end testAUsageIsConfirmedInTheUsageSchema()

	/**
	 * An unknown kind is not found.
	 *
	 * @return void
	 */
	public function testAnUnknownKindIsNotFound(): void {
		$this->objects->expects(self::never())->method('find');

		$refusal = $this->refusalOf(call: fn () => $this->service()->confirm(kind: 'contract', id: 'c-1'));

		self::assertSame(expected: 404, actual: $refusal->status);
	}//end testAnUnknownKindIsNotFound()

	/**
	 * Without the update right the confirmation is refused.
	 *
	 * @return void
	 */
	public function testWithoutTheUpdateRightTheConfirmationIsRefused(): void {
		$this->objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->objects->method('find')->willReturn($this->createMock(originalClassName: ObjectEntityInterface::class));
		$this->objects->method('saveObject')->willThrowException(new \OCP\AppFramework\OCS\OCSForbiddenException('no'));

		$refusal = $this->refusalOf(call: fn () => $this->service()->confirm(kind: 'module', id: 'm-1'));

		self::assertSame(expected: 403, actual: $refusal->status);
		self::assertSame(expected: 'forbidden', actual: $refusal->reason);
	}//end testWithoutTheUpdateRightTheConfirmationIsRefused()

	/**
	 * An entry the user cannot read is not found.
	 *
	 * @return void
	 */
	public function testAnEntryTheUserCannotReadIsNotFound(): void {
		$this->objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->objects->method('find')->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('gone'));
		$this->objects->expects(self::never())->method('saveObject');

		self::assertSame(expected: 404, actual: $this->refusalOf(call: fn () => $this->service()->confirm(kind: 'usage', id: 'u-9'))->status);
	}//end testAnEntryTheUserCannotReadIsNotFound()

	/**
	 * A failed save is a server error.
	 *
	 * @return void
	 */
	public function testAFailedSaveIsAServerError(): void {
		$this->objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->objects->method('find')->willReturn($this->createMock(originalClassName: ObjectEntityInterface::class));
		$this->objects->method('saveObject')->willThrowException(new \RuntimeException('database down'));

		$refusal = $this->refusalOf(call: fn () => $this->service()->confirm(kind: 'module', id: 'm-1'));

		self::assertSame(expected: 500, actual: $refusal->status);
		self::assertSame(expected: 'not-stored', actual: $refusal->reason);
	}//end testAFailedSaveIsAServerError()

	/**
	 * Without open register nothing is read.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsRead(): void {
		$this->apps = [];
		$this->objects->expects(self::never())->method('find');

		self::assertSame(expected: 503, actual: $this->refusalOf(call: fn () => $this->service()->confirm(kind: 'module', id: 'm-1'))->status);
	}//end testWithoutOpenRegisterNothingIsRead()
}//end class
