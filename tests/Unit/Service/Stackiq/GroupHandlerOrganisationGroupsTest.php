<?php

/**
 * Tests for the organisation, configuration and listing paths of GroupHandler.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service\Stackiq
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Stackiq;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCA\Stackiq\Service\Stackiq\GroupHandler;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Putting a contact person's user in their organisation's group, and the group configuration and listing.
 *
 * @covers \OCA\Stackiq\Service\Stackiq\GroupHandler
 * @uses   \OCA\Stackiq\Service\Stackiq\RoleGroupMembership
 */
class GroupHandlerOrganisationGroupsTest extends TestCase {
	/**
	 * The group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * The user manager.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager&MockObject $userManager;

	/**
	 * The app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * The OpenRegister object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * The settings service.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * Logger that records every call.
	 *
	 * @var AbstractLogger
	 */
	private AbstractLogger $logger;

	/**
	 * Whether OpenRegister is installed.
	 *
	 * @var boolean
	 */
	private bool $openRegister = true;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->groupManager  = $this->createMock(IGroupManager::class);
		$this->userManager   = $this->createMock(IUserManager::class);
		$this->appConfig     = $this->createMock(IAppConfig::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->settings      = $this->createMock(SettingsService::class);
		$this->logger        = new class extends AbstractLogger {
			/**
			 * Recorded calls.
			 *
			 * @var array<int, array{0: mixed, 1: string, 2: array}>
			 */
			public array $records = [];

			/**
			 * @param mixed $level   The level.
			 * @param mixed $message The message.
			 * @param array $context The context.
			 *
			 * @return void
			 */
			public function log($level, $message, array $context = []): void {
				$this->records[] = [$level, (string)$message, $context];
			}
		};
	}

	/**
	 * The handler under test.
	 *
	 * @return GroupHandler
	 */
	private function handler(): GroupHandler {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturnCallback(
			function (): array {
				if ($this->openRegister === true) {
					return ['openregister'];
				}

				return ['files'];
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => match ($id) {
				'OCA\OpenRegister\Service\ObjectService' => $this->objectService,
				RegisterConfigReaderInterface::class => $this->settings,
			}
		);

		return new GroupHandler($this->groupManager, $this->userManager, $this->appConfig, $container, $apps, $this->logger);
	}

	/**
	 * A user with a uid.
	 *
	 * @return IUser&MockObject The user.
	 */
	private function user(): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('ann');
		return $user;
	}

	/**
	 * Configure the register and schema, and the organisation the lookup finds.
	 *
	 * @param array<string, mixed>|null $organisation The organisation data, null when not found.
	 *
	 * @return void
	 */
	private function organisationLookup(?array $organisation): void {
		$this->settings->method('getVoorzieningenRegisterId')->willReturn(5);
		$this->settings->method('getSchemaIdForObjectType')->with('organization')->willReturn(6);
		$found = null;
		if ($organisation !== null) {
			$found = $this->createMock(ObjectEntityInterface::class);
			$found->method('getObject')->willReturn($organisation);
		}

		$this->objectService->method('find')->with('org-1', [], false, 5, 6)->willReturn($found);
	}

	/**
	 * The messages logged at a level.
	 *
	 * @param string $level The level.
	 *
	 * @return array<int, string> The messages.
	 */
	private function messages(string $level): array {
		$records = array_filter($this->logger->records, static fn (array $r): bool => $r[0] === $level);

		return array_values(array_map(static fn (array $r): string => $r[1], $records));
	}

	/**
	 * The user joins the group the organisation names.
	 *
	 * @return void
	 */
	public function testTheUserJoinsTheOrganisationsGroup(): void {
		$user  = $this->user();
		$group = $this->createMock(IGroup::class);
		$group->method('inGroup')->with($user)->willReturn(false);
		$group->expects($this->once())->method('addUser')->with($user);
		$this->groupManager->method('get')->with('acme')->willReturn($group);
		$this->organisationLookup(['id' => 'org-real', 'group' => 'acme']);

		$this->handler()->updateOrganizationGroups($user, ['organisation' => 'org-1']);

		$added = array_values(array_filter($this->logger->records, static fn (array $r): bool => $r[1] === 'Added user to organization group'));
		self::assertSame(['username' => 'ann', 'group' => 'acme', 'organizationUuid' => 'org-real'], $added[0][2]);
	}

	/**
	 * A member already in the group, an organisation without a group, and a missing group change nothing.
	 *
	 * @return void
	 */
	public function testNoMembershipChangeWhenNothingToJoin(): void {
		$user  = $this->user();
		$group = $this->createMock(IGroup::class);
		$group->method('inGroup')->willReturn(true);
		$group->expects($this->never())->method('addUser');
		$this->groupManager->method('get')->willReturnCallback(static fn (string $gid): ?IGroup => match ($gid) {
			'acme' => $group,
			default => null,
		});
		$this->organisationLookup(['group' => 'acme']);

		$this->handler()->updateOrganizationGroups($user, ['organization' => 'org-1']);
		$this->handler()->updateOrganizationGroups($user, []);

		self::assertNotContains('Added user to organization group', $this->messages('info'));
	}

	/**
	 * An unknown organisation or a missing configuration is not an error.
	 *
	 * @return void
	 */
	public function testAnUnresolvableOrganisationIsSkipped(): void {
		$this->groupManager->expects($this->never())->method('get');
		$this->organisationLookup(null);

		$this->handler()->updateOrganizationGroups($this->user(), ['organisation' => 'org-1']);

		self::assertSame([], $this->messages('error'));
	}

	/**
	 * Without a configured register the lookup warns and the user is left alone.
	 *
	 * @return void
	 */
	public function testAMissingRegisterWarns(): void {
		$this->settings->method('getVoorzieningenRegisterId')->willReturn(null);
		$this->objectService->expects($this->never())->method('find');

		$this->handler()->updateOrganizationGroups($this->user(), ['organisation' => 'org-1']);
		$this->handler()->updateGemeenteGroups($this->user(), ['organisation' => 'org-1']);

		self::assertSame(
			['Register or schema ID not configured for organisatie.', 'Register or schema ID not configured for organisatie.'],
			$this->messages('warning')
		);
	}

	/**
	 * Without OpenRegister both organisation steps log their failure instead of throwing.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheFailureIsLogged(): void {
		$this->openRegister = false;

		$this->handler()->updateOrganizationGroups($this->user(), ['organisation' => 'org-1']);
		$this->handler()->updateGemeenteGroups($this->user(), ['organisation' => 'org-1']);

		self::assertSame(
			[
				'Failed to process organization group: OpenRegister service is not available.',
				'Failed to process gemeente group: OpenRegister service is not available.',
			],
			$this->messages('error')
		);
	}

	/**
	 * A user from a gemeente is noted but gets no automatic group.
	 *
	 * @return void
	 */
	public function testAGemeenteUserIsOnlyNoted(): void {
		$this->groupManager->expects($this->never())->method('get');
		$this->organisationLookup(['id' => 'org-1', 'soort' => 'Gemeente']);

		$this->handler()->updateGemeenteGroups($this->user(), ['organisation' => 'org-1']);
		$this->handler()->updateGemeenteGroups($this->user(), []);

		self::assertSame(['User from gemeente organization (no automatic ambtenaar group assignment)'], $this->messages('debug'));
	}

	/**
	 * Updating a user's groups runs every step for an existing user and warns for an unknown one.
	 *
	 * @return void
	 */
	public function testUpdateUserGroupsForKnownAndUnknownUsers(): void {
		$user = $this->user();
		$this->userManager->method('get')->willReturnCallback(static fn (string $uid): ?IUser => match ($uid) {
			'ann' => $user,
			default => null,
		});
		$this->appConfig->method('getValueString')->willReturn('[]');
		$this->groupManager->method('getUserGroups')->with($user)->willReturn(['staff' => $this->createMock(IGroup::class)]);
		$contact = new class {
			/**
			 * @return array<string, mixed> The contact data.
			 */
			public function getObject(): array {
				return ['roles' => []];
			}
		};

		$this->handler()->updateUserGroups($contact, 'ghost');
		$this->handler()->updateUserGroups($contact, 'ann');

		self::assertSame(['User not found for group update'], $this->messages('warning'));
		$updated = array_values(array_filter($this->logger->records, static fn (array $r): bool => $r[1] === 'Updated user groups successfully'));
		self::assertSame(['username' => 'ann', 'groups' => ['staff']], $updated[0][2]);
	}

	/**
	 * A failing step is logged, not thrown.
	 *
	 * @return void
	 */
	public function testUpdateUserGroupsLogsFailures(): void {
		$this->userManager->method('get')->willThrowException(new RuntimeException('ldap down'));

		$this->handler()->updateUserGroups(new \stdClass(), 'ann');

		self::assertSame(['Failed to update user groups: ldap down'], $this->messages('error'));
	}

	/**
	 * The generic groups are stored as JSON.
	 *
	 * @return void
	 */
	public function testGenericGroupsAreStoredAsJson(): void {
		$this->appConfig->expects($this->once())->method('setValueString')->with('stackiq', 'generic_user_groups', '["users","guests"]');

		$this->handler()->setGenericUserGroups(['users', 'guests']);

		self::assertSame(['Updated generic user groups configuration'], $this->messages('info'));
	}

	/**
	 * Every group is listed with its member count and whether it is generic.
	 *
	 * @return void
	 */
	public function testAllGroupsAreListed(): void {
		$generic = $this->createMock(IGroup::class);
		$generic->method('getGID')->willReturn('software-catalog-users');
		$generic->method('getDisplayName')->willReturn('Catalog users');
		$generic->method('getUsers')->willReturn([$this->user(), $this->user()]);
		$other = $this->createMock(IGroup::class);
		$other->method('getGID')->willReturn('admin');
		$other->method('getDisplayName')->willReturn('Admins');
		$other->method('getUsers')->willReturn([]);
		$this->groupManager->method('search')->with('')->willReturn([$generic, $other]);
		$this->appConfig->method('getValueString')->willReturn('');

		self::assertSame(
			[
				['id' => 'software-catalog-users', 'displayName' => 'Catalog users', 'memberCount' => 2, 'isGeneric' => true],
				['id' => 'admin', 'displayName' => 'Admins', 'memberCount' => 0, 'isGeneric' => false],
			],
			$this->handler()->getAllGroups()
		);
	}

	/**
	 * Empty and non-text group names are invalid; names are otherwise accepted.
	 *
	 * @return void
	 */
	public function testEmptyGroupNamesAreInvalid(): void {
		self::assertSame(
			[
				'valid' => ['users', 'team.a-1'],
				'invalid' => ['', 5],
				'errors' => ['Group name cannot be empty', 'Group name cannot be empty'],
			],
			$this->handler()->validateGroups(['users', '', 5, 'team.a-1'])
		);
	}
}
