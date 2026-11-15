<?php

/**
 * The private group and username helpers of ContactPersonHandler that no caller reaches yet.
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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * addUserToGroup(), getOrganizationGroup(), getAllowedRoleGroups() and
 * ensureUniqueUsername() are private and currently have no caller in the
 * handler, so they are pinned through reflection: a group is created when
 * missing and the user added once, a group lookup refuses without
 * OpenRegister or configuration, and a taken username gets a counter.
 *
 * @covers \OCA\Stackiq\Service\Stackiq\ContactPersonHandler
 */
class ContactPersonHandlerGroupHelpersTest extends TestCase {

	/**
	 * Every log line, as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	public array $logs = [];

	private IUserManager&MockObject $users;

	private IGroupManager&MockObject $groups;

	private ObjectServiceInterface&MockObject $objects;

	private RegisterConfigReaderInterface&MockObject $registers;

	/**
	 * The apps reported as installed.
	 *
	 * @var array<int, string>
	 */
	private array $installed = ['openregister'];

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

		$this->users     = $this->createMock(IUserManager::class);
		$this->groups    = $this->createMock(IGroupManager::class);
		$this->objects   = $this->createMock(ObjectServiceInterface::class);
		$this->registers = $this->createMock(RegisterConfigReaderInterface::class);
	}//end setUp()

	/**
	 * Call a private method of a fresh handler.
	 *
	 * @param string            $method The method name.
	 * @param array<int, mixed> $args   The arguments.
	 *
	 * @return mixed
	 */
	private function call(string $method, array $args): mixed {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturnCallback(fn (): array => $this->installed);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => [
				'OCA\OpenRegister\Service\ObjectService' => $this->objectServiceResolves === true ? $this->objects : null,
				RegisterConfigReaderInterface::class     => $this->registers,
			][$id] ?? null
		);

		$handler = new ContactPersonHandler(
			_userManager: $this->users,
			_secureRandom: $this->createMock(ISecureRandom::class),
			_groupManager: $this->groups,
			_config: $this->createMock(IAppConfig::class),
			_container: $container,
			_appManager: $apps,
			_logger: $this->logger(),
			_emailService: $this->createMock(SymfonyEmailService::class),
			config: $this->createMock(IConfig::class),
		);

		$reflection = new ReflectionMethod($handler, $method);
		$reflection->setAccessible(true);
		return $reflection->invokeArgs($handler, $args);
	}//end call()

	/**
	 * A logger recording into $logs.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		return new class($this) extends AbstractLogger {
			public function __construct(private ContactPersonHandlerGroupHelpersTest $test) {
			}

			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->test->logs[] = [(string) $level, (string) $message, $context];
			}
		};
	}//end logger()

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
	 * A user with this uid.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser&MockObject
	 */
	private function user(string $uid): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	public function testAMissingGroupIsCreatedAndTheUserAdded(): void {
		$anna  = $this->user('anna');
		$group = $this->createMock(IGroup::class);
		$group->method('inGroup')->with($anna)->willReturn(false);
		$group->expects($this->once())->method('addUser')->with($anna);
		$this->groups->method('get')->with('Ambtenaar')->willReturn(null);
		$this->groups->expects($this->once())->method('createGroup')->with('Ambtenaar')->willReturn($group);

		$this->call('addUserToGroup', [$anna, 'Ambtenaar', 'role']);

		$this->assertSame(
			[['groupName' => 'Ambtenaar', 'type' => 'role']],
			$this->logged('info', 'Created group for user assignment')
		);
		$this->assertSame(
			[['username' => 'anna', 'groupName' => 'Ambtenaar', 'type' => 'role']],
			$this->logged('info', 'Added user to group')
		);
	}//end testAMissingGroupIsCreatedAndTheUserAdded()

	public function testAMemberOfAnExistingGroupIsNotAddedAgain(): void {
		$anna  = $this->user('anna');
		$group = $this->createMock(IGroup::class);
		$group->method('inGroup')->with($anna)->willReturn(true);
		$group->expects($this->never())->method('addUser');
		$this->groups->method('get')->willReturn($group);
		$this->groups->expects($this->never())->method('createGroup');

		$this->call('addUserToGroup', [$anna, 'Ambtenaar', 'role']);

		$this->assertSame([], $this->logs);
	}//end testAMemberOfAnExistingGroupIsNotAddedAgain()

	public function testAGroupThatCannotBeCreatedAddsNobody(): void {
		$this->groups->method('get')->willReturn(null);
		$this->groups->expects($this->once())->method('createGroup')->with('Ambtenaar')->willReturn(null);

		$this->call('addUserToGroup', [$this->user('anna'), 'Ambtenaar', 'role']);

		$this->assertSame([], $this->logs);
	}//end testAGroupThatCannotBeCreatedAddsNobody()

	public function testAFailingGroupBackendIsLoggedNotThrown(): void {
		$anna  = $this->user('anna');
		$group = $this->createMock(IGroup::class);
		$group->method('inGroup')->willReturn(false);
		$group->method('addUser')->willThrowException(new RuntimeException('group backend read-only'));
		$this->groups->method('get')->willReturn($group);

		$this->call('addUserToGroup', [$anna, 'Ambtenaar', 'role']);

		$errors = $this->logged('error', 'Failed to add user to group: group backend read-only');
		$this->assertCount(1, $errors);
		$this->assertSame(['anna', 'Ambtenaar', 'role'], [$errors[0]['username'], $errors[0]['groupName'], $errors[0]['type']]);
		$this->assertSame([], $this->logged('info', 'Added user to group'));
	}//end testAFailingGroupBackendIsLoggedNotThrown()

	public function testTheAllowedRoleGroupsMapEachRoleToItsOwnGroup(): void {
		$this->assertSame(
			[
				'Aanbod-beheerder' => 'Aanbod-beheerder',
				'Gebruik-beheerder' => 'Gebruik-beheerder',
				'Gebruik-raadpleger' => 'Gebruik-raadpleger',
				'Functioneel-beheerder' => 'Functioneel-beheerder',
				'VNG-raadpleger' => 'VNG-raadpleger',
				'Organisatie-beheerder' => 'Organisatie-beheerder',
				'Ambtenaar' => 'Ambtenaar',
			],
			$this->call('getAllowedRoleGroups', [])
		);
	}//end testTheAllowedRoleGroupsMapEachRoleToItsOwnGroup()

	public function testTheOrganisationGroupIsTheOneItsObjectNames(): void {
		$group = $this->createMock(IGroup::class);
		$this->registers->method('getVoorzieningenRegisterId')->willReturn(4);
		$this->registers->method('getSchemaIdForObjectType')->with('organization')->willReturn(5);
		$organisation = $this->createMock(ObjectEntity::class);
		$organisation->method('getObject')->willReturn(['group' => 'gemeente-voorbeeld']);
		$this->objects->expects($this->once())->method('find')->with('org-1', [], false, 4, 5)->willReturn($organisation);
		$this->groups->expects($this->once())->method('get')->with('gemeente-voorbeeld')->willReturn($group);

		$this->assertSame($group, $this->call('getOrganizationGroup', ['org-1']));
	}//end testTheOrganisationGroupIsTheOneItsObjectNames()

	public function testAnOrganisationWithoutAGroupHasNone(): void {
		$this->registers->method('getVoorzieningenRegisterId')->willReturn(4);
		$this->registers->method('getSchemaIdForObjectType')->willReturn(5);
		$organisation = $this->createMock(ObjectEntity::class);
		$organisation->method('getObject')->willReturn(['name' => 'Gemeente Voorbeeld']);
		$this->objects->method('find')->willReturn($organisation);
		$this->groups->expects($this->never())->method('get');

		$this->assertNull($this->call('getOrganizationGroup', ['org-1']));
	}//end testAnOrganisationWithoutAGroupHasNone()

	public function testWithoutAConfiguredRegisterNoOrganisationIsLookedUp(): void {
		$this->registers->method('getVoorzieningenRegisterId')->willReturn(null);
		$this->registers->method('getSchemaIdForObjectType')->willReturn(5);
		$this->objects->expects($this->never())->method('find');
		$this->groups->expects($this->never())->method('get');

		$this->assertNull($this->call('getOrganizationGroup', ['org-1']));
		$this->assertSame([[]], $this->logged('warning', 'Register or schema ID not configured for organisatie'));
	}//end testWithoutAConfiguredRegisterNoOrganisationIsLookedUp()

	public function testWithoutOpenRegisterTheGroupLookupIsLoggedAndRefused(): void {
		$this->installed = [];
		$this->objects->expects($this->never())->method('find');
		$this->groups->expects($this->never())->method('get');

		$this->assertNull($this->call('getOrganizationGroup', ['org-1']));

		$errors = $this->logged('error', 'Failed to get organization group: OpenRegister service is not available.');
		$this->assertCount(1, $errors);
		$this->assertSame('org-1', $errors[0]['organizationId']);
	}//end testWithoutOpenRegisterTheGroupLookupIsLoggedAndRefused()

	public function testAnUnresolvableObjectServiceFindsNoGroupSilently(): void {
		$this->objectServiceResolves = false;
		$this->registers->expects($this->never())->method('getVoorzieningenRegisterId');
		$this->groups->expects($this->never())->method('get');

		$this->assertNull($this->call('getOrganizationGroup', ['org-1']));
		$this->assertSame([], $this->logs);
	}//end testAnUnresolvableObjectServiceFindsNoGroupSilently()

	public function testATakenUsernameGetsTheFirstFreeCounter(): void {
		$taken = ['anna', 'anna1'];
		$this->users->method('userExists')->willReturnCallback(static fn (string $uid): bool => in_array($uid, $taken, true));

		$this->assertSame('anna2', $this->call('ensureUniqueUsername', ['anna']));
		$this->assertSame('bob', $this->call('ensureUniqueUsername', ['bob']));
	}//end testATakenUsernameGetsTheFirstFreeCounter()

	public function testAfterTenThousandTakenNamesAUniqueSuffixIsUsed(): void {
		$this->users->expects($this->exactly(9999))->method('userExists')->willReturn(true);

		$this->assertMatchesRegularExpression('/^anna[0-9a-f]{13}$/', $this->call('ensureUniqueUsername', ['anna']));
	}//end testAfterTenThousandTakenNamesAUniqueSuffixIsUsed()
}//end class
