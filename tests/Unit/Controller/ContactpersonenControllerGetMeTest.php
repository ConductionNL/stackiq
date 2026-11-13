<?php

/**
 * Unit tests for ContactpersonenController::getMe().
 *
 * Asserts the /me profile: the anonymous refusal, the Nextcloud fallbacks,
 * the contact-person enrichment (Dutch and English field names), the
 * active and member organisations with their slugs, and the non-fatal and
 * fatal failure paths.
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
use OCA\OpenRegister\Db\Organisation;
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
 * An organisation with the name and slug accessors the controller reads.
 */
class GetMeTestOrganisation extends Organisation {

	/**
	 * Build the organisation.
	 *
	 * @param int         $id   The id.
	 * @param string      $uuid The uuid.
	 * @param string      $name The name.
	 * @param string|null $slug The slug, null when unset.
	 */
	public function __construct(int $id, string $uuid, private string $name, private ?string $slug) {
		$this->setId($id);
		$this->setUuid($uuid);
	}//end __construct()

	/**
	 * The name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return $this->name;
	}//end getName()

	/**
	 * The slug.
	 *
	 * @return string|null
	 */
	public function getSlug(): ?string {
		return $this->slug;
	}//end getSlug()
}//end class

/**
 * Tests for the /me endpoint.
 *
 * @spec openspec/specs/contactpersonen-api/spec.md
 */
#[CoversClass(ContactpersonenController::class)]
class ContactpersonenControllerGetMeTest extends TestCase {

	/**
	 * User session mock.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Group manager mock.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * OpenRegister object service mock.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * OpenRegister organisation service mock.
	 *
	 * @var OrganisationService&MockObject
	 */
	private OrganisationService&MockObject $organisationService;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up fresh mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->userSession         = $this->createMock(IUserSession::class);
		$this->groupManager        = $this->createMock(IGroupManager::class);
		$this->objectService       = $this->createMock(ObjectServiceInterface::class);
		$this->organisationService = $this->createMock(OrganisationService::class);
		$this->logger              = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the controller.
	 *
	 * @return ContactpersonenController
	 */
	private function controller(): ContactpersonenController {
		return new ContactpersonenController(
			'stackiq',
			$this->createMock(IRequest::class),
			$this->createMock(SettingsService::class),
			$this->createMock(ContactPersonHandler::class),
			$this->createMock(ContactpersoonService::class),
			$this->createMock(IUserManager::class),
			$this->groupManager,
			$this->userSession,
			$this->createMock(ISecureRandom::class),
			$this->logger,
			objectService: $this->objectService,
			organisationService: $this->organisationService,
			organisationGuard: $this->createMock(ContactPersonOrganisationGuard::class),
		);
	}//end controller()

	/**
	 * Sign a user in.
	 *
	 * @param string      $uid   The user id.
	 * @param string|null $email The email, null when unset.
	 *
	 * @return void
	 */
	private function signIn(string $uid, ?string $email): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getEMailAddress')->willReturn($email);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * Make the contact-person search return one object with the given data.
	 *
	 * @param array $data The contact-person object data.
	 *
	 * @return void
	 */
	private function contactPerson(array $data): void {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($data);
		$this->objectService->method('searchObjectsPaginated')->willReturn(['results' => [$entity]]);
	}//end contactPerson()

	/**
	 * An anonymous caller is refused before any lookup.
	 *
	 * @return void
	 */
	public function testAnonymousCallerIsRefused(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->objectService->expects($this->never())->method('searchObjectsPaginated');
		$this->organisationService->expects($this->never())->method('getActiveOrganisation');

		$response = $this->controller()->getMe();

		$this->assertSame(401, $response->getStatus());
		$this->assertSame(['message' => 'Not authenticated'], $response->getData());
	}//end testAnonymousCallerIsRefused()

	/**
	 * Without contact person or organisations the profile is the empty shape,
	 * the email falls back to the user id, and the beheerder flag comes from
	 * the maintainer group.
	 *
	 * @return void
	 */
	public function testEmptyProfileFallsBackToUserId(): void {
		$this->signIn(uid: 'alice', email: null);
		$this->groupManager->expects($this->once())->method('isInGroup')->with('alice', 'maintainer')->willReturn(true);
		$this->objectService->expects($this->once())->method('searchObjectsPaginated')
			->with(['username' => 'alice', '_limit' => 1, '_schema' => 'contactPerson'])
			->willReturn(['results' => []]);
		$this->organisationService->method('getActiveOrganisation')->willReturn(null);
		$this->organisationService->method('getUserOrganisations')->willReturn([]);

		$response = $this->controller()->getMe();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			[
				'email' => 'alice',
				'firstName' => '',
				'middleName' => '',
				'lastName' => '',
				'role' => '',
				'organisations' => ['active' => null, 'all' => []],
				'isBeheerder' => true,
			],
			$response->getData()
		);
	}//end testEmptyProfileFallsBackToUserId()

	/**
	 * Dutch contact-person fields fill the name and role; the Nextcloud email wins.
	 *
	 * @return void
	 */
	public function testDutchContactFieldsFillProfile(): void {
		$this->signIn(uid: 'alice', email: 'alice@nc.example');
		$this->contactPerson(
			[
				'voornaam' => 'Alice',
				'tussenvoegsel' => 'van',
				'achternaam' => 'Dijk',
				'role' => 'admin',
				'e-mailadres' => 'other@example.org',
			]
		);
		$this->organisationService->method('getUserOrganisations')->willReturn([]);
		$this->groupManager->method('isInGroup')->with('alice', 'maintainer')->willReturn(false);

		$data = $this->controller()->getMe()->getData();

		$this->assertSame('Alice', $data['firstName']);
		$this->assertSame('van', $data['middleName']);
		$this->assertSame('Dijk', $data['lastName']);
		$this->assertSame('admin', $data['role']);
		$this->assertSame('alice@nc.example', $data['email']);
		$this->assertFalse($data['isBeheerder']);
	}//end testDutchContactFieldsFillProfile()

	/**
	 * English contact-person fields are used when the Dutch ones are absent.
	 *
	 * @return void
	 */
	public function testEnglishContactFieldsAreFallback(): void {
		$this->signIn(uid: 'bob', email: 'bob@nc.example');
		$this->contactPerson(['firstName' => 'Bob', 'middleName' => 'de', 'lastName' => 'Bouwer']);
		$this->organisationService->method('getUserOrganisations')->willReturn([]);

		$data = $this->controller()->getMe()->getData();

		$this->assertSame(['Bob', 'de', 'Bouwer', ''], [$data['firstName'], $data['middleName'], $data['lastName'], $data['role']]);
	}//end testEnglishContactFieldsAreFallback()

	/**
	 * A failing contact-person lookup is non-fatal and logged at debug level.
	 *
	 * @return void
	 */
	public function testContactLookupFailureIsNonFatal(): void {
		$this->signIn(uid: 'carol', email: 'carol@nc.example');
		$this->objectService->method('searchObjectsPaginated')->willThrowException(new \RuntimeException('search down'));
		$this->organisationService->method('getUserOrganisations')->willReturn([]);
		$this->logger->expects($this->once())->method('debug')->with(
			'ContactpersonenController: Could not find contactpersoon for user',
			['userId' => 'carol', 'error' => 'search down']
		);

		$response = $this->controller()->getMe();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('', $response->getData()['firstName']);
	}//end testContactLookupFailureIsNonFatal()

	/**
	 * The active and member organisations are listed, with a slug derived
	 * from the name when the organisation has none.
	 *
	 * @return void
	 */
	public function testOrganisationsAreListedWithSlugs(): void {
		$this->signIn(uid: 'dave', email: 'dave@nc.example');
		$this->objectService->method('searchObjectsPaginated')->willReturn(['results' => []]);
		$active = new GetMeTestOrganisation(id: 5, uuid: 'u-5', name: 'Gemeente Den Haag!', slug: null);
		$other  = new GetMeTestOrganisation(id: 9, uuid: 'u-9', name: 'Other Org', slug: 'custom-slug');
		$this->organisationService->method('getActiveOrganisation')->willReturn($active);
		$this->organisationService->method('getUserOrganisations')->willReturn([$active, $other]);

		$organisations = $this->controller()->getMe()->getData()['organisations'];

		$this->assertSame(
			['uuid' => 'u-5', 'name' => 'Gemeente Den Haag!', 'id' => '5', 'slug' => 'gemeente-den-haag'],
			$organisations['active']
		);
		$this->assertSame(
			[
				['uuid' => 'u-5', 'name' => 'Gemeente Den Haag!', 'id' => '5', 'slug' => 'gemeente-den-haag'],
				['uuid' => 'u-9', 'name' => 'Other Org', 'id' => '9', 'slug' => 'custom-slug'],
			],
			$organisations['all']
		);
	}//end testOrganisationsAreListedWithSlugs()

	/**
	 * A failing organisation lookup is non-fatal and logged as a warning.
	 *
	 * @return void
	 */
	public function testOrganisationLookupFailureIsNonFatal(): void {
		$this->signIn(uid: 'erin', email: 'erin@nc.example');
		$this->objectService->method('searchObjectsPaginated')->willReturn(['results' => []]);
		$this->organisationService->method('getActiveOrganisation')->willThrowException(new \RuntimeException('org down'));
		$this->logger->expects($this->once())->method('warning')->with(
			'ContactpersonenController: Could not get organisation data',
			['userId' => 'erin', 'error' => 'org down']
		);

		$response = $this->controller()->getMe();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['active' => null, 'all' => []], $response->getData()['organisations']);
	}//end testOrganisationLookupFailureIsNonFatal()

	/**
	 * A failure outside the guarded lookups answers 500.
	 *
	 * @return void
	 */
	public function testUnexpectedFailureAnswers500(): void {
		$this->signIn(uid: 'frank', email: 'frank@nc.example');
		$this->groupManager->method('isInGroup')->willThrowException(new \RuntimeException('groups down'));
		$this->logger->expects($this->once())->method('error')
			->with('ContactpersonenController: Failed to get /me data', $this->callback(fn (array $c) => $c['error'] === 'groups down'));

		$response = $this->controller()->getMe();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(
			['success' => false, 'message' => 'Failed to get user profile: groups down'],
			$response->getData()
		);
	}//end testUnexpectedFailureAnswers500()
}//end class
