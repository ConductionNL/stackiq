<?php

/**
 * UsageClassificationControllerTest
 *
 * Who may read the classification summary, and the suggestion's answers
 * (security-baseline-classification REQ-BCL-003, REQ-BCL-004).
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\UsageClassificationController;
use OCA\Stackiq\Kernel\Contracts\OpenRegisterLookupInterface;
use OCA\Stackiq\Service\ClassificationSummaryAccess;
use OCA\Stackiq\Service\UsageClassificationService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for UsageClassificationController.
 *
 * @covers \OCA\Stackiq\Controller\UsageClassificationController
 * @uses   \OCA\Stackiq\Service\ClassificationSummaryAccess
 */
class UsageClassificationControllerTest extends TestCase {

	/**
	 * The service double.
	 *
	 * @var UsageClassificationService&MockObject
	 */
	private UsageClassificationService $service;

	/**
	 * Prepare the service double; org-sup is a supplier, org-gem a municipality, org-sam a collaboration.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = $this->createMock(UsageClassificationService::class);
		$this->service->method('organisationTypeOf')->willReturnCallback(
			static fn (string $id): ?string => ['org-sup' => 'Supplier', 'org-gem' => 'Municipality', 'org-sam' => 'Collaboration'][$id] ?? null
		);
	}//end setUp()

	/**
	 * The controller for a user.
	 *
	 * @param string|null $organisation The active organisation, or null for no user at all when $groups is null.
	 * @param array|null  $groups       The user's groups; null means not signed in.
	 *
	 * @return UsageClassificationController
	 */
	private function controller(?string $organisation, ?array $groups = []): UsageClassificationController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($groups !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('someone');
		}

		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(in_array('admin', $groups ?? [], true));
		$groupManager->method('isInGroup')->willReturnCallback(static fn (string $uid, string $gid): bool => in_array($gid, $groups ?? [], true));
		$lookup = $this->createMock(OpenRegisterLookupInterface::class);
		$lookup->method('activeOrganisationUuid')->willReturn($organisation);

		return new UsageClassificationController(
			appName: 'stackiq',
			request: $this->createMock(IRequest::class),
			classification: $this->service,
			access: new ClassificationSummaryAccess(
				classification: $this->service,
				lookup: $lookup,
				userSession: $session,
				groupManager: $groupManager
			),
			logger: new NullLogger()
		);
	}//end controller()

	/**
	 * A supplier's user gets 403 before the summary is read; a municipal user, a collaboration's user, ambtenaar and admin get 200.
	 *
	 * @return void
	 */
	public function testOnlyMunicipalReadersGetTheSummary(): void {
		$this->service->expects($this->exactly(4))->method('summary')->with('m-1')->willReturn(['organisations' => 2, 'levels' => null]);

		$this->assertSame(403, $this->controller(organisation: 'org-sup')->summary(moduleId: 'm-1')->getStatus());
		$this->assertSame(403, $this->controller(organisation: null)->summary(moduleId: 'm-1')->getStatus());
		$this->assertSame(401, $this->controller(organisation: 'org-gem', groups: null)->summary(moduleId: 'm-1')->getStatus());

		$answer = $this->controller(organisation: 'org-gem')->summary(moduleId: 'm-1');
		$this->assertSame(200, $answer->getStatus());
		$this->assertSame(['organisations' => 2, 'levels' => null], $answer->getData());
		$this->assertSame(200, $this->controller(organisation: 'org-sam')->summary(moduleId: 'm-1')->getStatus());
		$this->assertSame(200, $this->controller(organisation: 'org-sup', groups: ['ambtenaar'])->summary(moduleId: 'm-1')->getStatus());
		$this->assertSame(200, $this->controller(organisation: null, groups: ['admin'])->summary(moduleId: 'm-1')->getStatus());
	}//end testOnlyMunicipalReadersGetTheSummary()

	/**
	 * The suggestion answers 200 with the levels, 404 for a usage the caller cannot read, and 500 when it fails.
	 *
	 * @return void
	 */
	public function testTheSuggestionAnswers(): void {
		$levels = ['levels' => ['availability' => 'BBN1', 'integrity' => 'BBN2', 'confidentiality' => 'BBN2'], 'scored' => 1, 'components' => 1];
		$this->service->method('suggestion')->willReturnCallback(
			static fn (string $id): ?array => match ($id) {
				'u-1' => $levels,
				'u-x' => throw new \RuntimeException('OpenRegister is not available'),
				default => null,
			}
		);

		$this->assertSame($levels, $this->controller(organisation: 'org-sup')->suggestion(usageId: 'u-1')->getData());
		$this->assertSame(404, $this->controller(organisation: 'org-sup')->suggestion(usageId: 'u-2')->getStatus());
		$this->assertSame(500, $this->controller(organisation: 'org-sup')->suggestion(usageId: 'u-x')->getStatus());
		$this->assertSame(401, $this->controller(organisation: null, groups: null)->suggestion(usageId: 'u-1')->getStatus());
	}//end testTheSuggestionAnswers()
}//end class
