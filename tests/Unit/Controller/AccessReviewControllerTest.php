<?php

/**
 * Who may review whom, what a review lists, and how Keep access and Remove role are recorded.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-003-a-functional-administrator-shall-review-the-access-of-their-organisations-people
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-004-a-reviewer-shall-confirm-or-withdraw-a-persons-access-and-the-decision-shall-be-recorded
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Controller\AccessReviewController;
use OCA\Stackiq\Service\RoleGrantSync;
use OCA\Stackiq\Service\RoleGroupMap;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Drives the controller with the real role sync and role map; Nextcloud and OpenRegister are doubles.
 *
 * Gemeente Voorbeeld (ORG) has a functional administrator `fa`, a colleague
 * who signed in fourteen months ago and was never reviewed, and a newcomer.
 * Gemeente Anders (OTHER) has one person, whom the contact person search
 * also returns, to prove the listing re-checks the organisation.
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-003-a-functional-administrator-shall-review-the-access-of-their-organisations-people
 */
final class AccessReviewControllerTest extends TestCase {

	/**
	 * Gemeente Voorbeeld.
	 *
	 * @var string
	 */
	private const ORG = '5d0c7e3a-1b2f-4c6d-8e9f-0a1b2c3d4e5f';

	/**
	 * Gemeente Anders.
	 *
	 * @var string
	 */
	private const OTHER = '9e8d7c6b-5a49-4382-a1b0-c9d8e7f6a5b4';

	/**
	 * Now: 14 November 2026, noon UTC.
	 *
	 * @var int
	 */
	private const NOW = 1794657600;

	/**
	 * The group memberships, by group id.
	 *
	 * @var array<string, list<string>>
	 */
	private array $groups = [
		'admin' => ['root'],
		'functioneel-beheerder' => ['fa', 'colleague'],
		'gebruik-beheerder' => ['joe', 'colleague'],
		'aanbod-beheerder' => [],
		'gebruik-raadpleger' => ['colleague'],
		'organisatie-beheerder' => [],
		'vng-raadpleger' => [],
		'Inkoop' => ['colleague'],
	];

	/**
	 * Each user's active organisation.
	 *
	 * @var array<string, string>
	 */
	private array $activeOrganisation = [
		'fa' => self::ORG,
		'joe' => self::ORG,
		'root' => self::OTHER,
	];

	/**
	 * The contact persons, by UUID.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $contactPersons = [];

	/**
	 * The patches written to OpenRegister, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $patches = [];

	/**
	 * Whether the contact person register is configured.
	 *
	 * @var bool
	 */
	private bool $configured = true;

	/**
	 * Whether reading the caller's groups fails.
	 *
	 * @var bool
	 */
	private bool $groupsFail = false;

	/**
	 * OpenRegister's object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Fill Gemeente Voorbeeld and Gemeente Anders.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->contactPersons = [
			'cp-colleague' => ['username' => 'colleague', 'organisation' => self::ORG, 'roles' => ['Gebruik-beheerder', 'Functioneel-beheerder']],
			'cp-fa' => [
				'username' => 'fa',
				'organization' => self::ORG,
				'roles' => ['Functioneel-beheerder'],
				'accessReviewedAt' => '2026-11-07T09:00:00+00:00',
				'accessReviewedBy' => 'root',
			],
			'cp-newcomer' => ['username' => 'newcomer', 'organisation' => self::ORG, 'roles' => []],
			'cp-no-account' => ['organisation' => self::ORG, 'roles' => ['Gebruik-beheerder']],
			'cp-anders' => ['username' => 'anders', 'organisation' => self::OTHER, 'roles' => []],
		];
	}//end setUp()

	/**
	 * The controller for a signed-in user, or none.
	 *
	 * @param string|null          $uid    The caller.
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return AccessReviewController
	 */
	private function controller(?string $uid, array $params = []): AccessReviewController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($uid === null ? null : $this->user(uid: $uid));

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(fn (string $uid): bool => in_array($uid, $this->groups['admin'], true));
		$groupManager->method('get')->willReturnCallback(fn (string $gid): ?IGroup => $this->group(gid: $gid));
		$groupManager->method('getUserGroups')->willReturnCallback(
			function (IUser $user): array {
				if ($this->groupsFail === true) {
					throw new RuntimeException('directory down');
				}

				$mine = [];
				foreach ($this->groups as $gid => $members) {
					if (in_array($user->getUID(), $members, true) === true) {
						$mine[$gid] = $this->group(gid: $gid);
					}
				}

				return $mine;
			}
		);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(
			fn (string $uid): ?IUser => in_array($uid, ['fa', 'colleague', 'newcomer', 'anders', 'joe', 'root'], true) ? $this->user(uid: $uid) : null
		);

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			fn (string $uid, string $app, string $key, $default = ''): string => ($app === 'core' && $key === 'organisation') ? ($this->activeOrganisation[$uid] ?? '') : (string) $default
		);

		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->objectService->method('searchObjects')->willReturnCallback(
			fn (): array => array_map(fn (string $id): ObjectEntityInterface => $this->entity(id: $id, data: $this->contactPersons[$id]), array_keys($this->contactPersons))
		);
		$this->objectService->method('find')->willReturnCallback(
			function (int|string $id, ...$rest): ?ObjectEntityInterface {
				if ($id === self::ORG) {
					return $this->entity(id: self::ORG, data: ['type' => 'Municipality']);
				}

				if (isset($this->contactPersons[$id]) === true) {
					return $this->entity(id: (string) $id, data: $this->contactPersons[$id]);
				}

				return null;
			}
		);
		$this->objectService->method('patchObject')->willReturnCallback(
			function (string $objectId, array $data, $register = null, $schema = null, bool $_rbac = true, bool $_multitenancy = true): ObjectEntityInterface {
				$this->patches[] = ['id' => $objectId, 'data' => $data, 'register' => $register, 'schema' => $schema, 'rbac' => $_rbac];
				$this->contactPersons[$objectId] = array_merge($this->contactPersons[$objectId], $data);
				return $this->entity(id: $objectId, data: $this->contactPersons[$objectId]);
			}
		);

		$registerConfig = $this->createMock(RegisterConfigReaderInterface::class);
		$registerConfig->method('getObjectService')->willReturn($this->objectService);
		$registerConfig->method('getRegisterIdForObjectType')->willReturnCallback(fn (): ?int => $this->configured === true ? 1 : null);
		$registerConfig->method('getSchemaIdForObjectType')->willReturnCallback(
			fn (string $type): ?int => $this->configured === true ? ['organization' => 2, 'contactPerson' => 3][$type] : null
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'role_mapping' => (string) json_encode(['Gebruik-raadpleger' => ['Inkoop']]),
				default => $default,
			}
		);
		$roleGroupMap = new RoleGroupMap(appConfig: $appConfig);
		$logger = $this->createMock(LoggerInterface::class);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => $params[$key] ?? $default);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new AccessReviewController(
			appName: 'stackiq',
			request: $request,
			userSession: $session,
			groupManager: $groupManager,
			userManager: $userManager,
			config: $config,
			registerConfig: $registerConfig,
			roleGrantSync: new RoleGrantSync(roleGroupMap: $roleGroupMap, groupManager: $groupManager, registerConfig: $registerConfig, logger: $logger),
			roleGroupMap: $roleGroupMap,
			timeFactory: $time,
			l10n: $l10n,
			logger: $logger
		);
	}//end controller()

	/**
	 * A user double; the colleague last signed in fourteen months ago.
	 *
	 * @param string $uid The user id.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn(ucfirst($uid));
		$user->method('isEnabled')->willReturn($uid !== 'newcomer');
		$user->method('getLastLogin')->willReturn(match ($uid) {
			'colleague' => strtotime('2025-09-14T08:00:00Z'),
			'fa' => self::NOW - 3600,
			default => 0,
		});
		return $user;
	}//end user()

	/**
	 * A group double over $this->groups.
	 *
	 * @param string $gid The group id.
	 *
	 * @return IGroup|null
	 */
	private function group(string $gid): ?IGroup {
		if (array_key_exists($gid, $this->groups) === false) {
			return null;
		}

		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn($gid);
		$group->method('inGroup')->willReturnCallback(fn (IUser $user): bool => in_array($user->getUID(), $this->groups[$gid], true));
		$group->method('removeUser')->willReturnCallback(
			function (IUser $user) use ($gid): void {
				$this->groups[$gid] = array_values(array_diff($this->groups[$gid], [$user->getUID()]));
			}
		);
		$group->method('addUser')->willReturnCallback(
			function (IUser $user) use ($gid): void {
				$this->groups[$gid][] = $user->getUID();
			}
		);
		return $group;
	}//end group()

	/**
	 * An OpenRegister object double.
	 *
	 * @param string               $id   The UUID.
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(string $id, array $data): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getUuid')->willReturn($id);
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * A functional administrator lists their own organisation: every person with an account,
	 * their roles and sources, last sign-in, enabled, reviewed at and by, and due.
	 *
	 * @return void
	 */
	public function testAFunctionalAdministratorListsTheirOwnOrganisation(): void {
		$response = $this->controller(uid: 'fa')->index(self::ORG);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(365, $data['reviewIntervalDays']);
		$this->assertSame(['cp-colleague', 'cp-fa', 'cp-newcomer'], array_column($data['results'], 'id'), 'No person without an account, nor of another organisation.');

		[$colleague, $self, $newcomer] = $data['results'];
		$this->assertSame('colleague', $colleague['username']);
		$this->assertSame('2025-09-14T08:00:00+00:00', $colleague['lastLogin']);
		$this->assertTrue($colleague['enabled']);
		$this->assertNull($colleague['accessReviewedAt']);
		$this->assertNull($colleague['accessReviewedBy']);
		$this->assertTrue($colleague['due'], 'Never reviewed is due.');
		$this->assertFalse($colleague['self']);
		$this->assertSame(
			[
				[
					'role' => 'Gebruik-beheerder',
					'group' => 'gebruik-beheerder',
					'sources' => [['type' => 'contactPerson'], ['type' => 'organisationType', 'organisationType' => 'Municipality']],
					'removable' => true,
				],
				[
					'role' => 'Gebruik-raadpleger',
					'group' => 'gebruik-raadpleger',
					'sources' => [['type' => 'group', 'group' => 'Inkoop']],
					'removable' => false,
				],
				[
					'role' => 'Functioneel-beheerder',
					'group' => 'functioneel-beheerder',
					'sources' => [['type' => 'contactPerson']],
					'removable' => true,
				],
			],
			$colleague['roles']
		);

		$this->assertTrue($self['self']);
		$this->assertFalse($self['due'], 'Reviewed a week ago is not due under a year.');
		$this->assertSame('2026-11-07T09:00:00+00:00', $self['accessReviewedAt']);
		$this->assertSame('root', $self['accessReviewedBy']);

		$this->assertFalse($newcomer['enabled']);
		$this->assertNull($newcomer['lastLogin'], 'Never signed in is null, not 1970.');
	}//end testAFunctionalAdministratorListsTheirOwnOrganisation()

	/**
	 * A review older than the interval is due again.
	 *
	 * @return void
	 */
	public function testAnOldReviewIsDue(): void {
		$this->contactPersons['cp-colleague']['accessReviewedAt'] = '2025-09-01T00:00:00+00:00';
		$this->contactPersons['cp-colleague']['accessReviewedBy'] = 'fa';

		$rows = $this->controller(uid: 'fa')->index(self::ORG)->getData()['results'];

		$this->assertTrue($rows[0]['due']);
		$this->assertSame('fa', $rows[0]['accessReviewedBy']);
	}//end testAnOldReviewIsDue()

	/**
	 * Another organisation's list is refused before any read; a Nextcloud admin gets it.
	 *
	 * @return void
	 */
	public function testAnotherOrganisationIsRefusedAndAnAdminIsServed(): void {
		$controller = $this->controller(uid: 'fa');
		$this->objectService->expects($this->never())->method('searchObjects');
		$refused = $controller->index(self::OTHER);

		$this->assertSame(Http::STATUS_FORBIDDEN, $refused->getStatus());
		$this->assertArrayNotHasKey('results', $refused->getData());

		$admin = $this->controller(uid: 'root')->index(self::ORG);
		$this->assertSame(Http::STATUS_OK, $admin->getStatus());
		$this->assertCount(3, $admin->getData()['results']);
	}//end testAnotherOrganisationIsRefusedAndAnAdminIsServed()

	/**
	 * Only admins and reviewer group members of the organisation may list it; anonymous gets 401.
	 *
	 * @return void
	 */
	public function testEveryoneElseIsRefused(): void {
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller(uid: 'joe')->index(self::ORG)->getStatus(), 'A gebruik-beheerder is no reviewer.');
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller(uid: 'colleague')->index(self::ORG)->getStatus(), 'A reviewer without an active organisation reviews none.');
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller(uid: 'fa')->index('')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(uid: null)->index(self::ORG)->getStatus());
	}//end testEveryoneElseIsRefused()

	/**
	 * A failing group lookup refuses rather than lets the caller through.
	 *
	 * @return void
	 */
	public function testAFailingCheckRefuses(): void {
		$this->groupsFail = true;

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller(uid: 'fa')->index(self::ORG)->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller(uid: 'fa')->confirm('cp-colleague')->getStatus());
		$this->assertSame([], $this->patches);
	}//end testAFailingCheckRefuses()

	/**
	 * Without a configured register the list fails with 500, never with an empty success.
	 *
	 * @return void
	 */
	public function testAMissingRegisterIsAnError(): void {
		$this->configured = false;

		$response = $this->controller(uid: 'fa')->index(self::ORG);

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('The access review could not be read.', $response->getData()['message']);
	}//end testAMissingRegisterIsAnError()

	/**
	 * Keep access records today and the reviewer on the contact person through OpenRegister.
	 *
	 * @return void
	 */
	public function testKeepAccessRecordsTheReview(): void {
		$response = $this->controller(uid: 'fa')->confirm('cp-colleague');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['id' => 'cp-colleague', 'accessReviewedAt' => '2026-11-14T12:00:00+00:00', 'accessReviewedBy' => 'fa', 'due' => false],
			$response->getData()
		);
		$this->assertSame(
			[['id' => 'cp-colleague', 'data' => ['accessReviewedAt' => '2026-11-14T12:00:00+00:00', 'accessReviewedBy' => 'fa'], 'register' => 1, 'schema' => 3, 'rbac' => false]],
			$this->patches
		);

		$row = $this->controller(uid: 'fa')->index(self::ORG)->getData()['results'][0];
		$this->assertFalse($row['due'], 'After Keep access the row is no longer due.');
		$this->assertSame('fa', $row['accessReviewedBy']);
	}//end testKeepAccessRecordsTheReview()

	/**
	 * A reviewer cannot confirm, nor change, their own access.
	 *
	 * @return void
	 */
	public function testAReviewerCannotReviewThemselves(): void {
		$confirm = $this->controller(uid: 'fa')->confirm('cp-fa');
		$withdraw = $this->controller(uid: 'fa', params: ['role' => 'Functioneel-beheerder'])->withdraw('cp-fa');

		$this->assertSame(Http::STATUS_FORBIDDEN, $confirm->getStatus());
		$this->assertSame('You cannot review your own access.', $confirm->getData()['message']);
		$this->assertSame(Http::STATUS_FORBIDDEN, $withdraw->getStatus());
		$this->assertSame([], $this->patches);
	}//end testAReviewerCannotReviewThemselves()

	/**
	 * A reviewer cannot confirm someone of another organisation; an admin can.
	 *
	 * @return void
	 */
	public function testConfirmingAnotherOrganisationIsRefused(): void {
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller(uid: 'fa')->confirm('cp-anders')->getStatus());
		$this->assertSame([], $this->patches);

		$this->assertSame(Http::STATUS_OK, $this->controller(uid: 'root')->confirm('cp-anders')->getStatus());
		$this->assertSame('root', $this->patches[0]['data']['accessReviewedBy']);
	}//end testConfirmingAnotherOrganisationIsRefused()

	/**
	 * A caller who reviews nothing is refused before the contact person is even read.
	 *
	 * @return void
	 */
	public function testANonReviewerIsRefusedBeforeAnyRead(): void {
		$controller = $this->controller(uid: 'joe');
		$this->objectService->expects($this->never())->method('find');

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->confirm('cp-colleague')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(uid: null)->confirm('cp-colleague')->getStatus());
	}//end testANonReviewerIsRefusedBeforeAnyRead()

	/**
	 * An unknown contact person is 404.
	 *
	 * @return void
	 */
	public function testAnUnknownContactPersonIsNotFound(): void {
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(uid: 'fa')->confirm('cp-missing')->getStatus());
	}//end testAnUnknownContactPersonIsNotFound()

	/**
	 * Remove role takes the role off the contact person, records the review and empties its group.
	 *
	 * @return void
	 */
	public function testRemoveRoleWithdrawsTheRoleAndItsGroup(): void {
		$response = $this->controller(uid: 'fa', params: ['role' => 'functioneel-beheerder'])->withdraw('cp-colleague');

		$this->assertSame(Http::STATUS_OK, $response->getStatus(), json_encode($response->getData()));
		$this->assertSame('Functioneel-beheerder', $response->getData()['role']);
		$this->assertTrue($response->getData()['removedFromGroup']);
		$this->assertSame(
			['roles' => ['Gebruik-beheerder'], 'accessReviewedAt' => '2026-11-14T12:00:00+00:00', 'accessReviewedBy' => 'fa'],
			$this->patches[0]['data']
		);
		$this->assertSame(['fa'], $this->groups['functioneel-beheerder']);
		$this->assertSame(['joe', 'colleague'], $this->groups['gebruik-beheerder'], 'Another role group is left as it was.');
	}//end testRemoveRoleWithdrawsTheRoleAndItsGroup()

	/**
	 * A role still granted by the organisation type leaves the contact person but the group stays.
	 *
	 * @return void
	 */
	public function testARoleStillDerivedKeepsItsGroup(): void {
		$response = $this->controller(uid: 'fa', params: ['role' => 'Gebruik-beheerder'])->withdraw('cp-colleague');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertFalse($response->getData()['removedFromGroup']);
		$this->assertSame(['Functioneel-beheerder'], $this->patches[0]['data']['roles']);
		$this->assertContains('colleague', $this->groups['gebruik-beheerder']);
	}//end testARoleStillDerivedKeepsItsGroup()

	/**
	 * A role from a mapped group is refused with the group named, and nothing is written.
	 *
	 * @return void
	 */
	public function testARoleFromAMappedGroupNamesTheGroup(): void {
		$response = $this->controller(uid: 'fa', params: ['role' => 'Gebruik-raadpleger'])->withdraw('cp-colleague');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('This role comes from the group Inkoop. Change that group instead.', $response->getData()['message']);
		$this->assertSame([['type' => 'group', 'group' => 'Inkoop']], $response->getData()['sources']);
		$this->assertSame([], $this->patches);
	}//end testARoleFromAMappedGroupNamesTheGroup()

	/**
	 * A role from the organisation type alone is refused with the type named; an unheld role is 404; no role is 400.
	 *
	 * @return void
	 */
	public function testOtherWithdrawalsAreRefused(): void {
		$fromType = $this->controller(uid: 'fa', params: ['role' => 'Gebruik-beheerder'])->withdraw('cp-newcomer');
		$this->assertSame(Http::STATUS_CONFLICT, $fromType->getStatus());
		$this->assertSame('This role comes from the organisation type Municipality. Change the role mapping instead.', $fromType->getData()['message']);

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(uid: 'fa', params: ['role' => 'Aanbod-beheerder'])->withdraw('cp-newcomer')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller(uid: 'fa', params: ['role' => 'Ambtenaar'])->withdraw('cp-colleague')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller(uid: 'fa')->withdraw('cp-colleague')->getStatus());
		$this->assertSame([], $this->patches);
	}//end testOtherWithdrawalsAreRefused()

	/**
	 * Every endpoint is open to non-admins and does its own check; each has a route.
	 *
	 * @return void
	 */
	public function testTheAuthPostureAndRoutes(): void {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$names = array_column($routes['routes'], 'url', 'name');

		foreach (['index' => 'GET', 'confirm' => 'POST', 'withdraw' => 'POST'] as $method => $verb) {
			$attributes = (new ReflectionMethod(AccessReviewController::class, $method))->getAttributes(NoAdminRequired::class);
			$this->assertCount(1, $attributes, $method);
			$this->assertArrayHasKey('accessReview#' . $method, $names);
		}

		$this->assertSame('/api/access-review/{organisationUuid}', $names['accessReview#index']);
		$this->assertSame('/api/access-review/{contactPersonId}/confirm', $names['accessReview#confirm']);
		$this->assertSame('/api/access-review/{contactPersonId}/withdraw', $names['accessReview#withdraw']);
	}//end testTheAuthPostureAndRoutes()
}//end class
