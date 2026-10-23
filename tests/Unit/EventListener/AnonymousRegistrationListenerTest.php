<?php

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\EventListener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\Stackiq\EventListener\AnonymousRegistrationListener;
use OCA\Stackiq\Service\SettingsService;
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

	public function testAnotherSchemaIsLeftAlone(): void {
		$event = $this->event(schema: '31', data: ['name' => 'A module']);

		$this->listener(user: null)->handle($event);

		self::assertSame([], $event->getModifiedData());
	}//end testAnotherSchemaIsLeftAlone()

	/**
	 * @param array<string, mixed> $data The object data.
	 */
	private function event(string $schema, array $data): ObjectCreatingEvent {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getSchema')->willReturn($schema);
		$entity->method('getObject')->willReturn($data);

		return new ObjectCreatingEvent($entity);
	}//end event()

	public function testACommandLineCreateKeepsItsValues(): void {
		$event = $this->event(schema: '30', data: ['name' => 'Seeded municipality', 'registrationStatus' => 'active']);

		$this->listener(user: null, commandLine: true)->handle($event);

		self::assertSame([], $event->getModifiedData());
	}//end testACommandLineCreateKeepsItsValues()

	private function listener(?IUser $user, bool $commandLine = false): AnonymousRegistrationListener {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getVoorzieningenConfig')->willReturn(['organisatie_schema' => '30']);

		return new AnonymousRegistrationListener($session, $settings, $commandLine);
	}//end listener()
}//end class
