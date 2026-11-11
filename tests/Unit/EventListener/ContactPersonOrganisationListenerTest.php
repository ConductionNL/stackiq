<?php

/**
 * Tests for ContactPersonOrganisationListener and ContactPersonOrganisationGuard.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\EventListener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Stackiq\EventListener\ContactPersonOrganisationListener;
use OCA\Stackiq\Service\Contactpersoon\ContactPersonOrganisationGuard;
use OCA\Stackiq\Service\Contactpersoon\SystemContactWrites;
use OCA\Stackiq\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * REQ-003: a contact person only from an administrator, or from a member of its
 * organisation who may add members; an existing account only when it is already a member.
 *
 * @covers \OCA\Stackiq\EventListener\ContactPersonOrganisationListener
 * @covers \OCA\Stackiq\Service\Contactpersoon\ContactPersonOrganisationGuard
 * @covers \OCA\Stackiq\Service\Contactpersoon\SystemContactWrites
 */
final class ContactPersonOrganisationListenerTest extends TestCase {
	private const SCHEMA = '41';

	private const ORG_X = '6b0c1f8e-0000-4000-8000-00000000000x';

	private const ORG_A = '6b0c1f8e-0000-4000-8000-00000000000a';

	/**
	 * Organisation uuid => member uids.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $organisations = [
		self::ORG_X => ['beheer-x', 'raadpleger-x', 'victim-x'],
		self::ORG_A => ['beheer-a', 'victim-a'],
	];

	/**
	 * Uid => groups.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $groups = [
		'beheer-x'     => ['gebruik-beheerder'],
		'raadpleger-x' => ['gebruik-raadpleger'],
		'beheer-a'     => ['aanbod-beheerder'],
		'outsider'     => ['software-catalog-users'],
		'root'         => ['admin'],
		'victim-x'     => ['gebruik-raadpleger'],
		'victim-a'     => ['gebruik-beheerder'],
	];

	/**
	 * Organisatie object uuid => status, for organisaties without an OpenRegister organisation.
	 *
	 * @var array<string, string>
	 */
	private array $organisaties = [
		'not-yet-an-organisation' => 'pending',
		'active-without-entity'   => 'Actief',
	];

	/**
	 * Whether reading an organisatie object fails.
	 */
	private bool $organisatieLookupFails = false;

	public function testASignedInUserOutsideTheOrganisationCannotAddAnAccountToIt(): void {
		$event = $this->creating(data: ['organisation' => self::ORG_X, 'email' => 'new@example.org']);

		$this->listener(caller: 'outsider')->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(['message' => 'This contact person could not be saved.'], $event->getErrors());
	}//end testASignedInUserOutsideTheOrganisationCannotAddAnAccountToIt()

	public function testAMemberManagerOfAnotherOrganisationCannotAddAnAccountToIt(): void {
		$event = $this->creating(data: ['organization' => self::ORG_X, 'e-mailadres' => 'new@example.org']);

		$this->listener(caller: 'beheer-a')->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testAMemberManagerOfAnotherOrganisationCannotAddAnAccountToIt()

	public function testAMemberWithoutTheRightToAddMembersIsRefused(): void {
		$event = $this->creating(data: ['organisation' => self::ORG_X, 'email' => 'new@example.org']);

		$this->listener(caller: 'raadpleger-x')->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testAMemberWithoutTheRightToAddMembersIsRefused()

	public function testAMemberManagerAddsAContactToTheOwnOrganisation(): void {
		$event = $this->creating(data: ['organisation' => self::ORG_X, 'email' => 'new@example.org']);

		$this->listener(caller: 'beheer-x')->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $event->getErrors());
	}//end testAMemberManagerAddsAContactToTheOwnOrganisation()

	public function testAnAdministratorMayWriteAnyContact(): void {
		$event = $this->creating(data: ['organisation' => self::ORG_X, 'email' => 'victim-a']);

		$this->listener(caller: 'root')->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAnAdministratorMayWriteAnyContact()

	public function testAContactCannotNameAnExistingAccountOfAnotherOrganisation(): void {
		$event = $this->creating(data: ['organisation' => self::ORG_X, 'email' => 'victim-a']);

		$this->listener(caller: 'beheer-x')->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testAContactCannotNameAnExistingAccountOfAnotherOrganisation()

	public function testAContactWithoutOrganisationCannotNameSomeoneElsesAccount(): void {
		$event = $this->creating(data: ['email' => 'victim-a']);

		$this->listener(caller: 'outsider')->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testAContactWithoutOrganisationCannotNameSomeoneElsesAccount()

	public function testAMemberManagerMayNameAnAccountThatIsAlreadyAMember(): void {
		$event = $this->creating(data: ['organisation' => self::ORG_X, 'email' => 'victim-x']);

		$this->listener(caller: 'beheer-x')->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAMemberManagerMayNameAnAccountThatIsAlreadyAMember()

	public function testAnAnonymousContactForAnExistingOrganisationIsRefused(): void {
		$event = $this->creating(data: ['organisation' => self::ORG_X, 'email' => 'new@example.org']);

		$this->listener(caller: null)->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testAnAnonymousContactForAnExistingOrganisationIsRefused()

	public function testAContactForAnOrganisationThatIsStillBeingRegisteredIsAccepted(): void {
		$event = $this->creating(data: ['organisation' => 'not-yet-an-organisation', 'email' => 'new@example.org']);

		$this->listener(caller: null)->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAContactForAnOrganisationThatIsStillBeingRegisteredIsAccepted()

	public function testAnActiveOrganisatieWithoutOrganisationYetIsRefused(): void {
		$event = $this->creating(data: ['organisation' => 'active-without-entity', 'email' => 'new@example.org']);

		$this->listener(caller: 'outsider')->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testAnActiveOrganisatieWithoutOrganisationYetIsRefused()

	public function testAnAnonymousContactForAnActiveOrganisatieWithoutOrganisationIsRefused(): void {
		$event = $this->creating(data: ['organisation' => 'active-without-entity', 'email' => 'new@example.org']);

		$this->listener(caller: null)->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testAnAnonymousContactForAnActiveOrganisatieWithoutOrganisationIsRefused()

	public function testAnAdministratorMayAddAContactToAnActiveOrganisatieWithoutOrganisation(): void {
		$event = $this->creating(data: ['organisation' => 'active-without-entity', 'email' => 'new@example.org']);

		$this->listener(caller: 'root')->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAnAdministratorMayAddAContactToAnActiveOrganisatieWithoutOrganisation()

	public function testAContactForAnUnknownOrganisatieIsAccepted(): void {
		$event = $this->creating(data: ['organisation' => 'no-such-organisatie', 'email' => 'new@example.org']);

		$this->listener(caller: 'outsider')->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAContactForAnUnknownOrganisatieIsAccepted()

	public function testAFailedOrganisatieLookupIsRefused(): void {
		$this->organisatieLookupFails = true;
		$event = $this->creating(data: ['organisation' => 'not-yet-an-organisation', 'email' => 'new@example.org']);

		$this->listener(caller: null)->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testAFailedOrganisatieLookupIsRefused()

	public function testAnUpdateThatMovesTheContactToAnotherOrganisationIsChecked(): void {
		$event = $this->updating(
			data: ['organisation' => self::ORG_X, 'email' => 'self@example.org'],
			oldData: ['organisation' => self::ORG_A, 'email' => 'self@example.org']
		);

		$this->listener(caller: 'beheer-a')->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testAnUpdateThatMovesTheContactToAnotherOrganisationIsChecked()

	public function testAnUpdateThatChangesTheEmailAddressIsChecked(): void {
		$event = $this->updating(
			data: ['organisation' => self::ORG_X, 'email' => 'victim-a'],
			oldData: ['organisation' => self::ORG_X, 'email' => 'new@example.org']
		);

		$this->listener(caller: 'beheer-x')->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testAnUpdateThatChangesTheEmailAddressIsChecked()

	public function testAnUpdateWithoutAStoredVersionIsChecked(): void {
		$event = $this->updating(data: ['organisation' => self::ORG_X, 'email' => 'new@example.org'], oldData: null);

		$this->listener(caller: 'outsider')->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testAnUpdateWithoutAStoredVersionIsChecked()

	public function testAnUpdateThatKeepsOrganisationAndEmailIsNotCheckedAgain(): void {
		$event = $this->updating(
			data: ['organisation' => self::ORG_X, 'email' => 'raadpleger-x', 'voornaam' => 'Nieuw'],
			oldData: ['organisation' => self::ORG_X, 'email' => 'raadpleger-x', 'voornaam' => 'Oud']
		);

		$this->listener(caller: 'raadpleger-x')->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAnUpdateThatKeepsOrganisationAndEmailIsNotCheckedAgain()

	public function testDataAnotherListenerChangedIsChecked(): void {
		$event = $this->creating(data: ['email' => 'new@example.org']);
		$event->setModifiedData(['organisation' => self::ORG_X]);

		$this->listener(caller: 'outsider')->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testDataAnotherListenerChangedIsChecked()

	public function testAWriteStackiqMarksAsItsOwnIsNotChecked(): void {
		$event = $this->creating(data: ['organisation' => self::ORG_X, 'email' => 'victim-a']);

		(new SystemContactWrites())->run(fn () => $this->listener(caller: null)->handle($event));

		self::assertFalse($event->isPropagationStopped());
	}//end testAWriteStackiqMarksAsItsOwnIsNotChecked()

	public function testTheMarkEndsWithTheSystemWrite(): void {
		(new SystemContactWrites())->run(fn () => null);
		$event = $this->creating(data: ['organisation' => self::ORG_X, 'email' => 'victim-a']);

		$this->listener(caller: null)->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testTheMarkEndsWithTheSystemWrite()

	public function testTheMarkEndsWhenTheSystemWriteThrows(): void {
		try {
			(new SystemContactWrites())->run(fn () => throw new \RuntimeException('sync failed'));
		} catch (\RuntimeException $e) {
		}

		self::assertFalse((new SystemContactWrites())->isActive());
	}//end testTheMarkEndsWhenTheSystemWriteThrows()

	public function testAnUnmarkedWriteWithoutASessionIsChecked(): void {
		$event = $this->updating(
			data: ['organization' => self::ORG_X, 'email' => 'x@example.org'],
			oldData: ['organization' => self::ORG_A, 'email' => 'x@example.org']
		);

		$this->listener(caller: null)->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testAnUnmarkedWriteWithoutASessionIsChecked()

	public function testAnEmptyOrganisationKeyDoesNotHideTheOtherKey(): void {
		$event = $this->creating(data: ['organisation' => '', 'organization' => self::ORG_X, 'email' => 'attacker@example.org']);

		$this->listener(caller: 'outsider')->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testAnEmptyOrganisationKeyDoesNotHideTheOtherKey()

	public function testTwoOrganisationKeysThatDisagreeAreRefused(): void {
		foreach (['00000000-0000-4000-8000-000000000000', self::ORG_A] as $organisation) {
			$event = $this->creating(data: ['organisation' => $organisation, 'organization' => self::ORG_X, 'email' => 'attacker@example.org']);

			$this->listener(caller: 'beheer-a')->handle($event);

			self::assertTrue($event->isPropagationStopped(), $organisation);
		}
	}//end testTwoOrganisationKeysThatDisagreeAreRefused()

	public function testTwoEmailKeysThatDisagreeAreRefused(): void {
		$event = $this->creating(data: ['organisation' => self::ORG_X, 'email' => 'new@example.org', 'e-mailadres' => 'other@example.org']);

		$this->listener(caller: 'beheer-x')->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testTwoEmailKeysThatDisagreeAreRefused()

	public function testAnUpdateThatSwapsTheOtherOrganisationKeyIsChecked(): void {
		$event = $this->updating(
			data: ['organisation' => self::ORG_A, 'organization' => self::ORG_X, 'email' => 'x@example.org'],
			oldData: ['organization' => self::ORG_A, 'email' => 'x@example.org']
		);

		$this->listener(caller: 'outsider')->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testAnUpdateThatSwapsTheOtherOrganisationKeyIsChecked()

	public function testUsernameCannotNameAnotherAccount(): void {
		$event = $this->updating(
			data: ['organization' => self::ORG_X, 'e-mailadres' => 'victim-x', 'username' => 'root', 'voornaam' => 'x'],
			oldData: ['organization' => self::ORG_X, 'e-mailadres' => 'victim-x', 'username' => 'victim-x']
		);

		$this->listener(caller: 'beheer-x')->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testUsernameCannotNameAnotherAccount()

	public function testAnAdministratorMaySetUsername(): void {
		$event = $this->creating(data: ['organisation' => self::ORG_X, 'email' => 'victim-x', 'username' => 'victim-x']);

		$this->listener(caller: 'root')->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAnAdministratorMaySetUsername()

	public function testAMemberManagesTheirOwnContact(): void {
		$event = $this->creating(data: ['organisation' => self::ORG_X, 'email' => 'beheer-x', 'username' => 'beheer-x']);

		$this->listener(caller: 'beheer-x')->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAMemberManagesTheirOwnContact()

	public function testAMemberChangesTheEmailAddressOfTheirOwnContact(): void {
		$event = $this->updating(
			data: ['organisation' => self::ORG_X, 'email' => 'new-address@example.org', 'username' => 'raadpleger-x'],
			oldData: ['organisation' => self::ORG_X, 'email' => 'raadpleger-x', 'username' => 'raadpleger-x']
		);

		$this->listener(caller: 'raadpleger-x')->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAMemberChangesTheEmailAddressOfTheirOwnContact()

	public function testTheOwnContactCannotNameAnotherAccount(): void {
		$event = $this->updating(
			data: ['organisation' => self::ORG_X, 'email' => 'victim-a', 'username' => 'raadpleger-x'],
			oldData: ['organisation' => self::ORG_X, 'email' => 'raadpleger-x', 'username' => 'raadpleger-x']
		);

		$this->listener(caller: 'raadpleger-x')->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testTheOwnContactCannotNameAnotherAccount()

	public function testTheOwnContactCannotMoveToAnotherOrganisation(): void {
		$event = $this->updating(
			data: ['organisation' => self::ORG_A, 'email' => 'raadpleger-x', 'username' => 'raadpleger-x'],
			oldData: ['organisation' => self::ORG_X, 'email' => 'raadpleger-x', 'username' => 'raadpleger-x']
		);

		$this->listener(caller: 'raadpleger-x')->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testTheOwnContactCannotMoveToAnotherOrganisation()

	public function testTheSameUuidInAnotherCaseIsNoConflict(): void {
		$event = $this->creating(data: ['organisation' => strtoupper(self::ORG_X), 'organization' => self::ORG_X, 'email' => 'new@example.org']);

		$this->listener(caller: 'beheer-x')->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testTheSameUuidInAnotherCaseIsNoConflict()

	public function testAnAccountNameInAnotherCaseOrWithSpacesIsTheSameAccount(): void {
		$event = $this->creating(data: ['organisation' => self::ORG_A, 'email' => ' VICTIM-X ']);

		$this->listener(caller: 'beheer-a')->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testAnAccountNameInAnotherCaseOrWithSpacesIsTheSameAccount()

	public function testAnotherSchemaIsLeftAlone(): void {
		$event = $this->creating(data: ['organisation' => self::ORG_X, 'email' => 'new@example.org'], schema: '30');

		$this->listener(caller: 'outsider')->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAnotherSchemaIsLeftAlone()

	public function testTheListenerIsRegisteredForCreatesAndUpdates(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../../lib/AppInfo/Application.php');

		foreach (['ObjectCreatingEvent', 'ObjectUpdatingEvent'] as $event) {
			self::assertMatchesRegularExpression(
				'/registerEventListener\(\s*' . $event . '::class,\s*ContactPersonOrganisationListener::class\s*\)/',
				$source
			);
		}
	}//end testTheListenerIsRegisteredForCreatesAndUpdates()

	/**
	 * @param array<string, mixed> $data The object data.
	 */
	private function creating(array $data, string $schema = self::SCHEMA): ObjectCreatingEvent {
		return new ObjectCreatingEvent($this->entity(data: $data, schema: $schema));
	}//end creating()

	/**
	 * @param array<string, mixed>      $data    The new object data.
	 * @param array<string, mixed>|null $oldData The stored object data.
	 */
	private function updating(array $data, ?array $oldData): ObjectUpdatingEvent {
		$old = null;
		if ($oldData !== null) {
			$old = $this->entity(data: $oldData, schema: self::SCHEMA);
		}

		return new ObjectUpdatingEvent($this->entity(data: $data, schema: self::SCHEMA), $old);
	}//end updating()

	/**
	 * @param array<string, mixed> $data The object data.
	 */
	private function entity(array $data, string $schema): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getSchema')->willReturn($schema);
		$entity->method('getObject')->willReturn($data);

		return $entity;
	}//end entity()

	private function listener(?string $caller): ContactPersonOrganisationListener {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($caller === null ? null : $this->user(uid: $caller));

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(fn (string $uid): bool => in_array('admin', ($this->groups[$uid] ?? []), true));
		$groupManager->method('isInGroup')->willReturnCallback(fn (string $uid, string $gid): bool => in_array($gid, ($this->groups[$uid] ?? []), true));

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(fn (string $uid): ?IUser => isset($this->groups[strtolower($uid)]) === true ? $this->user(uid: strtolower($uid)) : null);

		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->method('findByUuid')->willReturnCallback(
			function (string $uuid): Organisation {
				if (isset($this->organisations[$uuid]) === false) {
					throw new DoesNotExistException('no organisation');
				}

				$organisation = new Organisation();
				$organisation->setUuid($uuid);
				$organisation->setUsers($this->organisations[$uuid]);
				return $organisation;
			}
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getVoorzieningenConfig')->willReturn(['contactpersoon_schema' => self::SCHEMA, 'organisatie_schema' => '30']);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function (string|int $id): ?ObjectEntity {
				if ($this->organisatieLookupFails === true) {
					throw new \RuntimeException('lookup failed');
				}

				if (isset($this->organisaties[$id]) === false) {
					return null;
				}

				return $this->entity(data: ['status' => $this->organisaties[$id]], schema: '30');
			}
		);

		return new ContactPersonOrganisationListener(
			new ContactPersonOrganisationGuard($session, $groupManager, $userManager, $mapper, $objectService, $settings),
			$settings,
			new SystemContactWrites()
		);
	}//end listener()

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()
}//end class
