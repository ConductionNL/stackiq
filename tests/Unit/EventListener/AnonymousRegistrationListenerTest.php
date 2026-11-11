<?php

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\EventListener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\Stackiq\EventListener\AnonymousRegistrationListener;
use OCA\Stackiq\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * REQ-ORR-001: an organisation created without a signed-in user is pending, whatever the request says.
 */
final class AnonymousRegistrationListenerTest extends TestCase {
	public function testAnAnonymousCreateIsPendingAndUnpublished(): void {
		$event = $this->event(schema: '30', data: ['name' => 'Open Kaart BV', 'type' => 'Supplier', 'registrationStatus' => 'active', 'publicationDate' => '2026-10-01T00:00:00+00:00']);

		$this->listener(user: null)->handle($event);

		self::assertSame('pending', $event->getModifiedData()['registrationStatus']);
		self::assertArrayHasKey('publicationDate', $event->getModifiedData());
		self::assertNull($event->getModifiedData()['publicationDate']);
	}//end testAnAnonymousCreateIsPendingAndUnpublished()

	public function testItKeepsWhatAnotherListenerAlreadyChanged(): void {
		$event = $this->event(schema: '30', data: ['name' => 'Open Kaart BV']);
		$event->setModifiedData(['summary' => 'set earlier']);

		$this->listener(user: null)->handle($event);

		self::assertSame('set earlier', $event->getModifiedData()['summary']);
		self::assertSame('pending', $event->getModifiedData()['registrationStatus']);
	}//end testItKeepsWhatAnotherListenerAlreadyChanged()

	public function testASignedInCreateKeepsItsValues(): void {
		$event = $this->event(schema: '30', data: ['name' => 'Gemeente Zuiddrecht', 'registrationStatus' => 'active']);

		$this->listener(user: $this->createMock(IUser::class))->handle($event);

		self::assertSame([], $event->getModifiedData());
	}//end testASignedInCreateKeepsItsValues()

	public function testAnAnonymousCreateCarryingAnOrganisationsUuidIsRefused(): void {
		$event = $this->event(schema: '30', data: ['name' => 'Chosen'], uuid: 'held-uuid');

		$this->listener(user: null, held: ['held-uuid'])->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(['message' => 'This organisation could not be registered.'], $event->getErrors());
		self::assertSame([], $event->getModifiedData());
	}//end testAnAnonymousCreateCarryingAnOrganisationsUuidIsRefused()

	public function testAnAnonymousCreateWithAFreeUuidGoesAhead(): void {
		foreach (['free-uuid', null, ''] as $uuid) {
			$event = $this->event(schema: '30', data: ['name' => 'Chosen'], uuid: $uuid);

			$this->listener(user: null, held: ['held-uuid', ''])->handle($event);

			self::assertFalse($event->isPropagationStopped());
			self::assertSame('pending', $event->getModifiedData()['registrationStatus']);
		}
	}//end testAnAnonymousCreateWithAFreeUuidGoesAhead()

	public function testOnlyAnAnonymousOrganisationCreateIsRefused(): void {
		$cases = [
			[$this->createMock(IUser::class), false, '30'],
			[null, true, '30'],
			[null, false, '31'],
		];
		foreach ($cases as [$user, $commandLine, $schema]) {
			$event = $this->event(schema: $schema, data: ['name' => 'Chosen'], uuid: 'held-uuid');

			$this->listener(user: $user, commandLine: $commandLine, held: ['held-uuid'])->handle($event);

			self::assertFalse($event->isPropagationStopped());
		}
	}//end testOnlyAnAnonymousOrganisationCreateIsRefused()

	public function testAnotherEventOrAnUnconfiguredSchemaIsLeftAlone(): void {
		$other = $this->createMock(\OCP\EventDispatcher\Event::class);
		$this->listener(user: null, held: ['held-uuid'])->handle($other);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getVoorzieningenConfig')->willReturn([]);
		$organisations = $this->createMock(OrganisationMapper::class);
		$organisations->expects($this->never())->method('findByUuid');
		$event = $this->event(schema: '', data: ['name' => 'Chosen'], uuid: 'held-uuid');

		(new AnonymousRegistrationListener($session, $settings, $organisations, false))->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $event->getModifiedData());
	}//end testAnotherEventOrAnUnconfiguredSchemaIsLeftAlone()

	public function testTheRefusalSaysOnlyThatTheOrganisationCouldNotBeRegistered(): void {
		$event = $this->event(schema: '30', data: ['name' => 'Chosen'], uuid: 'held-uuid');

		$this->listener(user: null, held: ['held-uuid'])->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(['message' => 'This organisation could not be registered.'], $event->getErrors());
		self::assertSame([], $event->getModifiedData());
	}//end testTheRefusalSaysOnlyThatTheOrganisationCouldNotBeRegistered()

	public function testAnotherSchemaIsLeftAlone(): void {
		$event = $this->event(schema: '31', data: ['name' => 'A module']);

		$this->listener(user: null)->handle($event);

		self::assertSame([], $event->getModifiedData());
	}//end testAnotherSchemaIsLeftAlone()

	/**
	 * @param array<string, mixed> $data The object data.
	 */
	private function event(string $schema, array $data, ?string $uuid = null): ObjectCreatingEvent {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getSchema')->willReturn($schema);
		$entity->method('getObject')->willReturn($data);
		$entity->method('getUuid')->willReturn($uuid);

		return new ObjectCreatingEvent($entity);
	}//end event()

	public function testACommandLineCreateKeepsItsValues(): void {
		$event = $this->event(schema: '30', data: ['name' => 'Seeded municipality', 'registrationStatus' => 'active']);

		$this->listener(user: null, commandLine: true)->handle($event);

		self::assertSame([], $event->getModifiedData());
	}//end testACommandLineCreateKeepsItsValues()

	/**
	 * @param list<string> $held The uuids OpenRegister organisations hold.
	 */
	private function listener(?IUser $user, bool $commandLine = false, array $held = []): AnonymousRegistrationListener {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getVoorzieningenConfig')->willReturn(['organisatie_schema' => '30']);

		$organisations = $this->createMock(OrganisationMapper::class);
		$organisations->method('findByUuid')->willReturnCallback(
			function (string $uuid) use ($held) {
				if (in_array($uuid, $held, true) === false) {
					throw new DoesNotExistException('missing');
				}

				return $this->createMock(Organisation::class);
			}
		);

		return new AnonymousRegistrationListener($session, $settings, $organisations, $commandLine);
	}//end listener()
}//end class
