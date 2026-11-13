<?php

/**
 * Tests for ContactPersonOrganisationGuard on its own.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service\Contactpersoon
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Contactpersoon;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Stackiq\Service\Contactpersoon\ContactPersonOrganisationGuard;
use OCA\Stackiq\Service\Contactpersoon\ContactPersonWrite;
use OCA\Stackiq\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The guard's decisions the listener tests do not reach: an organisation given
 * as an object reference, a contact without organisation, and an organisatie
 * that OpenRegister reports as missing rather than failing to read.
 *
 * @covers \OCA\Stackiq\Service\Contactpersoon\ContactPersonOrganisationGuard
 * @covers \OCA\Stackiq\Service\Contactpersoon\ContactPersonWrite
 */
final class ContactPersonOrganisationGuardTest extends TestCase {

	/**
	 * Organisation uuid => member uids, as OpenRegister's organisations hold them.
	 *
	 * @var array<string, list<string>>
	 */
	private array $organisations = [
		'org-1' => ['beheerder'],
		'org-2' => ['ander'],
	];

	/**
	 * Uid => groups.
	 *
	 * @var array<string, list<string>>
	 */
	private array $groups = [
		'beheerder' => ['gebruik-beheerder'],
		'lid'       => [],
	];

	/**
	 * The ObjectService mock.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * Build the mocked ObjectService before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectService::class);
	}//end setUp()

	/**
	 * An organisation given as an object with a uuid is checked like a plain uuid.
	 *
	 * @return void
	 */
	public function testAnOrganisationReferenceByUuidIsTheOrganisation(): void {
		$guard = $this->guard(caller: 'beheerder');

		$this->assertTrue($guard->allows(contactData: ['organisation' => ['uuid' => 'ORG-1'], 'email' => 'nieuw@example.org']));
		$this->assertFalse($guard->allows(contactData: ['organisation' => ['uuid' => 'org-2'], 'email' => 'nieuw@example.org']));
	}//end testAnOrganisationReferenceByUuidIsTheOrganisation()

	/**
	 * Without a uuid, an object reference's id names the organisation.
	 *
	 * @return void
	 */
	public function testAnOrganisationReferenceFallsBackToItsId(): void {
		$guard = $this->guard(caller: 'beheerder');

		$this->assertTrue($guard->allows(contactData: ['organization' => ['id' => 'org-1']]));
		$this->assertFalse($guard->allows(contactData: ['organization' => ['id' => 'org-2']]));
	}//end testAnOrganisationReferenceFallsBackToItsId()

	/**
	 * Two references that name one organisation are not ambiguous; two different ones are.
	 *
	 * @return void
	 */
	public function testTwoKeysNamingDifferentOrganisationsAreRefused(): void {
		$guard = $this->guard(caller: 'beheerder');

		$this->assertTrue($guard->allows(contactData: ['organisation' => 'org-1', 'organization' => ['uuid' => 'org-1']]));
		$this->assertFalse($guard->allows(contactData: ['organisation' => 'org-1', 'organization' => ['uuid' => 'org-2']]));
	}//end testTwoKeysNamingDifferentOrganisationsAreRefused()

	/**
	 * A contact without organisation is no member's to refuse, and no organisatie is read for it.
	 *
	 * @return void
	 */
	public function testAContactWithoutOrganisationIsAllowedWithoutALookup(): void {
		$this->objectService->expects($this->never())->method('find');

		$this->assertTrue($this->guard(caller: 'lid')->allows(contactData: ['naam' => 'Jan', 'organisation' => ['uuid' => '  ']]));
	}//end testAContactWithoutOrganisationIsAllowedWithoutALookup()

	/**
	 * An organisatie OpenRegister reports as missing cannot become active, so the contact may be added.
	 *
	 * @return void
	 */
	public function testAMissingOrganisatieDoesNotBlock(): void {
		$calls = [];
		$this->objectService->expects($this->once())
			->method('find')
			->willReturnCallback(
				function (string|int $id, ?array $_extend=[], bool $files=false, string|int|null $register=null, string|int|null $schema=null, bool $_rbac=true, bool $_multitenancy=true) use (&$calls): never {
					$calls[] = [$id, $register, $schema, $_rbac, $_multitenancy];
					throw new DoesNotExistException('gone');
				}
			);

		$this->assertTrue($this->guard(caller: 'lid')->allows(contactData: ['organisation' => 'org-new']));
		$this->assertSame([['org-new', 'voorzieningen', 'organisatie', false, false]], $calls);
	}//end testAMissingOrganisatieDoesNotBlock()

	/**
	 * Any other failure to read the organisatie blocks the write: the guard fails closed.
	 *
	 * @return void
	 */
	public function testAnUnreadableOrganisatieBlocks(): void {
		$this->objectService->method('find')->willThrowException(new \RuntimeException('database down'));

		$this->assertFalse($this->guard(caller: 'lid')->allows(contactData: ['organisation' => 'org-new']));
	}//end testAnUnreadableOrganisatieBlocks()

	/**
	 * A write carries its data, its previous data and, until the guard sets it, no caller.
	 *
	 * @return void
	 */
	public function testAWriteCarriesItsDataAndNoCallerYet(): void {
		$write = new ContactPersonWrite(contactData: ['naam' => 'Jan'], oldData: null);

		$this->assertSame(['naam' => 'Jan'], $write->contactData);
		$this->assertNull($write->oldData);
		$this->assertNull($write->caller);
	}//end testAWriteCarriesItsDataAndNoCallerYet()

	/**
	 * A guard for the given caller over the fixture's groups and organisations.
	 *
	 * @param string $caller The signed-in uid.
	 *
	 * @return ContactPersonOrganisationGuard
	 */
	private function guard(string $caller): ContactPersonOrganisationGuard {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($caller);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->method('isInGroup')->willReturnCallback(fn (string $uid, string $gid): bool => in_array($gid, ($this->groups[$uid] ?? []), true));

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn(null);

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
		$settings->method('getVoorzieningenConfig')->willReturn(['register' => 'voorzieningen', 'organisatie_schema' => 'organisatie']);

		return new ContactPersonOrganisationGuard($session, $groupManager, $userManager, $mapper, $this->objectService, $settings);
	}//end guard()
}//end class
