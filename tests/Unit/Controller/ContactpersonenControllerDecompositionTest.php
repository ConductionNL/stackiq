<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction b.v. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Controller;

use OCA\SoftwareCatalog\Controller\ContactpersonenController;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Unit tests for the W30 ContactpersonenController decomposition helpers
 * (validateConvertToUserPermission, normaliseContactDataForPersist,
 * projectCatalogGroupsForUser).
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-5
 */
class ContactpersonenControllerDecompositionTest extends TestCase {
	/**
	 * Build a ContactpersonenController without invoking the constructor and
	 * inject the minimal collaborators each helper needs via Reflection.
	 */
	private function makeController(array $collaborators): ContactpersonenController {
		$reflection = new ReflectionClass(ContactpersonenController::class);
		/** @var ContactpersonenController $controller */
		$controller = $reflection->newInstanceWithoutConstructor();
		foreach ($collaborators as $property => $value) {
			$prop = $reflection->getProperty($property);
			$prop->setAccessible(true);
			$prop->setValue($controller, $value);
		}

		return $controller;
	}

	/**
	 * Invoke a private method via Reflection.
	 *
	 * @return mixed
	 */
	private function callPrivate(ContactpersonenController $controller, string $method, array $args = []) {
		$ref = new \ReflectionMethod($controller, $method);
		$ref->setAccessible(true);
		return $ref->invokeArgs($controller, $args);
	}

	public function testValidateConvertToUserPermissionReturns401WhenAnonymous(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$controller = $this->makeController([
			'userSession' => $userSession,
			'groupManager' => $this->createMock(IGroupManager::class),
		]);

		$response = $this->callPrivate($controller, 'validateConvertToUserPermission');
		$this->assertNotNull($response);
		$this->assertSame(401, $response->getStatus());
	}

	public function testValidateConvertToUserPermissionReturns403WhenNeitherAdminNorOrgAdmin(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->method('isInGroup')->willReturn(false);

		$controller = $this->makeController([
			'userSession' => $userSession,
			'groupManager' => $groupManager,
		]);

		$response = $this->callPrivate($controller, 'validateConvertToUserPermission');
		$this->assertNotNull($response);
		$this->assertSame(403, $response->getStatus());
	}

	public function testValidateConvertToUserPermissionPassesForAdmin(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(true);

		$controller = $this->makeController([
			'userSession' => $userSession,
			'groupManager' => $groupManager,
		]);

		$this->assertNull($this->callPrivate($controller, 'validateConvertToUserPermission'));
	}

	public function testValidateConvertToUserPermissionPassesForOrgAdmin(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('maintainer');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $gid): bool => $gid === 'gebruik-beheerder'
		);

		$controller = $this->makeController([
			'userSession' => $userSession,
			'groupManager' => $groupManager,
		]);

		$this->assertNull($this->callPrivate($controller, 'validateConvertToUserPermission'));
	}

	public function testNormaliseContactDataCoercesNonStringFieldsAndNullsOrgUuids(): void {
		$controller = $this->makeController([
			'logger' => $this->createMock(LoggerInterface::class),
		]);

		$input = [
			'voornaam' => 'Alice',
			'achternaam' => 12345, // non-string -> coerce
			'telefoonnummer' => true,  // non-string -> coerce
			'organization' => 'org-uuid-123',
			'organisation' => 'legacy-org-uuid',
			'unrelated' => ['keep' => 'as-is'],
		];

		$output = $this->callPrivate($controller, 'normaliseContactDataForPersist', [$input]);

		$this->assertSame('Alice', $output['voornaam']);
		$this->assertSame('12345', $output['achternaam']);
		$this->assertSame('1', $output['telefoonnummer']);
		$this->assertNull($output['organization']);
		$this->assertNull($output['organisation']);
		$this->assertSame(['keep' => 'as-is'], $output['unrelated']);
	}

	public function testNormaliseContactDataLeavesNonStringOrgUntouched(): void {
		$controller = $this->makeController([
			'logger' => $this->createMock(LoggerInterface::class),
		]);

		$input = ['organization' => null, 'organisation' => null];
		$output = $this->callPrivate($controller, 'normaliseContactDataForPersist', [$input]);
		$this->assertNull($output['organization']);
		$this->assertNull($output['organisation']);
	}

	public function testBuildEmptyMeResponseShape(): void {
		$controller = $this->makeController([]);
		$result = $this->callPrivate($controller, 'buildEmptyMeResponse', ['admin@example.org']);

		$this->assertSame('admin@example.org', $result['email']);
		$this->assertSame('', $result['firstName']);
		$this->assertSame('', $result['middleName']);
		$this->assertSame('', $result['lastName']);
		$this->assertSame('', $result['role']);
		$this->assertNull($result['organisations']['active']);
		$this->assertSame([], $result['organisations']['all']);
		$this->assertFalse($result['isBeheerder']);
	}

	public function testProjectCatalogGroupsKeepsOnlyCatalogGroups(): void {
		$user = $this->createMock(IUser::class);

		$group1 = $this->createMock(IGroup::class);
		$group1->method('getGID')->willReturn('gebruik-beheerder');
		$group2 = $this->createMock(IGroup::class);
		$group2->method('getGID')->willReturn('admin');
		$group3 = $this->createMock(IGroup::class);
		$group3->method('getGID')->willReturn('aanbod-beheerder');
		$group4 = $this->createMock(IGroup::class);
		$group4->method('getGID')->willReturn('some-other');

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroups')->willReturn([$group1, $group2, $group3, $group4]);

		$controller = $this->makeController([
			'groupManager' => $groupManager,
		]);

		$result = $this->callPrivate($controller, 'projectCatalogGroupsForUser', [$user]);
		$this->assertSame(['gebruik-beheerder', 'aanbod-beheerder'], $result);
	}
}
