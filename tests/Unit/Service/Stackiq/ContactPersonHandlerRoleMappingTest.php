<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction b.v. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Stackiq;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCA\Stackiq\Service\SymfonyEmailService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * THE DEFECT UNDER TEST (stackiq#1137).
 *
 * `organization.type` holds Municipality, Supplier, Collaboration or
 * Community (the register enum, after #520 translated it and migrated the
 * rows). The organisation-type role map still used the Dutch keys gemeente,
 * leverancier, samenwerking and community, so only Community matched: a
 * contact of a municipality or supplier got no role group, and every
 * register read rule that names gebruik-beheerder or aanbod-beheerder
 * skipped them.
 *
 * The tests drive the callers (account creation and contact update) with
 * the organisation type read through the real handler path, not only the
 * private map, so a map that is right but unreachable cannot pass.
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-002-a-person-shall-be-in-exactly-the-role-groups-of-the-roles-they-derive
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class ContactPersonHandlerRoleMappingTest extends TestCase {

	/**
	 * Groups the user was added to, by gid.
	 *
	 * @var string[]
	 */
	private array $addedTo = [];

	/**
	 * Groups the user was removed from, by gid.
	 *
	 * @var string[]
	 */
	private array $removedFrom = [];

	/**
	 * The organisation types of the register enum and the role group each derives.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function organisationTypes(): array {
		return [
			'Municipality' => ['Municipality', 'gebruik-beheerder'],
			'Supplier' => ['Supplier', 'aanbod-beheerder'],
			'Collaboration' => ['Collaboration', 'gebruik-beheerder'],
			'Community' => ['Community', 'aanbod-beheerder'],
		];
	}//end organisationTypes()

	/**
	 * Every value of the register's organization.type enum has a role group.
	 *
	 * Reads the enum from the shipped register, so a type added there without
	 * a role mapping fails here.
	 *
	 * @return void
	 */
	public function testEveryRegisterOrganisationTypeHasARoleGroup(): void {
		$path = __DIR__ . '/../../../../lib/Settings/softwarecatalogus_register.json';
		$register = json_decode((string)file_get_contents($path), true);
		$enum = $register['components']['schemas']['organization']['properties']['type']['enum'] ?? [];
		$this->assertNotEmpty($enum, 'organization.type must declare an enum');

		$handler = $this->makeHandler(organisationType: '');
		$map = new ReflectionMethod($handler, 'getRoleGroupByOrganizationType');
		$map->setAccessible(true);

		foreach ($enum as $type) {
			$this->assertNotSame(
				'',
				$map->invoke($handler, $type),
				"organization.type '$type' maps to no role group, so its contacts get none"
			);
		}
	}//end testEveryRegisterOrganisationTypeHasARoleGroup()

	/**
	 * The map ignores case and surrounding whitespace.
	 *
	 * @return void
	 */
	public function testTheMapIgnoresCase(): void {
		$handler = $this->makeHandler(organisationType: '');
		$map = new ReflectionMethod($handler, 'getRoleGroupByOrganizationType');
		$map->setAccessible(true);

		$this->assertSame('gebruik-beheerder', $map->invoke($handler, ' municipality '));
		$this->assertSame('aanbod-beheerder', $map->invoke($handler, 'SUPPLIER'));
		$this->assertSame('', $map->invoke($handler, 'Unknown'));
		$this->assertSame('', $map->invoke($handler, ''));
	}//end testTheMapIgnoresCase()

	/**
	 * On account creation a contact gets the role group of their organisation's type.
	 *
	 * @param string $type The organisation type as stored.
	 * @param string $roleGroup The role group it must derive.
	 *
	 * @return void
	 */
	#[DataProvider('organisationTypes')]
	public function testANewContactGetsTheRoleGroupOfTheirOrganisationType(string $type, string $roleGroup): void {
		$handler = $this->makeHandler(organisationType: $type);

		$assign = new ReflectionMethod($handler, 'assignUserGroups');
		$assign->setAccessible(true);
		$rolesValue = $assign->invoke($handler, $this->user(), ['organisation' => 'org-1', 'roles' => []], false);

		$this->assertSame([$roleGroup], $this->addedTo, "A contact of a $type organisation must be put in $roleGroup");
		$this->assertSame(ucfirst($roleGroup), $rolesValue);
	}//end testANewContactGetsTheRoleGroupOfTheirOrganisationType()

	/**
	 * On update a contact moves to the role group of their organisation's type.
	 *
	 * @return void
	 */
	public function testAnUpdatedSupplierContactMovesToAanbodBeheerder(): void {
		$handler = $this->makeHandler(organisationType: 'Supplier', alreadyIn: ['gebruik-beheerder']);

		$handler->updateUserGroupsFromContactData($this->user(), ['organisation' => 'org-1']);

		$this->assertSame(['aanbod-beheerder'], $this->addedTo);
		$this->assertSame(['gebruik-beheerder'], $this->removedFrom);
	}//end testAnUpdatedSupplierContactMovesToAanbodBeheerder()

	/**
	 * Build the handler with its organisation lookup answering one type.
	 *
	 * @param string $organisationType The `type` the organisation object holds.
	 * @param string[] $alreadyIn Role groups the user is already in.
	 *
	 * @return ContactPersonHandler
	 */
	private function makeHandler(string $organisationType, array $alreadyIn = []): ContactPersonHandler {
		$this->addedTo = [];
		$this->removedFrom = [];

		$organisation = $this->createMock(ObjectEntity::class);
		$organisation->method('getObject')->willReturn(['type' => $organisationType]);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturn($organisation);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getVoorzieningenConfig')->willReturn(
			['register' => '1', 'organisatie_schema' => '2']
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($settingsService, $objectService) {
				if ($id === 'OCA\Stackiq\Service\SettingsService') {
					return $settingsService;
				}

				if ($id === 'OCA\OpenRegister\Service\ObjectService') {
					return $objectService;
				}

				throw new \RuntimeException('Unexpected container lookup: ' . $id);
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister', 'stackiq']);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturnCallback(
			function (string $gid) use ($alreadyIn): IGroup {
				$group = $this->createMock(IGroup::class);
				$group->method('inGroup')->willReturn(in_array($gid, $alreadyIn, true));
				$group->method('addUser')->willReturnCallback(
					function () use ($gid): void {
						$this->addedTo[] = $gid;
					}
				);
				$group->method('removeUser')->willReturnCallback(
					function () use ($gid): void {
						$this->removedFrom[] = $gid;
					}
				);
				return $group;
			}
		);

		return new ContactPersonHandler(
			$this->createMock(IUserManager::class),
			$this->createMock(ISecureRandom::class),
			$groupManager,
			$this->createMock(IAppConfig::class),
			$container,
			$appManager,
			$this->createMock(LoggerInterface::class),
			$this->createMock(SymfonyEmailService::class),
			$this->createMock(IConfig::class)
		);
	}//end makeHandler()

	/**
	 * A user double.
	 *
	 * @return IUser
	 */
	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('contact-1');
		return $user;
	}//end user()
}//end class
