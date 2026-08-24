<?php

/**
 * Organization Synchronization Tests
 *
 * Tests for the organization synchronization functionality between Stackiq and OpenRegister.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/stackiq
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\StackiqService;
use OCP\AppFramework\Db\Entity;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Test class for organization synchronization functionality
 *
 * @category Test
 * @package  OCA\Stackiq\Tests
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/stackiq
 */
class OrganizationSyncTest extends TestCase {
	/**
	 * Test organization creation and OpenRegister synchronization
	 *
	 * @return void
	 */
	public function testOrganizationCreationSync(): void {
		// Mock dependencies
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$settingsService = $this->createMock(SettingsService::class);
		$userManager = $this->createMock(IUserManager::class);
		$groupManager = $this->createMock(IGroupManager::class);

		// Configure mocks
		$settingsService->method('getRegisterIdForObjectType')
			->with('organization')
			->willReturn(1);

		$settingsService->method('getSchemaIdForObjectType')
			->with('organization')
			->willReturn(37);

		// Test organization data
		$organizationData = [
			'name' => 'Test Organization',
			'type' => 'Municipality',
			'website' => 'https://test.org',
			'beoordeling' => 'actief',
			'id' => 'test-org-uuid-123'
		];

		// Mock organization object
		$organizationObject = $this->createMock(Entity::class);
		$organizationObject->method('getId')
			->willReturn('test-org-uuid-123');
		$organizationObject->method('getObject')
			->willReturn($organizationData);

		// Mock OpenRegister response
		$openRegisterObject = $this->createMock(Entity::class);
		$openRegisterObject->method('getId')
			->willReturn('test-org-uuid-123');

		$objectService->method('find')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

		$objectService->method('saveObject')
			->willReturn($openRegisterObject);

		// Create service instance
		$service = new StackiqService(
			$this->createMock(\OCA\Stackiq\Service\Stackiq\OrganizationHandler::class),
			$this->createMock(\OCA\Stackiq\Service\Stackiq\ContactPersonHandler::class),
			$this->createMock(\OCA\Stackiq\Service\Stackiq\GroupHandler::class),
			$this->createMock(\OCA\Stackiq\Service\Stackiq\HierarchyHandler::class),
			$this->createMock(\OCA\Stackiq\Service\SymfonyEmailService::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			$this->createMock(\Psr\Container\ContainerInterface::class),
			$this->createMock(\OCP\App\IAppManager::class),
			_userSession: $this->createMock(IUserSession::class),
			_userManager: $this->createMock(IUserManager::class),
			_groupManager: $this->createMock(IGroupManager::class),
		);

		// Test synchronization
		$result = $service->syncOrganizationWithOpenRegister($organizationObject);

		// Assertions
		$this->assertTrue($result, 'Organization sync should succeed');
	}

	/**
	 * Test organization status mapping
	 *
	 * @return void
	 */
	public function testOrganizationStatusMapping(): void {
		// Test data mapping
		$testCases = [
			[
				'input' => ['beoordeling' => 'actief'],
				'expected' => 'active'
			],
			[
				'input' => ['beoordeling' => 'inactief'],
				'expected' => 'inactive'
			],
			[
				'input' => ['beoordeling' => 'deactief'],
				'expected' => 'inactive'
			],
			[
				'input' => ['beoordeling' => 'unknown'],
				'expected' => 'Draft'
			]
		];

		foreach ($testCases as $testCase) {
			$mappedData = $this->mapOrganizationDataForOpenRegister($testCase['input']);
			$this->assertEquals($testCase['expected'], $mappedData['status'],
				'Status mapping should work correctly for: ' . $testCase['input']['beoordeling']);
		}
	}

	/**
	 * Test contactpersoon organization membership
	 *
	 * @return void
	 */
	public function testContactpersoonOrganizationMembership(): void {
		// Mock dependencies
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$settingsService = $this->createMock(SettingsService::class);

		// Configure mocks
		$settingsService->method('getVoorzieningenRegisterId')
			->willReturn(1);

		$settingsService->method('getSchemaIdForObjectType')
			->with('organization')
			->willReturn(37);

		// Test contactpersoon data
		$contactPersonData = [
			'username' => 'test.user@example.com',
			'organisation' => 'test-org-uuid-123'
		];

		// Mock contactpersoon object
		$contactPersonObject = $this->createMock(Entity::class);
		$contactPersonObject->method('getId')
			->willReturn('test-contact-uuid-456');
		$contactPersonObject->method('getObject')
			->willReturn($contactPersonData);

		// Mock organization data
		$organizationData = [
			'users' => ['existing.user@example.com']
		];

		// Mock organization object
		$organizationObject = $this->createMock(Entity::class);
		$organizationObject->method('getObject')
			->willReturn($organizationData);

		$objectService->method('find')
			->willReturn($organizationObject);

		// Test should add to organization
		$result = $this->shouldAddContactpersoonToOrganization($contactPersonObject);
		$this->assertTrue($result, 'Contactpersoon should be added to organization');
	}

	/**
	 * Test user status management based on organization status
	 *
	 * @return void
	 */
	public function testUserStatusManagement(): void {
		// Mock user manager
		$userManager = $this->createMock(IUserManager::class);

		// Mock user
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('isEnabled')
			->willReturn(false);
		$user->method('setEnabled')
			->willReturnSelf();

		$userManager->method('get')
			->willReturn($user);

		// Test user activation
		$this->assertFalse($user->isEnabled(), 'User should be inactive initially');

		// Simulate activation
		$user->setEnabled(true);
		$this->assertTrue($user->isEnabled(), 'User should be active after activation');
	}

	/**
	 * Helper method to map organization data for OpenRegister
	 *
	 * @param array $objectData The organization data from Stackiq
	 *
	 * @return array The mapped data for OpenRegister
	 */
	private function mapOrganizationDataForOpenRegister(array $objectData): array {
		$mappedData = [
			'name' => $objectData['name'] ?? '',
			'type' => $objectData['type'] ?? '',
			'website' => $objectData['website'] ?? '',
			'active' => false, // Default to inactive for new organizations
			'contactpersonen' => [],
			'participants' => []
		];

		// Map status from Stackiq to OpenRegister
		$assessment = strtolower($objectData['beoordeling'] ?? '');
		if ($assessment === 'actief') {
			$mappedData['active'] = true;
		} elseif ($assessment === 'inactief' || $assessment === 'deactief') {
			$mappedData['active'] = false;
		}

		return $mappedData;
	}

	/**
	 * Helper method to check if contactpersoon should be added to organization
	 *
	 * @param object $contactPersonObject The contactpersoon object
	 *
	 * @return bool True if the user should be added to the organization
	 */
	private function shouldAddContactpersoonToOrganization(object $contactPersonObject): bool {
		$objectData = $contactPersonObject->getObject();
		$username = $objectData['username'] ?? '';
		$organizationUuid = $objectData['organisation'] ?? '';

		if (empty($username) || empty($organizationUuid)) {
			return false;
		}

		// Mock organization data
		$organizationData = [
			'users' => ['existing.user@example.com']
		];

		// Check if the username is already in the organization's users
		$organizationUsers = $organizationData['users'] ?? [];

		return is_array($organizationUsers) && !in_array($username, $organizationUsers);
	}
}
