<?php

/**
 * Unit tests for the organisation-membership paths of ContactPersonHandler.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Stackiq
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Contact person handler internals; behaviour pinned here.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Stackiq;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCA\Stackiq\Service\SymfonyEmailService;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Adding a contact person's user to its organisation, the maintainer role and
 * the contact-event entry points that end in that membership check.
 *
 * @covers \OCA\Stackiq\Service\Stackiq\ContactPersonHandler
 *
 * @spec exclude Contact person handler internals; behaviour pinned here.
 */
class ContactPersonHandlerOrganisationMembershipTest extends TestCase {

	private const ORG = 'org-uuid-1';

	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $appManager;

	/**
	 * @var IUserManager&MockObject
	 */
	private IUserManager&MockObject $userManager;

	/**
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The handler under test.
	 *
	 * @var ContactPersonHandler
	 */
	private ContactPersonHandler $handler;

	/**
	 * Build the boundary doubles and the handler.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->settings      = $this->createMock(originalClassName: SettingsService::class);
		$this->appManager    = $this->createMock(originalClassName: IAppManager::class);
		$this->userManager   = $this->createMock(originalClassName: IUserManager::class);
		$this->groupManager  = $this->createMock(originalClassName: IGroupManager::class);
		$this->logger        = $this->createMock(originalClassName: LoggerInterface::class);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => match ($id) {
				RegisterConfigReaderInterface::class => $this->settings,
				'OCA\OpenRegister\Service\ObjectService' => $this->objectService,
			}
		);

		$this->handler = new ContactPersonHandler(
			_userManager: $this->userManager,
			_secureRandom: $this->createMock(originalClassName: ISecureRandom::class),
			_groupManager: $this->groupManager,
			_config: $this->createMock(originalClassName: IAppConfig::class),
			_container: $container,
			_appManager: $this->appManager,
			_logger: $this->logger,
			_emailService: $this->createMock(originalClassName: SymfonyEmailService::class),
			config: $this->createMock(originalClassName: IConfig::class)
		);
	}//end setUp()

	/**
	 * OpenRegister installed with the organisation register 4 / schema 5.
	 *
	 * @return void
	 */
	private function configured(): void {
		$this->appManager->method('getInstalledApps')->willReturn(['openregister', 'stackiq']);
		$this->settings->method('getVoorzieningenRegisterId')->willReturn(4);
		$this->settings->method('getSchemaIdForObjectType')->with('organization')->willReturn(5);
	}//end configured()

	/**
	 * An organisation object holding the given data.
	 *
	 * @param array<string,mixed> $data The organisation data.
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function organisation(array $data): ObjectEntity&MockObject {
		$organisation = $this->createMock(originalClassName: ObjectEntity::class);
		$organisation->method('getObject')->willReturn($data);
		return $organisation;
	}//end organisation()

	/**
	 * A contact person object holding the given data.
	 *
	 * @param array<string,mixed> $data The contact data.
	 *
	 * @return FakeContactPersonObject
	 */
	private function contact(array $data): FakeContactPersonObject {
		return new FakeContactPersonObject(data: $data);
	}//end contact()

	/**
	 * A contact without a username or organisation needs no membership.
	 *
	 * @return void
	 */
	public function testShouldAddNeedsUsernameAndOrganisation(): void {
		$this->objectService->expects($this->never())->method('find');

		$noUsername     = $this->contact(data: ['organisation' => self::ORG]);
		$noOrganisation = $this->contact(data: ['username' => 'jan']);

		$this->assertFalse(condition: $this->handler->shouldAddContactpersoonToOrganization(contactPersonObject: $noUsername));
		$this->assertFalse(condition: $this->handler->shouldAddContactpersoonToOrganization(contactPersonObject: $noOrganisation));
	}//end testShouldAddNeedsUsernameAndOrganisation()

	/**
	 * Without OpenRegister the check fails closed and logs the error.
	 *
	 * @return void
	 */
	public function testShouldAddFailsClosedWithoutOpenRegister(): void {
		$this->appManager->method('getInstalledApps')->willReturn(['stackiq']);
		$this->logger->expects($this->once())->method('error')->with(
			'ContactPersonHandler: Failed to check if contactpersoon should be added to organization: OpenRegister service is not available.',
			['objectId' => 11, 'exception' => 'OpenRegister service is not available.']
		);

		$this->assertFalse(
			condition: $this->handler->shouldAddContactpersoonToOrganization(
				contactPersonObject: $this->contact(data: ['username' => 'jan', 'organisation' => self::ORG])
			)
		);
	}//end testShouldAddFailsClosedWithoutOpenRegister()

	/**
	 * Without a configured register the check answers false.
	 *
	 * @return void
	 */
	public function testShouldAddNeedsAConfiguredRegister(): void {
		$this->appManager->method('getInstalledApps')->willReturn(['openregister']);
		$this->settings->method('getVoorzieningenRegisterId')->willReturn(null);
		$this->objectService->expects($this->never())->method('find');

		$this->assertFalse(
			condition: $this->handler->shouldAddContactpersoonToOrganization(
				contactPersonObject: $this->contact(data: ['username' => 'jan', 'organisation' => self::ORG])
			)
		);
	}//end testShouldAddNeedsAConfiguredRegister()

	/**
	 * The user is to be added exactly when the organisation does not list them yet.
	 *
	 * @return void
	 */
	public function testShouldAddDependsOnTheOrganisationUsers(): void {
		$this->configured();
		$this->objectService->expects($this->exactly(count: 3))->method('find')
			->with(self::ORG, [], false, 4, 5)
			->willReturnOnConsecutiveCalls(
				$this->organisation(data: ['users' => ['piet']]),
				$this->organisation(data: ['users' => ['piet', 'jan']]),
				$this->organisation(data: ['users' => 'jan'])
			);
		$contact = $this->contact(data: ['username' => 'jan', 'organisation' => self::ORG]);

		$this->assertTrue(condition: $this->handler->shouldAddContactpersoonToOrganization(contactPersonObject: $contact));
		$this->assertFalse(condition: $this->handler->shouldAddContactpersoonToOrganization(contactPersonObject: $contact));
		$this->assertFalse(condition: $this->handler->shouldAddContactpersoonToOrganization(contactPersonObject: $contact));
	}//end testShouldAddDependsOnTheOrganisationUsers()

	/**
	 * A missing organisation answers false with a warning.
	 *
	 * @return void
	 */
	public function testShouldAddWithAMissingOrganisation(): void {
		$this->configured();
		$this->objectService->method('find')->willThrowException(new DoesNotExistException(msg: 'gone'));
		$this->logger->expects($this->once())->method('warning')->with(
			'ContactPersonHandler: Organization not found for contactpersoon',
			['username' => 'jan', 'organizationUuid' => self::ORG]
		);

		$this->assertFalse(
			condition: $this->handler->shouldAddContactpersoonToOrganization(
				contactPersonObject: $this->contact(data: ['username' => 'jan', 'organisation' => self::ORG])
			)
		);
	}//end testShouldAddWithAMissingOrganisation()

	/**
	 * Adding refuses without a username or organisation.
	 *
	 * @return void
	 */
	public function testAddRefusesWithoutUsernameOrOrganisation(): void {
		$this->logger->expects($this->once())->method('warning')->with(
			'ContactPersonHandler: Cannot add contactpersoon to organization - missing username or organization',
			['username' => '', 'organizationUuid' => self::ORG]
		);
		$this->objectService->expects($this->never())->method('saveObject');

		$contact = $this->contact(data: ['organisation' => self::ORG]);

		$this->assertFalse(condition: $this->handler->addContactpersoonToOrganization(contactPersonObject: $contact));
	}//end testAddRefusesWithoutUsernameOrOrganisation()

	/**
	 * Adding refuses when the register is not configured.
	 *
	 * @return void
	 */
	public function testAddRefusesWithoutAConfiguredRegister(): void {
		$this->appManager->method('getInstalledApps')->willReturn(['openregister']);
		$this->settings->method('getVoorzieningenRegisterId')->willReturn(null);
		$this->logger->expects($this->once())->method('error')->with('ContactPersonHandler: Register or schema not configured for organisatie');

		$this->assertFalse(
			condition: $this->handler->addContactpersoonToOrganization(
				contactPersonObject: $this->contact(data: ['username' => 'jan', 'organisation' => self::ORG])
			)
		);
	}//end testAddRefusesWithoutAConfiguredRegister()

	/**
	 * The username is appended to the organisation users and the organisation saved in place.
	 *
	 * @return void
	 */
	public function testAddAppendsTheUserAndSavesTheOrganisation(): void {
		$this->configured();
		$this->objectService->method('find')->willReturn($this->organisation(data: ['name' => 'Gemeente', 'users' => ['piet']]));
		$this->objectService->expects($this->once())->method('saveObject')
			->with(['name' => 'Gemeente', 'users' => ['piet', 'jan']], [], 4, 5, self::ORG)
			->willReturn($this->organisation(data: []));

		$this->assertTrue(
			condition: $this->handler->addContactpersoonToOrganization(
				contactPersonObject: $this->contact(data: ['username' => 'jan', 'organisation' => self::ORG])
			)
		);
	}//end testAddAppendsTheUserAndSavesTheOrganisation()

	/**
	 * A non-list users value is replaced by a list holding only the new user.
	 *
	 * @return void
	 */
	public function testAddReplacesANonListUsersValue(): void {
		$this->configured();
		$this->objectService->method('find')->willReturn($this->organisation(data: ['users' => 'broken']));
		$this->objectService->expects($this->once())->method('saveObject')
			->with(['users' => ['jan']], [], 4, 5, self::ORG)
			->willReturn($this->organisation(data: []));

		$this->assertTrue(
			condition: $this->handler->addContactpersoonToOrganization(
				contactPersonObject: $this->contact(data: ['username' => 'jan', 'organisation' => self::ORG])
			)
		);
	}//end testAddReplacesANonListUsersValue()

	/**
	 * A user already listed is a success without a save.
	 *
	 * @return void
	 */
	public function testAddIsIdempotentForAListedUser(): void {
		$this->configured();
		$this->objectService->method('find')->willReturn($this->organisation(data: ['users' => ['jan']]));
		$this->objectService->expects($this->never())->method('saveObject');

		$this->assertTrue(
			condition: $this->handler->addContactpersoonToOrganization(
				contactPersonObject: $this->contact(data: ['username' => 'jan', 'organisation' => self::ORG])
			)
		);
	}//end testAddIsIdempotentForAListedUser()

	/**
	 * A missing organisation or a failing save answers false.
	 *
	 * @return void
	 */
	public function testAddFailsOnMissingOrganisationOrFailingSave(): void {
		$this->configured();
		$this->objectService->method('find')->willReturnOnConsecutiveCalls(
			$this->throwException(exception: new DoesNotExistException(msg: 'gone')),
			$this->organisation(data: ['users' => []])
		);
		$this->objectService->method('saveObject')->willThrowException(new \RuntimeException(message: 'locked'));
		$contact = $this->contact(data: ['username' => 'jan', 'organisation' => self::ORG]);

		$this->assertFalse(condition: $this->handler->addContactpersoonToOrganization(contactPersonObject: $contact));
		$this->assertFalse(condition: $this->handler->addContactpersoonToOrganization(contactPersonObject: $contact));
	}//end testAddFailsOnMissingOrganisationOrFailingSave()

	/**
	 * Ensuring membership saves only when the user is not listed yet.
	 *
	 * @return void
	 */
	public function testEnsureAddsOnlyWhenNeeded(): void {
		$this->configured();
		$this->objectService->method('find')->willReturnOnConsecutiveCalls(
			$this->organisation(data: ['users' => ['jan']]),
			$this->organisation(data: ['users' => []]),
			$this->organisation(data: ['users' => []])
		);
		$this->objectService->expects($this->once())->method('saveObject')
			->with(['users' => ['jan']], [], 4, 5, self::ORG)
			->willReturn($this->organisation(data: []));
		$contact = $this->contact(data: ['username' => 'jan', 'organisation' => self::ORG]);

		$this->handler->ensureContactpersoonInOrganization(contactPersonObject: $contact);
		$this->handler->ensureContactpersoonInOrganization(contactPersonObject: $contact);
	}//end testEnsureAddsOnlyWhenNeeded()

	/**
	 * A failed add after a positive check is reported as a warning.
	 *
	 * @return void
	 */
	public function testEnsureWarnsWhenTheAddFails(): void {
		$this->configured();
		$this->objectService->method('find')->willReturn($this->organisation(data: ['users' => []]));
		$this->objectService->method('saveObject')->willThrowException(new \RuntimeException(message: 'locked'));
		$this->logger->expects($this->once())->method('warning')
			->with('ContactPersonHandler: Failed to add contact person to organization', ['objectId' => 11]);

		$this->handler->ensureContactpersoonInOrganization(
			contactPersonObject: $this->contact(data: ['username' => 'jan', 'organisation' => self::ORG])
		);
	}//end testEnsureWarnsWhenTheAddFails()

	/**
	 * The maintainer role is added to the contact and the user joins a newly created maintainer group.
	 *
	 * @return void
	 */
	public function testAssignBeheerderRoleAddsRoleAndGroup(): void {
		$user  = $this->createMock(originalClassName: IUser::class);
		$group = $this->createMock(originalClassName: IGroup::class);
		$group->method('inGroup')->with($user)->willReturn(false);
		$group->expects($this->once())->method('addUser')->with($user);
		$this->groupManager->method('get')->with('maintainer')->willReturn(null);
		$this->groupManager->expects($this->once())->method('createGroup')->with('maintainer')->willReturn($group);
		$this->userManager->method('get')->with('jan')->willReturn($user);
		$contact = $this->contact(data: ['name' => 'Jan', 'roles' => 'not-a-list']);

		$this->handler->assignBeheerderRole(contactPersonObject: $contact, username: 'jan', organizationUuid: self::ORG);

		$this->assertSame(expected: ['name' => 'Jan', 'roles' => ['maintainer']], actual: $contact->getObject());
	}//end testAssignBeheerderRoleAddsRoleAndGroup()

	/**
	 * A contact that already has the maintainer role (any case) is left alone.
	 *
	 * @return void
	 */
	public function testAssignBeheerderRoleIsIdempotent(): void {
		$this->groupManager->expects($this->never())->method('get');
		$contact = $this->contact(data: ['roles' => ['Maintainer']]);

		$this->handler->assignBeheerderRole(contactPersonObject: $contact, username: 'jan', organizationUuid: self::ORG);

		$this->assertSame(expected: ['roles' => ['Maintainer']], actual: $contact->getObject());
		$this->assertSame(expected: 0, actual: $contact->sets);
	}//end testAssignBeheerderRoleIsIdempotent()

	/**
	 * An existing member of the maintainer group is not added again.
	 *
	 * @return void
	 */
	public function testAssignBeheerderRoleSkipsAnExistingMember(): void {
		$user  = $this->createMock(originalClassName: IUser::class);
		$group = $this->createMock(originalClassName: IGroup::class);
		$group->method('inGroup')->willReturn(true);
		$group->expects($this->never())->method('addUser');
		$this->groupManager->method('get')->willReturn($group);
		$this->groupManager->expects($this->never())->method('createGroup');
		$this->userManager->method('get')->willReturn($user);
		$contact = $this->contact(data: ['roles' => ['viewer']]);

		$this->handler->assignBeheerderRole(contactPersonObject: $contact, username: 'jan', organizationUuid: self::ORG);

		$this->assertSame(expected: ['roles' => ['viewer', 'maintainer']], actual: $contact->getObject());
	}//end testAssignBeheerderRoleSkipsAnExistingMember()

	/**
	 * The first-contact check currently always answers true.
	 *
	 * @return void
	 */
	public function testIsFirstContactDefaultsToTrue(): void {
		$this->assertTrue(condition: $this->handler->isFirstContactForOrganization(contactObject: $this->contact(data: []), objectData: []));
	}//end testIsFirstContactDefaultsToTrue()

	/**
	 * A contact that already has a username only gets its membership ensured.
	 *
	 * @return void
	 */
	public function testProcessingAContactWithUsernameEnsuresMembership(): void {
		$this->configured();
		$this->objectService->method('find')->willReturn($this->organisation(data: ['users' => []]));
		$this->objectService->expects($this->exactly(count: 3))->method('saveObject')
			->with(['users' => ['jan']], [], 4, 5, self::ORG)
			->willReturn($this->organisation(data: []));
		$this->userManager->expects($this->never())->method('createUser');
		$contact = $this->contact(data: ['username' => 'jan', 'organisation' => self::ORG]);

		$this->assertTrue(condition: $this->handler->processContactpersoon(contactPersonObject: $contact));
		$this->handler->handleNewContact(contactObject: $contact);
		$this->handler->handleContactUpdate(contactObject: $contact);
		$this->assertSame(expected: 0, actual: $contact->sets);
	}//end testProcessingAContactWithUsernameEnsuresMembership()

	/**
	 * On update a contact without a username is linked to an existing user
	 * derived from its email instead of creating a new account.
	 *
	 * @return void
	 */
	public function testUpdateLinksAnExistingUserByGeneratedUsername(): void {
		$this->appManager->method('getInstalledApps')->willReturn(['stackiq']);
		$this->userManager->method('get')->with('jan@gemeente.nl')->willReturn($this->createMock(originalClassName: IUser::class));
		$this->userManager->expects($this->never())->method('createUser');
		$contact = $this->contact(data: ['email' => 'Jan+test@Gemeente.nl', 'organisation' => self::ORG]);

		$this->assertTrue(condition: $this->handler->processContactpersoon(contactPersonObject: $contact, isUpdate: true));

		$this->assertSame(
			expected: ['email' => 'Jan+test@Gemeente.nl', 'organisation' => self::ORG, 'username' => 'jan@gemeente.nl'],
			actual: $contact->getObject()
		);
	}//end testUpdateLinksAnExistingUserByGeneratedUsername()

	/**
	 * A contact update without role or organisation change does not touch groups.
	 *
	 * @return void
	 */
	public function testContactpersoonUpdateWithoutChangesKeepsGroups(): void {
		$this->appManager->method('getInstalledApps')->willReturn(['stackiq']);
		$this->userManager->expects($this->never())->method('get');
		$this->groupManager->expects($this->never())->method('get');
		$data = ['username' => 'jan', 'organisation' => self::ORG, 'roles' => 'viewer'];

		$this->handler->handleContactpersoonUpdate(
			contactPersonObject: $this->contact(data: $data),
			oldContactPersonObject: $this->contact(data: $data)
		);
	}//end testContactpersoonUpdateWithoutChangesKeepsGroups()

	/**
	 * Username candidates are derived from the email first, then from the name.
	 *
	 * @return void
	 */
	public function testUsernameGenerationStrategies(): void {
		$this->assertSame(
			expected: 'a.b@x.nl',
			actual: $this->handler->generateUsernameFromContactData(contactData: ['e-mailadres' => 'A.B+tag@X.nl'])
		);
		$this->assertSame(
			expected: 'jan.jansen',
			actual: $this->handler->generateUsernameFromContactData(contactData: ['voornaam' => 'Jan', 'achternaam' => 'Jansen', 'email' => 'no-at-sign'])
		);
		$this->assertMatchesRegularExpression(
			pattern: '/^user\d+$/',
			string: $this->handler->generateUsernameFromContactData(contactData: [])
		);
	}//end testUsernameGenerationStrategies()

	/**
	 * Email validation names the exact problem, and accepts a usable address.
	 *
	 * @return void
	 */
	public function testValidateEmailForUsernameMessages(): void {
		$this->assertSame(expected: 'No email address found on the contact person.', actual: $this->handler->validateEmailForUsername(email: ''));
		$this->assertSame(
			expected: 'The email address "jan" is not a valid email address (missing @).',
			actual: $this->handler->validateEmailForUsername(email: 'jan')
		);
		$this->assertSame(
			expected: 'The email address "@" is too short to be used as a username (minimum 3 characters).',
			actual: $this->handler->validateEmailForUsername(email: '@')
		);
		$long = str_repeat(string: 'a', times: 60).'@example.nl';
		$this->assertSame(
			expected: 'The email address "'.$long.'" is too long to be used as a username (maximum 64 characters).',
			actual: $this->handler->validateEmailForUsername(email: $long)
		);
		$this->assertNull(actual: $this->handler->validateEmailForUsername(email: 'Jan.Jansen+x@Gemeente.nl'));
	}//end testValidateEmailForUsernameMessages()
}//end class

/**
 * Minimal contact person object: the id/uuid/object accessors the handler reads.
 */
class FakeContactPersonObject {
	/**
	 * Number of setObject() calls.
	 *
	 * @var integer
	 */
	public int $sets = 0;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $data The object data.
	 */
	public function __construct(private array $data) {
	}

	/**
	 * The database id.
	 *
	 * @return integer
	 */
	public function getId(): int {
		return 11;
	}

	/**
	 * The uuid.
	 *
	 * @return string
	 */
	public function getUuid(): string {
		return 'contact-uuid-11';
	}

	/**
	 * The object data.
	 *
	 * @return array<string,mixed>
	 */
	public function getObject(): array {
		return $this->data;
	}

	/**
	 * Replace the object data.
	 *
	 * @param array<string,mixed> $data The new data.
	 *
	 * @return void
	 */
	public function setObject(array $data): void {
		$this->data = $data;
		$this->sets++;
	}
}//end class
