<?php

/**
 * Regression tests for ContactpersonenController::updateUserGroups.
 *
 * Covers SB1: cross-tenant privilege-escalation fix — an org-admin in tenant A
 * must NOT be able to update groups for a user in tenant B.
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Controller;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\SoftwareCatalog\Controller\ContactpersonenController;
use OCA\SoftwareCatalog\Service\ContactpersoonService;
use OCA\SoftwareCatalog\Service\SettingsService;
use OCA\SoftwareCatalog\Service\SoftwareCatalogue\ContactPersonHandler;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for updateUserGroups cross-tenant scope enforcement (SB1).
 *
 * @category Test
 * @package  OCA\SoftwareCatalog\Tests\Unit\Controller
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://codeberg.org/Conduction/SoftwareCatalog
 */
class ContactpersonenControllerUpdateUserGroupsTest extends TestCase {

	/** @var IUserManager|MockObject */
	private IUserManager|MockObject $userManager;

	/** @var IGroupManager|MockObject */
	private IGroupManager|MockObject $groupManager;

	/** @var IUserSession|MockObject */
	private IUserSession|MockObject $userSession;

	/** @var ContainerInterface|MockObject */
	private ContainerInterface|MockObject $container;

	/** @var ObjectServiceInterface|MockObject */
	private ObjectServiceInterface|MockObject $objectService;

	/** @var LoggerInterface|MockObject */
	private LoggerInterface|MockObject $logger;

	private ContactpersonenController $controller;

	/**
	 * Set up mocks and the controller instance.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->container = $this->createMock(ContainerInterface::class);

		$this->container
			->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($this->objectService);

		$this->controller = new ContactpersonenController(
			'softwarecatalog',
			$this->createMock(IRequest::class),
			$this->createMock(SettingsService::class),
			$this->createMock(ContactPersonHandler::class),
			$this->createMock(ContactpersoonService::class),
			$this->userManager,
			$this->groupManager,
			$this->userSession,
			$this->container,
			$this->createMock(ISecureRandom::class),
			$this->logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
			magicMapper: $this->createMock(MagicMapper::class),
			organisationService: $this->createMock(OrganisationService::class),
		);

	}//end setUp()

	/**
	 * Build a stub ObjectEntity that returns a given organisation UUID from getObject().
	 *
	 * @param string $organisationUuid The organisation UUID to embed in the object data.
	 *
	 * @return ObjectEntity
	 */
	private function makeContactPersonEntity(string $organisationUuid): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn(['organisation' => $organisationUuid]);
		return $entity;
	}//end makeContactpersoonEntity()

	/**
	 * SB1-regression: org-admin in tenant A must be denied when target user is in tenant B.
	 *
	 * The caller belongs to org-uuid-A; the target user belongs to org-uuid-B.
	 * Expected: HTTP 403.
	 *
	 * @return void
	 */
	public function testCrossTenantUpdateDenied(): void {
		$callerUid = 'orgadmin@a.nl';
		$targetUid = 'user@b.nl';
		$callerOrgUuid = 'org-uuid-A';
		$targetOrgUuid = 'org-uuid-B';

		// Caller is authenticated.
		$callerUser = $this->createMock(IUser::class);
		$callerUser->method('getUID')->willReturn($callerUid);
		$this->userSession->method('getUser')->willReturn($callerUser);

		// Caller is org-admin (gebruik-beheerder), NOT full admin.
		$this->groupManager
			->method('isAdmin')
			->with($callerUid)
			->willReturn(false);
		$this->groupManager
			->method('isInGroup')
			->willReturnMap(
				[
					[$callerUid, 'gebruik-beheerder', true],
					[$callerUid, 'aanbod-beheerder', false],
				]
			);

		// Target user exists in Nextcloud.
		$targetUser = $this->createMock(IUser::class);
		$targetUser->method('getUID')->willReturn($targetUid);
		$this->userManager->method('get')->with($targetUid)->willReturn($targetUser);

		// ObjectService returns contactpersonen for target and caller.
		$targetContactPerson = $this->makeContactPersonEntity($targetOrgUuid);
		$callerContactPerson = $this->makeContactPersonEntity($callerOrgUuid);

		$this->objectService
			->method('searchObjectsPaginated')
			->willReturnCallback(
				function (array $query) use ($targetUid, $callerUid, $targetContactPerson, $callerContactPerson): array {
					if (($query['username'] ?? '') === $targetUid) {
						return ['results' => [$targetContactPerson]];
					}

					if (($query['username'] ?? '') === $callerUid) {
						return ['results' => [$callerContactPerson]];
					}

					return ['results' => []];
				}
			);

		$response = $this->controller->updateUserGroups($targetUid, ['gebruik-raadpleger']);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);

	}//end testCrossTenantUpdateDenied()

	/**
	 * SB1-regression: org-admin in tenant A CAN update users in the same tenant.
	 *
	 * Both caller and target belong to org-uuid-A.
	 * Expected: the update proceeds (HTTP 200).
	 *
	 * @return void
	 */
	public function testSameTenantUpdateAllowed(): void {
		$callerUid = 'orgadmin@a.nl';
		$targetUid = 'user@a.nl';
		$sharedOrgUuid = 'org-uuid-A';

		$callerUser = $this->createMock(IUser::class);
		$callerUser->method('getUID')->willReturn($callerUid);
		$this->userSession->method('getUser')->willReturn($callerUser);

		$this->groupManager->method('isAdmin')->with($callerUid)->willReturn(false);
		$this->groupManager
			->method('isInGroup')
			->willReturnMap(
				[
					[$callerUid, 'gebruik-beheerder', true],
					[$callerUid, 'aanbod-beheerder', false],
				]
			);

		$targetUser = $this->createMock(IUser::class);
		$targetUser->method('getUID')->willReturn($targetUid);
		$this->userManager->method('get')->with($targetUid)->willReturn($targetUser);

		// Both belong to the same organisation.
		$sharedContactPerson = $this->makeContactPersonEntity($sharedOrgUuid);

		$this->objectService
			->method('searchObjectsPaginated')
			->willReturn(['results' => [$sharedContactPerson]]);

		// getUserGroups returns an empty array so no group changes are made.
		$this->groupManager->method('getUserGroups')->willReturn([]);

		$response = $this->controller->updateUserGroups($targetUid, []);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);

	}//end testSameTenantUpdateAllowed()

	/**
	 * SB1-regression: full admin bypasses cross-tenant check.
	 *
	 * Expected: update is allowed even if orgs differ.
	 *
	 * @return void
	 */
	public function testFullAdminBypassesTenantCheck(): void {
		$adminUid = 'admin';
		$targetUid = 'user@b.nl';

		$adminUser = $this->createMock(IUser::class);
		$adminUser->method('getUID')->willReturn($adminUid);
		$this->userSession->method('getUser')->willReturn($adminUser);

		// isAdmin returns true for full admin.
		$this->groupManager->method('isAdmin')->with($adminUid)->willReturn(true);
		$this->groupManager->method('isInGroup')->willReturn(false);

		$targetUser = $this->createMock(IUser::class);
		$targetUser->method('getUID')->willReturn($targetUid);
		$this->userManager->method('get')->with($targetUid)->willReturn($targetUser);

		// ObjectService should NOT be called for org lookup when full admin.
		$this->objectService->expects($this->never())->method('searchObjectsPaginated');

		$this->groupManager->method('getUserGroups')->willReturn([]);

		$response = $this->controller->updateUserGroups($targetUid, []);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);

	}//end testFullAdminBypassesTenantCheck()

}//end class
