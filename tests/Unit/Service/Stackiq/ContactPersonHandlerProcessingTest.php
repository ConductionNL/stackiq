<?php

/**
 * Processing a contact person into an inactive account and its organisation membership.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Stackiq
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Stackiq;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCA\Stackiq\Service\SymfonyEmailService;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * processContactpersoon() for a contact without a username: the account is
 * made inactive, the username is written back and the contact's organisation
 * entity is sought to add the user to; a contact that gets no account fails the event
 * handlers without touching any user, and the membership helpers refuse
 * when OpenRegister's object service cannot be resolved.
 *
 * @covers \OCA\Stackiq\Service\Stackiq\ContactPersonHandler
 */
class ContactPersonHandlerProcessingTest extends TestCase {

	/**
	 * Every log line, as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	public array $logs = [];

	/**
	 * A log message whose logging fails, if any.
	 *
	 * @var string|null
	 */
	public ?string $failingMessage = null;

	private IUserManager&MockObject $users;

	private IConfig&MockObject $config;

	private OrganisationMapper&MockObject $mapper;

	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The apps OpenRegister reports as installed.
	 *
	 * @var array<int, string>
	 */
	private array $installed = [];

	/**
	 * Whether the container resolves OpenRegister's object service.
	 *
	 * @var boolean
	 */
	private bool $objectServiceResolves = true;

	/**
	 * Wire the boundary doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->users   = $this->createMock(IUserManager::class);
		$this->config  = $this->createMock(IConfig::class);
		$this->mapper  = $this->createMock(OrganisationMapper::class);
		$this->objects = $this->createMock(ObjectServiceInterface::class);
	}//end setUp()

	/**
	 * The handler under test.
	 *
	 * @return ContactPersonHandler
	 */
	private function handler(): ContactPersonHandler {
		$registers = $this->createMock(RegisterConfigReaderInterface::class);
		$registers->method('getVoorzieningenRegisterId')->willReturn(4);
		$registers->method('getSchemaIdForObjectType')->willReturn(5);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturnCallback(fn (): array => $this->installed);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($registers) {
				if ($id === 'OCA\OpenRegister\Service\ObjectService') {
					return $this->objectServiceResolves === true ? $this->objects : null;
				}

				return [
					'OCA\\OpenRegister\\Db\\OrganisationMapper' => $this->mapper,
					RegisterConfigReaderInterface::class      => $registers,
				][$id] ?? null;
			}
		);

		return new ContactPersonHandler(
			_userManager: $this->users,
			_secureRandom: $this->createMock(ISecureRandom::class),
			_groupManager: $this->createMock(IGroupManager::class),
			_config: $this->createMock(IAppConfig::class),
			_container: $container,
			_appManager: $apps,
			_logger: $this->logger(),
			_emailService: $this->createMock(SymfonyEmailService::class),
			config: $this->config,
		);
	}//end handler()

	/**
	 * A logger recording into $logs, failing on $failingMessage.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		return new class($this) extends AbstractLogger {
			public function __construct(private ContactPersonHandlerProcessingTest $test) {
			}

			public function log($level, string|\Stringable $message, array $context = []): void {
				if ((string) $message === $this->test->failingMessage) {
					throw new RuntimeException('log sink full');
				}

				$this->test->logs[] = [(string) $level, (string) $message, $context];
			}
		};
	}//end logger()

	/**
	 * A contact person that records what is written back to it.
	 *
	 * @param array<string, mixed> $data The contact data.
	 *
	 * @return object
	 */
	private static function contact(array $data): object {
		return new class($data) {
			public ?array $written = null;

			/**
			 * @param array<string, mixed> $data The contact data.
			 */
			public function __construct(private array $data) {
			}

			public function getId(): int {
				return 21;
			}

			public function getUuid(): string {
				return 'contact-uuid-21';
			}

			public function getObject(): array {
				return $this->data;
			}

			public function setObject(array $data): void {
				$this->written = $data;
			}
		};
	}//end contact()

	/**
	 * The contexts logged at this level with exactly this message.
	 *
	 * @param string $level   The level.
	 * @param string $message The message.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function logged(string $level, string $message): array {
		$contexts = [];
		foreach ($this->logs as [$lineLevel, $lineMessage, $context]) {
			if ($lineLevel === $level && $lineMessage === $message) {
				$contexts[] = $context;
			}
		}

		return $contexts;
	}//end logged()

	/**
	 * The messages logged at this level.
	 *
	 * @param string $level The level.
	 *
	 * @return array<int, string>
	 */
	private function messages(string $level): array {
		$messages = [];
		foreach ($this->logs as [$lineLevel, $lineMessage]) {
			if ($lineLevel === $level) {
				$messages[] = $lineMessage;
			}
		}

		return $messages;
	}//end messages()

	public function testANewContactGetsAnInactiveAccountAndItsOrganisationEntityIsSought(): void {
		$anna = $this->createMock(IUser::class);
		$anna->method('getUID')->willReturn('anna@gemeente.nl');
		$anna->method('getEMailAddress')->willReturn('anna@gemeente.nl');
		$anna->expects($this->once())->method('setEnabled')->with(false);
		$this->users->method('userExists')->with('anna@gemeente.nl')->willReturn(true);
		$this->users->method('get')->with('anna@gemeente.nl')->willReturn($anna);
		$this->users->expects($this->never())->method('createUser');
		// The organisation has no entity yet and OpenRegister is not installed to create one from.
		$this->mapper->expects($this->exactly(2))->method('findByUuid')->with('org-1')
			->willThrowException(new DoesNotExistException('missing'));
		$this->mapper->expects($this->never())->method('update');

		$contact = self::contact(['email' => 'anna@gemeente.nl', 'organisation' => 'org-1']);

		$this->assertTrue($this->handler()->processContactpersoon($contact));

		$this->assertSame(['email' => 'anna@gemeente.nl', 'organisation' => 'org-1', 'username' => 'anna@gemeente.nl'], $contact->written);
		$this->assertSame(
			[['organizationUuid' => 'org-1']],
			$this->logged('info', 'ContactPersonHandler: No organization entity, user not added')
		);
		$this->assertSame(
			[['username' => 'anna@gemeente.nl', 'objectId' => 21]],
			$this->logged('info', 'Successfully created inactive user and updated contactpersoon')
		);
		$this->assertSame([['username' => 'anna@gemeente.nl']], $this->logged('info', 'Set user account to inactive'));
	}//end testANewContactGetsAnInactiveAccountAndItsOrganisationEntityIsSought()

	public function testAContactThatGetsNoAccountFailsAndTouchesNoUser(): void {
		$this->users->expects($this->never())->method('createUser');
		$this->users->expects($this->never())->method('get');
		$this->mapper->expects($this->never())->method('findByUuid');
		$contact = self::contact(['voornaam' => 'Anna', 'organisation' => 'org-1']);

		try {
			$this->handler()->processContactpersoon($contact);
			$this->fail('A contact that gets no account must not be processed');
		} catch (\Exception $e) {
			$this->assertSame('Failed to create user account', $e->getMessage());
		}

		$this->assertNull($contact->written);
		$this->assertSame(['❌ USER CREATION FAILED - NO EMAIL', 'Failed to process contactpersoon object: Failed to create user account'], $this->messages('error'));
	}//end testAContactThatGetsNoAccountFailsAndTouchesNoUser()

	public function testTheEventHandlersLogAFailedContactInsteadOfThrowing(): void {
		$this->users->expects($this->never())->method('createUser');
		$contact = self::contact(['voornaam' => 'Anna']);
		$handler = $this->handler();

		$handler->handleNewContact($contact);
		$handler->handleContactUpdate($contact);

		$created = $this->logged('error', 'Failed to handle new contact: Failed to create user account');
		$updated = $this->logged('error', 'Failed to handle contact update: Failed to create user account');
		$this->assertCount(1, $created);
		$this->assertCount(1, $updated);
		$this->assertSame(21, $created[0]['objectId']);
		$this->assertSame(21, $updated[0]['objectId']);
		$this->assertSame('Failed to create user account', $updated[0]['exception']->getMessage());
		$this->assertNull($contact->written);
	}//end testTheEventHandlersLogAFailedContactInsteadOfThrowing()

	public function testMembershipIsRefusedWhenTheObjectServiceCannotBeResolved(): void {
		$this->installed             = ['openregister'];
		$this->objectServiceResolves = false;
		$contact = self::contact(['username' => 'anna', 'organisation' => 'org-1']);
		$handler = $this->handler();

		$this->assertFalse($handler->shouldAddContactpersoonToOrganization($contact));
		$this->assertFalse($handler->addContactpersoonToOrganization($contact));

		$this->assertSame(['ContactPersonHandler: OpenRegister ObjectService not available'], $this->messages('error'));
		$this->assertSame([], $this->logged('info', 'ContactPersonHandler: Contactpersoon should be added to organization'));
	}//end testMembershipIsRefusedWhenTheObjectServiceCannotBeResolved()

	public function testAFailureWhileEnsuringMembershipIsLoggedNotThrown(): void {
		$this->installed      = ['openregister'];
		$this->failingMessage = 'ContactPersonHandler: Ensuring contactpersoon is in organization';
		$this->objects->expects($this->never())->method('find');
		$this->objects->expects($this->never())->method('saveObject');

		$this->handler()->ensureContactpersoonInOrganization(self::contact(['username' => 'anna', 'organisation' => 'org-1']));

		$errors = $this->logged('error', 'ContactPersonHandler: Failed to ensure contactpersoon in organization: log sink full');
		$this->assertCount(1, $errors);
		$this->assertSame(21, $errors[0]['objectId']);
		$this->assertSame('log sink full', $errors[0]['exception']);
		$this->assertSame([], $this->logged('info', 'ContactPersonHandler: Successfully ensured contactpersoon in organization'));
	}//end testAFailureWhileEnsuringMembershipIsLoggedNotThrown()
}//end class
