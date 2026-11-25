<?php

/**
 * Health without OpenRegister answers 503 in the ADR-006 shape (adopt-apphost 3.1).
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Controller
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/adopt-apphost/tasks.md#3-verification
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\HealthController;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * Covers HealthController.
 *
 * @covers \OCA\Stackiq\Controller\HealthController
 */
class HealthControllerTest extends TestCase {

	/**
	 * A controller over a database that answers, or throws.
	 *
	 * @param bool $databaseUp Whether the trivial query succeeds
	 *
	 * @return HealthController
	 */
	private function controller(bool $databaseUp): HealthController {
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getAppVersion')->with('stackiq')->willReturn('1.2.3');

		$db = $this->createMock(originalClassName: IDBConnection::class);
		if ($databaseUp === true) {
			$db->method('executeQuery')->with('SELECT 1')->willReturn($this->createMock(originalClassName: IResult::class));
		} else {
			$db->method('executeQuery')->willThrowException(new RuntimeException('connection refused'));
		}

		return new HealthController(
			appName: 'stackiq',
			request: $this->createMock(originalClassName: IRequest::class),
			appManager: $appManager,
			db: $db
		);
	}//end controller()

	/**
	 * The failed critical check gives `error` with 503 and the ADR-006 keys.
	 *
	 * @return void
	 */
	public function testAnswers503WithTheOpenRegisterCheckFailed(): void {
		$response = $this->controller(databaseUp: true)->index();

		$this->assertSame(expected: 503, actual: $response->getStatus());
		$this->assertSame(
			expected: [
				'status'  => 'error',
				'app'     => 'stackiq',
				'version' => '1.2.3',
				'checks'  => ['database' => 'ok', 'openregister' => 'failed: openregister unavailable'],
			],
			actual: $response->getData()
		);
	}//end testAnswers503WithTheOpenRegisterCheckFailed()

	/**
	 * A database that cannot be queried is reported failed, without its error text.
	 *
	 * @return void
	 */
	public function testAFailingDatabaseIsReportedWithoutItsMessage(): void {
		$data = $this->controller(databaseUp: false)->index()->getData();

		$this->assertSame(expected: 'failed', actual: $data['checks']['database']);
		$this->assertStringNotContainsString(needle: 'connection refused', haystack: (string)json_encode($data));
	}//end testAFailingDatabaseIsReportedWithoutItsMessage()

	/**
	 * Probes need no session and no CSRF token, as for the AppHost generic.
	 *
	 * @return void
	 */
	public function testTheEndpointIsPublic(): void {
		$method = new ReflectionMethod(HealthController::class, 'index');

		$this->assertNotEmpty(actual: $method->getAttributes(PublicPage::class));
		$this->assertNotEmpty(actual: $method->getAttributes(NoCSRFRequired::class));
	}//end testTheEndpointIsPublic()
}//end class
