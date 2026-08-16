<?php

/**
 * Regression tests for the GH#459 contact-person organisation scope.
 *
 * `GET /api/contactpersonen/organisation/{organisationId}` was
 * `@NoAdminRequired` with "is somebody logged in" as its only guard, and
 * returned each contact's Nextcloud username, full group membership and
 * enabled/disabled state for a caller-chosen organisation. Sibling methods on
 * the same controller that return the same account data (`getUserInfo`,
 * `getBulkUserInfo`, `updateUserGroups`) all refuse that to non-admins.
 *
 * These tests assert on the ITEM — the foreign organisation's username must be
 * absent from the response body — not merely on the HTTP envelope.
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
 * GH#459 — organisation scope on the contact-person read-outs.
 *
 * @category Test
 * @package  OCA\SoftwareCatalog\Tests\Unit\Controller
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://codeberg.org/Conduction/SoftwareCatalog
 */
class ContactpersonenControllerOrganisationScopeTest extends TestCase {

	private const CALLER_ORG = 'org-uuid-A';

	private const FOREIGN_ORG = 'org-uuid-B';

	private const FOREIGN_USERNAME = 'victim@b.example';

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

	/** @var ContactpersoonService|MockObject */
	private ContactpersoonService|MockObject $contactSvc;

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
		$this->contactSvc = $this->createMock(ContactpersoonService::class);
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
			$this->contactSvc,
			$this->userManager,
			$this->groupManager,
			$this->userSession,
			$this->container,
			$this->createMock(ISecureRandom::class),
			$this->logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
			organisationService: $this->createMock(OrganisationService::class),
		);

	}//end setUp()

	/**
	 * Authenticate a caller with the given uid and admin flag.
	 *
	 * @param string $uid The caller uid.
	 * @param bool $isAdmin Whether the caller is an instance admin.
	 *
	 * @return void
	 */
	private function authenticate(string $uid, bool $isAdmin): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn($isAdmin);

	}//end authenticate()

	/**
	 * Build a contactpersoon entity carrying an organisation and a username.
	 *
	 * @param string $organisation The organisation reference.
	 * @param string|null $username The Nextcloud username, when any.
	 *
	 * @return ObjectEntity
	 */
	private function makeContact(string $organisation, ?string $username = null): ObjectEntity {
		$data = ['organization' => $organisation];
		if ($username !== null) {
			$data['username'] = $username;
		}

		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($data);
		$entity->method('getId')->willReturn(1);
		$entity->method('getUuid')->willReturn('contact-uuid');

		return $entity;
	}//end makeContact()

	/**
	 * A non-admin asking for somebody else's organisation is refused.
	 *
	 * @return void
	 */
	public function testForeignOrganisationIsForbiddenForNonAdmin(): void {
		$this->authenticate(uid: 'plain@a.example', isAdmin: false);

		// The caller's own contactpersoon resolves to organisation A.
		$this->objectService
			->method('searchObjectsPaginated')
			->willReturn(['results' => [$this->makeContact(organisation: self::CALLER_ORG)], 'total' => 1]);

		$response = $this->controller->getContactpersonen(self::FOREIGN_ORG);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testForeignOrganisationIsForbiddenForNonAdmin()

	/**
	 * A caller whose own organisation cannot be resolved is refused (fail closed).
	 *
	 * @return void
	 */
	public function testUnresolvableCallerOrganisationIsForbidden(): void {
		$this->authenticate(uid: 'orphan@example', isAdmin: false);

		$this->objectService
			->method('searchObjectsPaginated')
			->willReturn(['results' => [], 'total' => 0]);

		$response = $this->controller->getContactpersonen(self::CALLER_ORG);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testUnresolvableCallerOrganisationIsForbidden()

	/**
	 * A non-admin reading their OWN organisation still succeeds.
	 *
	 * @return void
	 */
	public function testOwnOrganisationIsAllowedForNonAdmin(): void {
		$this->authenticate(uid: 'plain@a.example', isAdmin: false);

		$this->objectService
			->method('searchObjectsPaginated')
			->willReturn(['results' => [$this->makeContact(organisation: self::CALLER_ORG)], 'total' => 1]);

		$response = $this->controller->getContactpersonen(self::CALLER_ORG);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);

	}//end testOwnOrganisationIsAllowedForNonAdmin()

	/**
	 * A record from another organisation is dropped from the body.
	 *
	 * This is the ITEM-level assertion: OpenRegister is made to return an
	 * UNSCOPED result set (the exact failure mode of a filter that does not
	 * scope), and the foreign username must not appear in the response.
	 *
	 * @return void
	 */
	public function testForeignRecordIsAbsentFromTheBodyWhenTheQueryDoesNotScope(): void {
		$this->authenticate(uid: 'admin', isAdmin: true);

		$mine = $this->makeContact(organisation: self::CALLER_ORG, username: 'me@a.example');
		$foreign = $this->makeContact(organisation: self::FOREIGN_ORG, username: self::FOREIGN_USERNAME);

		$this->objectService
			->method('searchObjectsPaginated')
			->willReturn(['results' => [$mine, $foreign], 'total' => 2]);

		$this->userManager->method('get')->willReturn(null);

		$response = $this->controller->getContactpersonen(self::CALLER_ORG);
		$body = json_encode($response->getData());

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertStringNotContainsString(
			self::FOREIGN_USERNAME,
			(string)$body,
			'a contact belonging to another organisation leaked into the response body'
		);
		$this->assertStringNotContainsString(self::FOREIGN_ORG, (string)$body);
		$this->assertCount(1, $response->getData()['contactpersonen']);
		$this->assertSame(1, $response->getData()['total']);

	}//end testForeignRecordIsAbsentFromTheBodyWhenTheQueryDoesNotScope()

	/**
	 * An instance admin may read any organisation.
	 *
	 * @return void
	 */
	public function testAdminMayReadAnyOrganisation(): void {
		$this->authenticate(uid: 'admin', isAdmin: true);

		$this->objectService
			->method('searchObjectsPaginated')
			->willReturn(['results' => [$this->makeContact(organisation: self::FOREIGN_ORG)], 'total' => 1]);

		$this->userManager->method('get')->willReturn(null);

		$response = $this->controller->getContactpersonen(self::FOREIGN_ORG);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData()['contactpersonen']);

	}//end testAdminMayReadAnyOrganisation()

	/**
	 * The sibling with-user-details route carries the same guard.
	 *
	 * @return void
	 */
	public function testSiblingWithUserDetailsRouteIsAlsoScoped(): void {
		$this->authenticate(uid: 'plain@a.example', isAdmin: false);

		$this->objectService
			->method('searchObjectsPaginated')
			->willReturn(['results' => [$this->makeContact(organisation: self::CALLER_ORG)], 'total' => 1]);

		// If the guard is absent the service is reached; it must not be.
		$this->contactSvc
			->expects($this->never())
			->method('getContactPersonsWithUserDetailsForOrganization');

		$response = $this->controller->getContactPersonsWithUserDetailsForOrganization(self::FOREIGN_ORG);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testSiblingWithUserDetailsRouteIsAlsoScoped()

	/**
	 * A nested related-object organisation reference is understood.
	 *
	 * `organisatie` is declared as a related object in the register, so the
	 * stored value may be an envelope rather than a bare UUID. If the
	 * normaliser missed that shape, a legitimate member would be refused.
	 *
	 * @return void
	 */
	public function testNestedOrganisationReferenceIsResolved(): void {
		$this->authenticate(uid: 'plain@a.example', isAdmin: false);

		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn(['organization' => ['@self' => ['uuid' => self::CALLER_ORG]]]);
		$entity->method('getId')->willReturn(2);
		$entity->method('getUuid')->willReturn('contact-uuid-2');

		$this->objectService
			->method('searchObjectsPaginated')
			->willReturn(['results' => [$entity], 'total' => 1]);

		$this->userManager->method('get')->willReturn(null);

		$response = $this->controller->getContactpersonen(self::CALLER_ORG);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData()['contactpersonen']);

	}//end testNestedOrganisationReferenceIsResolved()

}//end class
