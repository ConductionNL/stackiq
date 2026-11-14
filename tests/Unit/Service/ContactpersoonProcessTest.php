<?php

/**
 * Unit tests for turning a contactpersoon object into a Nextcloud account.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\ContactpersoonService;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCA\Stackiq\Service\Stackiq\GroupHandler;
use OCA\Stackiq\Service\Stackiq\HierarchyHandler;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A contactpersoon with a usable e-mail address gets an account only when its
 * organisation is active; an existing account has its groups refreshed, the
 * organisation's beheerder ensured and its username written back.
 */
#[CoversClass(ContactpersoonService::class)]
class ContactpersoonProcessTest extends TestCase {

	/**
	 * The parameter names of ObjectServiceInterface::saveObject(), in order.
	 */
	private const SAVE_PARAMETERS = ['object', 'extend', 'register', 'schema', 'uuid', '_rbac', '_multitenancy', 'silent', '_validation', 'uploadedFiles', 'currentUser', 'failIfExists'];

	/**
	 * Every log line, as [level, message, context].
	 *
	 * @var array<int,array{0:string,1:string,2:array<string,mixed>}>
	 */
	private array $logs = [];

	/**
	 * The accounts that exist, by uid.
	 *
	 * @var array<string,IUser>
	 */
	private array $users = [];

	/**
	 * Every saveObject() call, by parameter name.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saves = [];

	/**
	 * Services the container serves besides the user manager and object service.
	 *
	 * @var array<string,mixed>
	 */
	private array $services = [];

	/**
	 * The contact person handler.
	 *
	 * @var ContactPersonHandler&MockObject
	 */
	private ContactPersonHandler&MockObject $handler;

	/**
	 * The hierarchy handler.
	 *
	 * @var HierarchyHandler&MockObject
	 */
	private HierarchyHandler&MockObject $hierarchy;

	/**
	 * The register configuration.
	 *
	 * @var RegisterConfigReaderInterface&MockObject
	 */
	private RegisterConfigReaderInterface&MockObject $settings;

	/**
	 * The object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->handler       = $this->createMock(ContactPersonHandler::class);
		$this->hierarchy     = $this->createMock(HierarchyHandler::class);
		$this->settings      = $this->createMock(RegisterConfigReaderInterface::class);
		$this->settings->method('getVoorzieningenConfig')->willReturn(['register' => 'voorzieningen', 'contactpersoon_schema' => 'contactpersoon', 'organisatie_schema' => 'organisatie']);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (...$args): ObjectEntityInterface {
				$this->saves[] = array_combine(self::SAVE_PARAMETERS, array_pad($args, count(self::SAVE_PARAMETERS), null));
				return $this->createMock(ObjectEntityInterface::class);
			}
		);
		$this->services[RegisterConfigReaderInterface::class] = $this->settings;
	}//end setUp()

	/**
	 * Without an e-mail address, or with an invalid one, nothing is processed.
	 *
	 * @return void
	 */
	public function testAnUnusableEmailIsSkipped(): void {
		$this->handler->expects($this->never())->method('createUserAccount');
		$service = $this->service();

		$this->assertFalse($service->processContactpersoon(contactPersonObject: $this->contact(id: 1, data: [])));
		$this->assertFalse($service->processContactpersoon(contactPersonObject: $this->contact(id: 2, data: ['e-mailadres' => 'not-an-address'])));
		$this->assertSame(
			['ContactpersoonService: Contactpersoon has no email, skipping processing', 'ContactpersoonService: Contactpersoon has invalid email, skipping user creation'],
			$this->messages(level: 'warning')
		);
	}//end testAnUnusableEmailIsSkipped()

	/**
	 * An existing account gets its groups and beheerder refreshed and its username written back silently.
	 *
	 * @return void
	 */
	public function testAnExistingAccountIsRefreshedAndItsUsernameWrittenBack(): void {
		$user    = $this->addUser(uid: 'jan@example.org');
		$contact = $this->contact(id: 3, data: ['e-mailadres' => 'jan@example.org', 'voornaam' => 'Jan']);
		$this->handler->expects($this->never())->method('createUserAccount');
		$this->handler->expects($this->once())->method('updateUserGroupsFromContactData')->with($user, ['e-mailadres' => 'jan@example.org', 'voornaam' => 'Jan']);
		$this->hierarchy->expects($this->once())->method('ensureOrganizationBeheerder')->with($contact, 'jan@example.org');

		$this->assertTrue($this->service()->processContactpersoon(contactPersonObject: $contact, isUpdate: true));

		$this->assertSame(['e-mailadres' => 'jan@example.org', 'voornaam' => 'Jan', 'username' => 'jan@example.org'], $contact->getObject());
		$this->assertCount(1, $this->saves);
		$this->assertSame($contact->getObject(), $this->saves[0]['object']);
		$this->assertSame(['reg', 'schema-cp', 'uuid-3', true, false], [$this->saves[0]['register'], $this->saves[0]['schema'], $this->saves[0]['uuid'], $this->saves[0]['silent'], $this->saves[0]['_validation']]);
	}//end testAnExistingAccountIsRefreshedAndItsUsernameWrittenBack()

	/**
	 * A contact of an active organisation gets an account, is linked to the organisation and owns its
	 * object. The method then falls through to the "no organization reference" branch and answers false,
	 * so groups and beheerder are left to the next run.
	 *
	 * @return void
	 */
	public function testAContactOfAnActiveOrganisationGetsAnAccount(): void {
		$this->services['OCA\OpenRegister\Db\OrganisationMapper'] = $this->mapper(entity: $this->organisation(active: true));
		$contact = $this->contact(id: 4, data: ['e-mailadres' => 'piet@example.org', 'organisation' => 'org-1', 'username' => 'piet@example.org', 'telefoonnummer' => 612345678]);
		$this->handler->expects($this->once())->method('isFirstContactForOrganization')->willReturn(true);
		$this->handler->expects($this->once())->method('createUserAccount')->with($contact, true)->willReturnCallback(
			fn (): IUser => $this->addUser(uid: 'piet@example.org')
		);
		$this->handler->expects($this->once())->method('addUserToOrganizationEntity')->with($contact, 'piet@example.org', 'org-1');
		$this->handler->expects($this->never())->method('updateUserGroupsFromContactData');

		$this->assertFalse($this->service()->processContactpersoon(contactPersonObject: $contact));

		$this->assertSame('piet@example.org', $contact->owner);
		$this->assertSame('org-1', $contact->organisation);
		$this->assertSame(['owner' => 'piet@example.org', 'organisation' => 'org-1'], $contact->getObject()['@self']);
		$this->assertSame('612345678', $contact->getObject()['telefoonnummer']);
		$this->assertCount(1, $this->saves);
		$this->assertSame(['organisation' => 'org-1'], $this->saves[0]['object']['@self']);
		$this->assertSame($this->users['piet@example.org'], $this->saves[0]['currentUser']);
		$this->assertTrue($this->saves[0]['silent']);
		$this->assertFalse($this->saves[0]['_validation']);
		$this->assertContains('ContactpersoonService: Successfully created user account', $this->messages(level: 'info'));
		$this->assertSame(['ContactpersoonService: Contactpersoon has no organization reference, skipping user creation'], $this->messages(level: 'warning'));
	}//end testAContactOfAnActiveOrganisationGetsAnAccount()

	/**
	 * A contact of an inactive organisation gets no account.
	 *
	 * @return void
	 */
	public function testAnInactiveOrganisationGetsNoAccount(): void {
		$this->services['OCA\OpenRegister\Db\OrganisationMapper'] = $this->mapper(entity: $this->organisation(active: false));
		$this->handler->expects($this->never())->method('createUserAccount');

		$this->assertFalse($this->service()->processContactpersoon(contactPersonObject: $this->contact(id: 5, data: ['email' => 'a@example.org', 'organization' => 'org-2'])));

		$skipped = $this->context(message: 'ContactpersoonService: Skipping user creation - organization not active or entity not found');
		$this->assertSame(['contactId' => 5, 'organizationUuid' => 'org-2', 'organizationFound' => true, 'organizationActive' => false], $skipped);
	}//end testAnInactiveOrganisationGetsNoAccount()

	/**
	 * A failure while linking the new account is logged and reported as not processed.
	 *
	 * @return void
	 */
	public function testAFailingLinkIsNotProcessed(): void {
		$this->services['OCA\OpenRegister\Db\OrganisationMapper'] = $this->mapper(entity: $this->organisation(active: true));
		$this->handler->method('createUserAccount')->willReturnCallback(fn (): IUser => $this->addUser(uid: 'b@example.org'));
		$this->handler->method('addUserToOrganizationEntity')->willThrowException(new RuntimeException('link failed'));

		$this->assertFalse($this->service()->processContactpersoon(contactPersonObject: $this->contact(id: 6, data: ['email' => 'b@example.org', 'organisation' => 'org-1'])));

		$this->assertSame(['contactId' => 6, 'organizationUuid' => 'org-1', 'error' => 'link failed'], $this->context(message: 'ContactpersoonService: User creation failed'));
		$this->assertSame([], $this->saves);
	}//end testAFailingLinkIsNotProcessed()

	/**
	 * A new contact without an organisation reference gets no account; the group update finds no user
	 * and only the beheerder check and the username write-back run.
	 *
	 * @return void
	 */
	public function testAContactWithoutAnOrganisationGetsNoAccount(): void {
		$contact = $this->contact(id: 7, data: ['email' => 'c@example.org']);
		$this->handler->expects($this->never())->method('createUserAccount');
		$this->handler->expects($this->never())->method('updateUserGroupsFromContactData');
		$this->hierarchy->expects($this->once())->method('ensureOrganizationBeheerder')->with($contact, 'c@example.org');

		$this->assertTrue($this->service()->processContactpersoon(contactPersonObject: $contact));

		$this->assertSame(['User not found for group update'], $this->messages(level: 'warning'));
		$this->assertSame('c@example.org', $contact->getObject()['username']);
	}//end testAContactWithoutAnOrganisationGetsNoAccount()

	/**
	 * A missing organisation entity is rebuilt from an active organisation object before the account is made.
	 *
	 * @return void
	 */
	public function testAMissingOrganisationEntityIsRebuiltFromAnActiveObject(): void {
		$this->services['OCA\OpenRegister\Db\OrganisationMapper'] = $this->mapper(entity: null);
		$orgObject = $this->createMock(ObjectEntityInterface::class);
		$orgObject->method('getObject')->willReturn(['status' => 'Actief']);
		$this->objectService->expects($this->once())->method('find')
			->with('org-3', [], false, 'voorzieningen', 'organisatie', false, false)
			->willReturn($orgObject);
		$sync = new class($this->organisation(active: true)) {
			/**
			 * The organisation objects it was asked about.
			 *
			 * @var array<int,object>
			 */
			public array $asked = [];

			/**
			 * Build the fake sync service.
			 *
			 * @param object $entity The entity it answers with.
			 */
			public function __construct(private readonly object $entity) {
			}

			/**
			 * Answer the entity.
			 *
			 * @param object              $organisationObject The organisation object.
			 * @param array<string,mixed> $stats              The stats.
			 *
			 * @return object
			 */
			public function ensureOrganisationEntityPublic(object $organisationObject, array &$stats): object {
				$this->asked[] = $organisationObject;
				return $this->entity;
			}
		};
		$this->services['OCA\Stackiq\Service\OrganizationSyncService'] = $sync;
		$this->handler->expects($this->once())->method('createUserAccount')->willReturnCallback(fn (): IUser => $this->addUser(uid: 'd@example.org'));

		$this->assertFalse($this->service()->processContactpersoon(contactPersonObject: $this->contact(id: 8, data: ['email' => 'd@example.org', 'organisation' => 'org-3'])));

		$this->assertSame([$orgObject], $sync->asked);
		$this->assertSame(['contactId' => 8, 'organizationUuid' => 'org-3', 'entityCreated' => true], $this->context(message: 'ContactpersoonService: Backup entity created'));
	}//end testAMissingOrganisationEntityIsRebuiltFromAnActiveObject()

	/**
	 * A failing rebuild is logged and the contact gets no account.
	 *
	 * @return void
	 */
	public function testAFailingRebuildGivesNoAccount(): void {
		$this->services['OCA\OpenRegister\Db\OrganisationMapper'] = $this->mapper(entity: null);
		$this->objectService->method('find')->willThrowException(new RuntimeException('register offline'));
		$this->handler->expects($this->never())->method('createUserAccount');

		$this->assertFalse($this->service()->processContactpersoon(contactPersonObject: $this->contact(id: 9, data: ['email' => 'e@example.org', 'organisation' => 'org-4'])));

		$this->assertSame(['contactId' => 9, 'organizationUuid' => 'org-4', 'error' => 'register offline'], $this->context(message: 'ContactpersoonService: Backup entity creation failed'));
		$this->assertSame(false, $this->context(message: 'ContactpersoonService: Skipping user creation - organization not active or entity not found')['organizationFound']);
	}//end testAFailingRebuildGivesNoAccount()

	/**
	 * An unexpected failure is logged and rethrown, and the contact can be processed again afterwards.
	 *
	 * @return void
	 */
	public function testAnUnexpectedFailureIsRethrown(): void {
		$this->addUser(uid: 'f@example.org');
		$contact = $this->contact(id: 10, data: ['email' => 'f@example.org', 'username' => 'f@example.org']);
		$this->handler->method('updateUserGroupsFromContactData')->willThrowException(new RuntimeException('groups down'));
		$service = $this->service();

		try {
			$service->processContactpersoon(contactPersonObject: $contact);
			$this->fail('The failure was swallowed');
		} catch (RuntimeException $e) {
			$this->assertSame('groups down', $e->getMessage());
		}

		$this->assertSame('groups down', $this->context(message: 'ContactpersoonService: Failed to process contactpersoon object')['exception']);
		$this->expectException(RuntimeException::class);
		$service->processContactpersoon(contactPersonObject: $contact);
	}//end testAnUnexpectedFailureIsRethrown()

	/**
	 * A contact that is being processed is not processed again from within.
	 *
	 * @return void
	 */
	public function testReentryForTheSameContactIsSkipped(): void {
		$this->services['OCA\OpenRegister\Db\OrganisationMapper'] = $this->mapper(entity: $this->organisation(active: true));
		$contact = $this->contact(id: 11, data: ['email' => 'g@example.org', 'organisation' => 'org-1', 'username' => 'g@example.org']);
		$service = $this->service();
		$nested  = null;
		$this->handler->expects($this->once())->method('createUserAccount')->willReturnCallback(
			function () use ($service, $contact, &$nested): IUser {
				$nested = $service->processContactpersoon(contactPersonObject: $contact);
				return $this->addUser(uid: 'g@example.org');
			}
		);

		$this->assertFalse($service->processContactpersoon(contactPersonObject: $contact));
		$this->assertTrue($nested);
	}//end testReentryForTheSameContactIsSkipped()

	/**
	 * The service under test.
	 *
	 * @return ContactpersoonService
	 */
	private function service(): ContactpersoonService {
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(fn (string $uid): ?IUser => ($this->users[$uid] ?? null));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): mixed => match ($id) {
				'OCP\IUserManager' => $userManager,
				'OCA\OpenRegister\Service\ObjectService' => $this->objectService,
				default => $this->services[$id] ?? throw new RuntimeException('no '.$id),
			}
		);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturnCallback(static fn (string $app): bool => $app === 'openregister');
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		return new ContactpersoonService(
			contactPersonHandler: $this->handler,
			groupHandler: $this->createMock(GroupHandler::class),
			hierarchyHandler: $this->hierarchy,
			logger: $logger,
			container: $container,
			appManager: $appManager,
			config: $this->createMock(IAppConfig::class),
			settingsService: $this->settings
		);
	}//end service()

	/**
	 * Register an account.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser
	 */
	private function addUser(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->users[$uid] = $user;
		return $user;
	}//end addUser()

	/**
	 * A contactpersoon object.
	 *
	 * @param int                 $id   The id.
	 * @param array<string,mixed> $data The object data.
	 *
	 * @return object
	 */
	private function contact(int $id, array $data): object {
		return new class($id, $data) {
			/**
			 * The owner set on the entity.
			 *
			 * @var string|null
			 */
			public ?string $owner = null;

			/**
			 * The organisation set on the entity.
			 *
			 * @var string|null
			 */
			public ?string $organisation = null;

			/**
			 * Build the contact.
			 *
			 * @param int                 $id   The id.
			 * @param array<string,mixed> $data The data.
			 */
			public function __construct(private readonly int $id, private array $data) {
			}

			/**
			 * @return int
			 */
			public function getId(): int {
				return $this->id;
			}

			/**
			 * @return string
			 */
			public function getUuid(): string {
				return 'uuid-'.$this->id;
			}

			/**
			 * @return string
			 */
			public function getRegister(): string {
				return 'reg';
			}

			/**
			 * @return string
			 */
			public function getSchema(): string {
				return 'schema-cp';
			}

			/**
			 * @return array<string,mixed>
			 */
			public function getObject(): array {
				return $this->data;
			}

			/**
			 * @param array<string,mixed> $data The data.
			 *
			 * @return void
			 */
			public function setObject(array $data): void {
				$this->data = $data;
			}

			/**
			 * @param string $owner The owner.
			 *
			 * @return void
			 */
			public function setOwner(string $owner): void {
				$this->owner = $owner;
			}

			/**
			 * @param string $organisation The organisation.
			 *
			 * @return void
			 */
			public function setOrganisation(string $organisation): void {
				$this->organisation = $organisation;
			}
		};
	}//end contact()

	/**
	 * An organisation entity.
	 *
	 * @param bool $active Whether it is active.
	 *
	 * @return object
	 */
	private function organisation(bool $active): object {
		return new class($active) {
			/**
			 * @param bool $active Whether it is active.
			 */
			public function __construct(private readonly bool $active) {
			}

			/**
			 * @return bool
			 */
			public function getActive(): bool {
				return $this->active;
			}
		};
	}//end organisation()

	/**
	 * An organisation mapper finding the given entity, or none.
	 *
	 * @param object|null $entity The entity.
	 *
	 * @return object
	 */
	private function mapper(?object $entity): object {
		return new class($entity) {
			/**
			 * @param object|null $entity The entity.
			 */
			public function __construct(private readonly ?object $entity) {
			}

			/**
			 * @param string $uuid The uuid.
			 *
			 * @return object
			 */
			public function findByUuid(string $uuid): object {
				return $this->entity ?? throw new DoesNotExistException('no '.$uuid);
			}
		};
	}//end mapper()

	/**
	 * The messages logged at a level.
	 *
	 * @param string $level The level.
	 *
	 * @return array<int,string>
	 */
	private function messages(string $level): array {
		return array_values(array_map(static fn (array $log): string => $log[1], array_filter($this->logs, static fn (array $log): bool => $log[0] === $level)));
	}//end messages()

	/**
	 * The context of the first log line with this message.
	 *
	 * @param string $message The message.
	 *
	 * @return array<string,mixed>
	 */
	private function context(string $message): array {
		foreach ($this->logs as $log) {
			if ($log[1] === $message) {
				return $log[2];
			}
		}

		$this->fail('Not logged: '.$message);
	}//end context()
}//end class
