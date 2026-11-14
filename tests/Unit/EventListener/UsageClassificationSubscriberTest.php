<?php

/**
 * The subscriber writes the derived level back once, and never for another schema.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\EventListener
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-002-the-overall-bbn-level-shall-be-the-highest-of-the-three-aspects-on-every-save
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\EventListener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Stackiq\EventListener\UsageClassificationSubscriber;
use OCA\Stackiq\Service\OpenRegisterLookup;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Covers UsageClassificationSubscriber with the real OpenRegister event classes.
 *
 * @covers \OCA\Stackiq\EventListener\UsageClassificationSubscriber
 * @uses   \OCA\Stackiq\Service\UsageClassification
 * @uses   \OCA\Stackiq\Service\OpenRegisterLookup
 */
final class UsageClassificationSubscriberTest extends TestCase {

	/**
	 * The saves the subscriber made, as their named arguments.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saves = [];

	/**
	 * The subscriber over a container whose usage schema is 12.
	 *
	 * @return UsageClassificationSubscriber
	 */
	private function subscriber(): UsageClassificationSubscriber {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('saveObject')->willReturnCallback(
			function ($object, $extend=[], $register=null, $schema=null, $uuid=null) {
				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				return $this->createMock(\OCA\OpenRegister\Contract\ObjectEntityInterface::class);
			}
		);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister']);
		$orContainer = $this->createMock(ContainerInterface::class);
		$orContainer->method('get')->with('OCA\\OpenRegister\\Service\\ObjectService')->willReturn($objects);

		$types = $this->createMock(ObjectTypeLocatorInterface::class);
		$types->method('getSchemaIdForObjectType')->willReturnCallback(fn (string $type) => $type === 'usage' ? 12 : null);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('ciso');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$lookup = new OpenRegisterLookup(appManager: $apps, container: $orContainer, userSession: $session, logger: $this->createMock(LoggerInterface::class));

		$services = [
			OpenRegisterLookup::class => $lookup,
			ObjectTypeLocatorInterface::class => $types,
			IUserSession::class => $session,
			LoggerInterface::class => $this->createMock(LoggerInterface::class),
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id) => $services[$id]);

		return new UsageClassificationSubscriber(container: $container);
	}//end subscriber()

	/**
	 * A usage object.
	 *
	 * @param array<string, mixed> $data   The data.
	 * @param int                  $schema The schema id.
	 *
	 * @return ObjectEntity
	 */
	private function usage(array $data, int $schema=12): ObjectEntity {
		$entity = $this->getMockBuilder(ObjectEntity::class)->disableOriginalConstructor()->getMockForAbstractClass();
		$entity->method('getUuid')->willReturn('usage-1');
		$entity->method('getId')->willReturn(7);
		$entity->method('getRegister')->willReturn('3');
		$entity->method('getSchema')->willReturn((string)$schema);
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end usage()

	public function testAClassifiedUsageGetsTheHighestLevelAndTheStamp(): void {
		$this->subscriber()->handle(new ObjectCreatedEvent($this->usage(data: ['availabilityLevel' => 'BBN1', 'integrityLevel' => 'BBN2', 'confidentialityLevel' => 'BBN3'])));

		$this->assertCount(1, $this->saves);
		$saved = $this->saves[0];
		$this->assertSame('BBN3', $saved['object']['bbnLevel']);
		$this->assertSame('ciso', $saved['object']['classifiedBy']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $saved['object']['classifiedAt']);
		$this->assertSame(['3', '12', 'usage-1'], [$saved['register'], $saved['schema'], $saved['uuid']]);
	}

	public function testItsOwnWriteBackComesBackAndWritesNothing(): void {
		$done = ['availabilityLevel' => 'BBN1', 'integrityLevel' => 'BBN2', 'confidentialityLevel' => 'BBN3', 'bbnLevel' => 'BBN3', 'classifiedAt' => '2026-11-14T09:00:00Z', 'classifiedBy' => 'ciso'];
		$before = $done;
		unset($before['bbnLevel'], $before['classifiedAt'], $before['classifiedBy']);

		$this->subscriber()->handle(new ObjectUpdatedEvent($this->usage(data: $done), $this->usage(data: $before)));

		$this->assertSame([], $this->saves);
	}

	public function testAChangedAspectOnUpdateRestamps(): void {
		$before = ['integrityLevel' => 'BBN2', 'bbnLevel' => 'BBN2'];
		$after  = ['integrityLevel' => 'BBN3', 'bbnLevel' => 'BBN2'];

		$this->subscriber()->handle(new ObjectUpdatedEvent($this->usage(data: $after), $this->usage(data: $before)));

		$this->assertSame('BBN3', $this->saves[0]['object']['bbnLevel']);
		$this->assertSame('ciso', $this->saves[0]['object']['classifiedBy']);
	}

	public function testOtherSchemasAndOtherEventsAreLeftAlone(): void {
		$subscriber = $this->subscriber();
		$subscriber->handle(new ObjectCreatedEvent($this->usage(data: ['availabilityLevel' => 'BBN3'], schema: 99)));
		$subscriber->handle(new ObjectDeletedEvent($this->usage(data: ['availabilityLevel' => 'BBN3'])));

		$this->assertSame([], $this->saves);
	}

	public function testItIsRegisteredForCreateAndUpdate(): void {
		$registrar = (string)file_get_contents(__DIR__ . '/../../../lib/EventListener/ListenerRegistrar.php');

		$this->assertStringContainsString('registerEventListener(ObjectCreatedEvent::class, UsageClassificationSubscriber::class)', $registrar);
		$this->assertStringContainsString('registerEventListener(ObjectUpdatedEvent::class, UsageClassificationSubscriber::class)', $registrar);
	}
}
