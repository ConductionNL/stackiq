<?php

/**
 * Unit tests for ContactNamesController.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/application-usage-pages/spec.md#requirement-req-uap-003-a-usage-names-a-business-owner-and-a-technical-owner
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Controller\ContactNamesController;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\StackiqContactSyncService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The names endpoint answers only a signed-in user, reads the contact persons
 * with that user's rights, and names only what it was asked for.
 *
 * @spec openspec/specs/application-usage-pages/spec.md#requirement-req-uap-003-a-usage-names-a-business-owner-and-a-technical-owner
 */
class ContactNamesControllerTest extends TestCase {

	private const A = '00000000-0000-0000-0000-00000000000a';
	private const B = '00000000-0000-0000-0000-00000000000b';
	private const C = '00000000-0000-0000-0000-00000000000c';

	/** @var ObjectServiceInterface&MockObject */
	private ObjectServiceInterface&MockObject $objectService;

	/** @var StackiqContactSyncService&MockObject */
	private StackiqContactSyncService&MockObject $contactSync;

	/** @var IUserSession&MockObject */
	private IUserSession&MockObject $userSession;

	/** @var SettingsService&MockObject */
	private SettingsService&MockObject $settingsService;

	/**
	 * Signed in, Contacts available, register and schema configured.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->contactSync = $this->createMock(StackiqContactSyncService::class);
		$this->contactSync->method('isAvailable')->willReturn(true);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($this->createMock(IUser::class));
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->settingsService->method('getVoorzieningenConfig')->willReturn(
			['register' => '20', 'contactpersoon_schema' => '7']
		);
	}//end setUp()

	/**
	 * A controller for a request with these ids.
	 *
	 * @param mixed $ids The `ids` query parameter.
	 *
	 * @return ContactNamesController
	 */
	private function controller(mixed $ids): ContactNamesController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($key === 'ids' ? $ids : $default)
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new ContactNamesController(
			appName: 'stackiq',
			request: $request,
			userSession: $this->userSession,
			l10n: $l10n,
			settingsService: $this->settingsService,
			contactSync: $this->contactSync,
			objectService: $this->objectService,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * A contact person's name comes from its contact, read with the caller's rights.
	 *
	 * @return void
	 */
	public function testNamesTheReadableContactPersons(): void {
		$this->objectService->expects($this->once())
			->method('searchObjectsPaginated')
			->with(
				$this->callback(static fn (array $query): bool => $query['@self'] === ['register' => 20, 'schema' => 7]),
				// The caller's own RBAC and organisation scope.
				true,
				true,
				false,
				[self::A, self::B]
			)
			->willReturn(
				[
					'results' => [
						['id' => self::A, 'contactsUid' => 'uid-a'],
						['id' => self::B, 'contactsUid' => 'uid-b'],
					],
				]
			);
		$this->contactSync->method('findContactByUid')->willReturnMap(
			[
				['uid-a', ['UID' => 'uid-a', 'FN' => 'Teamleider Applicatiebeheer']],
				['uid-b', null],
			]
		);

		$response = $this->controller(ids: [self::A, strtoupper(self::B), 'not-a-uuid', self::A])->index();

		$this->assertSame(200, $response->getStatus());
		$this->assertEquals(['names' => (object)[self::A => 'Teamleider Applicatiebeheer']], $response->getData());
	}//end testNamesTheReadableContactPersons()

	/**
	 * A record OpenRegister returns but that was not asked for is never named.
	 *
	 * @return void
	 */
	public function testNamesOnlyTheRequestedIds(): void {
		$this->objectService->method('searchObjectsPaginated')->willReturn(
			['results' => [['id' => self::C, 'contactsUid' => 'uid-c']]]
		);
		$this->contactSync->expects($this->never())->method('findContactByUid');

		$response = $this->controller(ids: [self::A])->index();

		$this->assertEquals(['names' => (object)[]], $response->getData());
	}//end testNamesOnlyTheRequestedIds()

	/**
	 * Without a signed-in user nothing is read.
	 *
	 * @return void
	 */
	public function testRefusesAnonymousCallers(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);
		$this->objectService->expects($this->never())->method('searchObjectsPaginated');

		$response = $this->controller(ids: [self::A])->index();

		$this->assertSame(401, $response->getStatus());
		$this->assertSame('not-authenticated', $response->getData()['error']);
	}//end testRefusesAnonymousCallers()

	/**
	 * More ids than one list page shows are refused.
	 *
	 * @return void
	 */
	public function testRefusesMoreThanOnePageOfIds(): void {
		$ids = [];
		for ($i = 0; $i <= ContactNamesController::MAX_IDS; $i++) {
			$ids[] = sprintf('00000000-0000-0000-0000-%012d', $i);
		}

		$this->objectService->expects($this->never())->method('searchObjectsPaginated');

		$response = $this->controller(ids: $ids)->index();

		$this->assertSame(400, $response->getStatus());
	}//end testRefusesMoreThanOnePageOfIds()

	/**
	 * Without a configured contact person schema no contact person is resolved.
	 *
	 * @return void
	 */
	public function testFailsClosedWithoutTheContactPersonSchema(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->settingsService->method('getVoorzieningenConfig')->willReturn(['register' => '20']);
		$this->objectService->expects($this->never())->method('searchObjectsPaginated');

		$response = $this->controller(ids: [self::A])->index();

		$this->assertSame(200, $response->getStatus());
		$this->assertEquals(['names' => (object)[]], $response->getData());
	}//end testFailsClosedWithoutTheContactPersonSchema()

	/**
	 * An OpenRegister failure gives no names, not a 500.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterGivesNoNames(): void {
		$this->objectService->method('searchObjectsPaginated')->willThrowException(new \RuntimeException('down'));

		$response = $this->controller(ids: self::A)->index();

		$this->assertSame(200, $response->getStatus());
		$this->assertEquals(['names' => (object)[]], $response->getData());
	}//end testAnUnreadableRegisterGivesNoNames()
}//end class
