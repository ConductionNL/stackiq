<?php

/**
 * Entry type controller tests
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-001-an-entry-changes-type-and-keeps-its-identity
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\EntryTypeController;
use OCA\Stackiq\Service\EntryType\EntryTypeRefusal;
use OCA\Stackiq\Service\EntryTypeService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EntryTypeController.
 *
 * @covers \OCA\Stackiq\Controller\EntryTypeController
 * @covers \OCA\Stackiq\Service\EntryType\EntryTypeRefusal
 */
class EntryTypeControllerTest extends TestCase {

	private EntryTypeService&MockObject $service;

	protected function setUp(): void {
		$this->service = $this->createMock(EntryTypeService::class);

	}//end setUp()

	/**
	 * The controller under test.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param bool $signedIn Whether a user is signed in.
	 *
	 * @return EntryTypeController
	 */
	private function controller(array $params, bool $signedIn = true): EntryTypeController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);

		return new EntryTypeController($request, $session, $this->service);
	}//end controller()

	public function testAnAnonymousCallerIsRefusedBeforeTheService(): void {
		$this->service->expects($this->never())->method('preview');
		$this->service->expects($this->never())->method('change');

		$this->assertSame(401, $this->controller(['target' => 'service'], false)->preview('u-1')->getStatus());
		$this->assertSame(401, $this->controller(['target' => 'service'], false)->change('u-1')->getStatus());
	}//end testAnAnonymousCallerIsRefusedBeforeTheService()

	public function testThePreviewAnswersWhatTheServiceSays(): void {
		$preview = ['uuid' => 'u-1', 'currentType' => 'application', 'targetType' => 'service', 'carried' => ['name'], 'dropped' => ['licence' => 'EUPL'], 'blockers' => [], 'serviceTypes' => ['Training']];
		$this->service->expects($this->once())->method('preview')->with('u-1', 'service')->willReturn($preview);

		$response = $this->controller(['target' => 'service'])->preview('u-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($preview, $response->getData());
	}//end testThePreviewAnswersWhatTheServiceSays()

	public function testABlockedChangeAnswers409WithTheBlockers(): void {
		$blockers = [['schema' => 'usage', 'field' => 'module', 'count' => 1]];
		$this->service->method('change')->willThrowException(new EntryTypeRefusal(message: 'Other entries still point at this one, so its type cannot change.', status: 409, blockers: $blockers));

		$response = $this->controller(['target' => 'service', 'serviceTypes' => ['Training']])->change('u-1');

		$this->assertSame(409, $response->getStatus());
		$this->assertSame(['message' => 'Other entries still point at this one, so its type cannot change.', 'blockers' => $blockers], $response->getData());
	}//end testABlockedChangeAnswers409WithTheBlockers()

	public function testAForbiddenChangeAnswers403(): void {
		$this->service->method('change')->willThrowException(new EntryTypeRefusal(message: 'You may not change the type of this entry.', status: 403));

		$response = $this->controller(['target' => 'service'])->change('u-1');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['message' => 'You may not change the type of this entry.'], $response->getData());
	}//end testAForbiddenChangeAnswers403()

	public function testAnEntryThatDoesNotFitAnswers422(): void {
		$this->service->method('preview')->willThrowException(new EntryTypeRefusal(message: 'This entry cannot change into that type.', status: 422));

		$this->assertSame(422, $this->controller(['target' => 'nonsense'])->preview('u-1')->getStatus());
	}//end testAnEntryThatDoesNotFitAnswers422()

	public function testTheChangePassesTheTargetAndServiceTypes(): void {
		$this->service->expects($this->once())->method('change')->with('u-1', 'service', ['Training'])->willReturn(['uuid' => 'u-1', 'targetType' => 'service', 'moved' => true, 'errors' => []]);

		$response = $this->controller(['target' => 'service', 'serviceTypes' => 'Training'])->change('u-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['moved']);
	}//end testTheChangePassesTheTargetAndServiceTypes()
}//end class
