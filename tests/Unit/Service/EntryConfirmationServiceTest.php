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
	private ObjectServiceInterface&MockObject $objects;

	/** @var array<int, array<string, mixed>> */
	private array $saves = [];

	/** @var list<string> */
	private array $apps = ['openregister'];

	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->objects->method('find')->willReturnCallback(
			function (): ObjectEntityInterface {
				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('getObject')->willReturn(
					['name' => 'Zaaksysteem', 'provider' => 'org-1', 'lastConfirmedAt' => '2025-09-01T00:00:00+00:00', '@self' => ['id' => 'x'], 'id' => 'x']
				);
				return $entity;
			}
		);
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null, bool $_rbac = true): ObjectEntityInterface {
				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid, '_rbac' => $_rbac];
				return $this->createMock(ObjectEntityInterface::class);
			}
		);
	}//end setUp()

	private function service(): EntryConfirmationService {
		$catalogue = $this->createMock(CatalogueLocator::class);
		$catalogue->method('getVoorzieningenConfig')->willReturn(['register' => '5', 'module_schema' => '11', 'gebruik_schema' => '12']);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturnCallback(fn (): array => $this->apps);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (): object => $this->objects);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime('2026-11-14T09:30:00+00:00'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new EntryConfirmationService($catalogue, $apps, $container, $time, $l10n, new NullLogger());
	}//end service()

	private function refusalOf(callable $call): EntryConfirmationRefused {
		try {
			$call();
		} catch (EntryConfirmationRefused $refusal) {
			return $refusal;
		}

		self::fail('No refusal was raised.');
	}//end refusalOf()

	public function testConfirmingSetsLastConfirmedAtToNowAndNothingElse(): void {
		$answer = $this->service()->confirm(kind: 'module', id: 'm-1');

		self::assertSame(['lastConfirmedAt' => '2026-11-14T09:30:00+00:00'], $answer);
		self::assertCount(1, $this->saves);
		self::assertSame(['name' => 'Zaaksysteem', 'provider' => 'org-1', 'lastConfirmedAt' => '2026-11-14T09:30:00+00:00'], $this->saves[0]['object']);
		self::assertSame(5, $this->saves[0]['register']);
		self::assertSame(11, $this->saves[0]['schema']);
		self::assertSame('m-1', $this->saves[0]['uuid']);
		self::assertTrue($this->saves[0]['_rbac'], 'Only a user who may update the entry can confirm it.');
	}//end testConfirmingSetsLastConfirmedAtToNowAndNothingElse()

	public function testAUsageIsConfirmedInTheUsageSchema(): void {
		$this->service()->confirm(kind: 'usage', id: 'u-1');

		self::assertSame(12, $this->saves[0]['schema']);
	}//end testAUsageIsConfirmedInTheUsageSchema()

	public function testAnUnknownKindIsNotFound(): void {
		$this->objects->expects(self::never())->method('find');

		$refusal = $this->refusalOf(fn () => $this->service()->confirm(kind: 'contract', id: 'c-1'));

		self::assertSame(404, $refusal->status);
	}//end testAnUnknownKindIsNotFound()

	public function testWithoutTheUpdateRightTheConfirmationIsRefused(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->objects->method('find')->willReturn($this->createMock(ObjectEntityInterface::class));
		$this->objects->method('saveObject')->willThrowException(new \OCP\AppFramework\OCS\OCSForbiddenException('no'));

		$refusal = $this->refusalOf(fn () => $this->service()->confirm(kind: 'module', id: 'm-1'));

		self::assertSame(403, $refusal->status);
		self::assertSame('forbidden', $refusal->reason);
	}//end testWithoutTheUpdateRightTheConfirmationIsRefused()

	public function testAnEntryTheUserCannotReadIsNotFound(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->objects->method('find')->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('gone'));
		$this->objects->expects(self::never())->method('saveObject');

		self::assertSame(404, $this->refusalOf(fn () => $this->service()->confirm(kind: 'usage', id: 'u-9'))->status);
	}//end testAnEntryTheUserCannotReadIsNotFound()

	public function testAFailedSaveIsAServerError(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->objects->method('find')->willReturn($this->createMock(ObjectEntityInterface::class));
		$this->objects->method('saveObject')->willThrowException(new \RuntimeException('database down'));

		$refusal = $this->refusalOf(fn () => $this->service()->confirm(kind: 'module', id: 'm-1'));

		self::assertSame(500, $refusal->status);
		self::assertSame('not-stored', $refusal->reason);
	}//end testAFailedSaveIsAServerError()

	public function testWithoutOpenRegisterNothingIsRead(): void {
		$this->apps = [];
		$this->objects->expects(self::never())->method('find');

		self::assertSame(503, $this->refusalOf(fn () => $this->service()->confirm(kind: 'module', id: 'm-1'))->status);
	}//end testWithoutOpenRegisterNothingIsRead()
}//end class
