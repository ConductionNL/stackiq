<?php

/**
 * Unit tests for ContactpersonenController::getContactPersonsWithUserDetailsForOrganization().
 *
 * Asserts the organisation guard in front of the read-out, the projection of
 * each returned contact person, the empty-UUID refusal and the 500 body when
 * the service throws.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/contactpersonen-api/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\Stackiq\Controller\ContactpersonenController;
use OCA\Stackiq\Service\Contactpersoon\ContactPersonOrganisationGuard;
use OCA\Stackiq\Service\ContactpersoonService;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Contact persons with user details for one organisation.
 *
 * @spec openspec/specs/contactpersonen-api/spec.md
 */
#[CoversClass(ContactpersonenController::class)]
class ContactpersonenControllerUserDetailsTest extends TestCase {

	/**
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * @var ContactpersoonService&MockObject
	 */
	private ContactpersoonService&MockObject $contactSvc;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up fresh mocks for each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->groupManager  = $this->createMock(IGroupManager::class);
		$this->userSession   = $this->createMock(IUserSession::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->contactSvc    = $this->createMock(ContactpersoonService::class);
		$this->logger        = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the controller with the current mocks.
	 *
	 * @return ContactpersonenController
	 */
	private function controller(): ContactpersonenController {
		return new ContactpersonenController(
			'stackiq',
			$this->createMock(IRequest::class),
			$this->createMock(SettingsService::class),
			$this->createMock(ContactPersonHandler::class),
			$this->contactSvc,
			$this->createMock(IUserManager::class),
			$this->groupManager,
			$this->userSession,
			$this->createMock(ISecureRandom::class),
			$this->logger,
			objectService: $this->objectService,
			organisationService: $this->createMock(OrganisationService::class),
			organisationGuard: $this->createMock(ContactPersonOrganisationGuard::class),
		);
	}//end controller()

	/**
	 * Sign a caller in.
	 *
	 * @param string $uid     The caller uid.
	 * @param bool   $isAdmin Whether the caller is an instance admin.
	 *
	 * @return void
	 */
	private function signIn(string $uid, bool $isAdmin): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with($uid)->willReturn($isAdmin);
	}//end signIn()

	/**
	 * A contact person as the service returns it.
	 *
	 * @param int    $id   The object id.
	 * @param string $uuid The object uuid.
	 *
	 * @return object
	 */
	private function contactPerson(int $id, string $uuid): object {
		return new class($id, $uuid) {
			/**
			 * @param int    $id   The object id.
			 * @param string $uuid The object uuid.
			 */
			public function __construct(private int $id, private string $uuid) {
			}

			/** @return int */
			public function getId(): int {
				return $this->id;
			}

			/** @return string */
			public function getUuid(): string {
				return $this->uuid;
			}

			/** @return array<string,mixed> */
			public function getObject(): array {
				return ['email' => $this->uuid . '@example.org', 'username' => $this->uuid];
			}

			/** @return string */
			public function getRegister(): string {
				return 'reg-1';
			}

			/** @return string */
			public function getSchema(): string {
				return 'contactPerson';
			}

			/** @return string */
			public function getCreated(): string {
				return '2026-01-01T00:00:00+00:00';
			}

			/** @return string */
			public function getModified(): string {
				return '2026-02-01T00:00:00+00:00';
			}
		};
	}//end contactPerson()

	/**
	 * An anonymous caller is refused before the service is asked.
	 *
	 * @return void
	 */
	public function testAnonymousCallerIsRefused(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->contactSvc->expects($this->never())->method('getContactPersonsWithUserDetailsForOrganization');

		$response = $this->controller()->getContactPersonsWithUserDetailsForOrganization('org-1');

		$this->assertSame(401, $response->getStatus());
		$this->assertSame(['message' => 'Not authenticated'], $response->getData());
	}//end testAnonymousCallerIsRefused()

	/**
	 * An admin gets every contact person projected to its stored fields.
	 *
	 * @return void
	 */
	public function testAdminGetsProjectedContactPersons(): void {
		$this->signIn(uid: 'admin', isAdmin: true);
		$this->objectService->expects($this->never())->method('searchObjectsPaginated');
		$this->contactSvc->expects($this->once())->method('getContactPersonsWithUserDetailsForOrganization')
			->with('org-1')
			->willReturn([$this->contactPerson(id: 4, uuid: 'c-4'), $this->contactPerson(id: 5, uuid: 'c-5')]);

		$response = $this->controller()->getContactPersonsWithUserDetailsForOrganization('org-1');
		$data     = $response->getData();

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($data['success']);
		$this->assertSame(2, $data['count']);
		$this->assertSame('org-1', $data['organizationUuid']);
		$this->assertSame(
			[
				'id' => 4,
				'uuid' => 'c-4',
				'object' => ['email' => 'c-4@example.org', 'username' => 'c-4'],
				'register' => 'reg-1',
				'schema' => 'contactPerson',
				'created' => '2026-01-01T00:00:00+00:00',
				'modified' => '2026-02-01T00:00:00+00:00',
			],
			$data['data'][0]
		);
		$this->assertSame('c-5', $data['data'][1]['uuid']);
	}//end testAdminGetsProjectedContactPersons()

	/**
	 * A non-admin may read their own organisation, resolved from a nested
	 * organisation reference on their contact person.
	 *
	 * @return void
	 */
	public function testNonAdminReadsOwnOrganisation(): void {
		$this->signIn(uid: 'jan', isAdmin: false);
		$own = $this->createMock(ObjectEntity::class);
		$own->method('getObject')->willReturn(['organisation' => ['@self' => ['id' => 'org-1']]]);
		$this->objectService->expects($this->once())->method('searchObjectsPaginated')
			->with(['username' => 'jan', '_limit' => 1, '_schema' => 'contactPerson'])
			->willReturn(['results' => [$own], 'total' => 1]);
		$this->contactSvc->method('getContactPersonsWithUserDetailsForOrganization')->willReturn([]);

		$response = $this->controller()->getContactPersonsWithUserDetailsForOrganization(' org-1 ');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(0, $response->getData()['count']);
		$this->assertSame([], $response->getData()['data']);
	}//end testNonAdminReadsOwnOrganisation()

	/**
	 * A non-admin is refused another organisation and the service is not asked.
	 *
	 * @return void
	 */
	public function testNonAdminIsRefusedForeignOrganisation(): void {
		$this->signIn(uid: 'jan', isAdmin: false);
		$own = $this->createMock(ObjectEntity::class);
		$own->method('getObject')->willReturn(['organization' => 'org-1']);
		$this->objectService->method('searchObjectsPaginated')->willReturn(['results' => [$own], 'total' => 1]);
		$this->contactSvc->expects($this->never())->method('getContactPersonsWithUserDetailsForOrganization');
		$this->logger->expects($this->once())->method('warning')
			->with(
				'ContactpersonenController: cross-organisation contact read denied',
				['callerUid' => 'jan', 'callerOrg' => 'org-1', 'requestedOrg' => 'org-2']
			);

		$response = $this->controller()->getContactPersonsWithUserDetailsForOrganization('org-2');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(
			['success' => false, 'message' => 'Forbidden: you may only read contact persons of your own organisation'],
			$response->getData()
		);
	}//end testNonAdminIsRefusedForeignOrganisation()

	/**
	 * A non-admin is refused when their organisation lookup throws (fail closed).
	 *
	 * @return void
	 */
	public function testNonAdminIsRefusedWhenOrganisationLookupThrows(): void {
		$this->signIn(uid: 'jan', isAdmin: false);
		$this->objectService->method('searchObjectsPaginated')->willThrowException(new \RuntimeException('db gone'));
		$this->contactSvc->expects($this->never())->method('getContactPersonsWithUserDetailsForOrganization');

		$response = $this->controller()->getContactPersonsWithUserDetailsForOrganization('org-1');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(
			['success' => false, 'message' => 'Forbidden: organisation scope could not be verified'],
			$response->getData()
		);
	}//end testNonAdminIsRefusedWhenOrganisationLookupThrows()

	/**
	 * A non-admin asking for no organisation is refused with 403.
	 *
	 * @return void
	 */
	public function testNonAdminIsRefusedEmptyOrganisation(): void {
		$this->signIn(uid: 'jan', isAdmin: false);
		$this->objectService->expects($this->never())->method('searchObjectsPaginated');

		$response = $this->controller()->getContactPersonsWithUserDetailsForOrganization('  ');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['success' => false, 'message' => 'Forbidden: an organisation is required'], $response->getData());
	}//end testNonAdminIsRefusedEmptyOrganisation()

	/**
	 * An admin asking for an empty organisation UUID gets 400.
	 *
	 * @return void
	 */
	public function testAdminWithEmptyOrganisationGets400(): void {
		$this->signIn(uid: 'admin', isAdmin: true);
		$this->contactSvc->expects($this->never())->method('getContactPersonsWithUserDetailsForOrganization');

		$response = $this->controller()->getContactPersonsWithUserDetailsForOrganization('');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['success' => false, 'message' => 'Organization UUID is required'], $response->getData());
	}//end testAdminWithEmptyOrganisationGets400()

	/**
	 * A failing service answers 500 with its message and logs the organisation.
	 *
	 * @return void
	 */
	public function testServiceFailureAnswers500(): void {
		$this->signIn(uid: 'admin', isAdmin: true);
		$this->contactSvc->method('getContactPersonsWithUserDetailsForOrganization')
			->willThrowException(new \RuntimeException('register missing'));
		$this->logger->expects($this->once())->method('error')
			->with(
				'ContactpersonenController: Failed to get contact persons with user details for organization',
				$this->callback(
					static fn (array $context): bool => $context['organizationUuid'] === 'org-1'
						&& $context['error'] === 'register missing'
				)
			);

		$response = $this->controller()->getContactPersonsWithUserDetailsForOrganization('org-1');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(
			['success' => false, 'message' => 'Failed to get contact persons with user details: register missing'],
			$response->getData()
		);
	}//end testServiceFailureAnswers500()
}//end class
