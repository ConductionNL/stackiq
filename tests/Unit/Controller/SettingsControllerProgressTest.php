<?php

/**
 * Unit tests for who may read the progress of an operation.
 *
 * Progress now lives in the distributed cache, so an operation id no longer
 * stays inside one session. The read rule on GET /api/progress/{operationId}
 * and its stream is what keeps it private: the owner and Nextcloud admins
 * read it, anyone else gets 404, the same answer as an unknown id.
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
 * @spec openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-001-progress-of-a-long-operation-shall-be-readable-from-any-request-and-only-by-users-allowed-to-read-it
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\SettingsController;
use OCA\Stackiq\Service\ArchiMateService;
use OCA\Stackiq\Service\EolSyncService;
use OCA\Stackiq\Service\OrganizationSyncService;
use OCA\Stackiq\Service\ProgressTracker;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Read rule of SettingsController::getProgress() and streamProgress().
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class SettingsControllerProgressTest extends TestCase {

	/**
	 * Build the controller for a signed-in caller and one stored operation.
	 *
	 * @param string     $callerUid The signed-in user.
	 * @param bool       $isAdmin   Whether the caller is a Nextcloud admin.
	 * @param array|null $progress  What the tracker holds for the operation.
	 *
	 * @return SettingsController The controller under test.
	 */
	private function makeController(string $callerUid, bool $isAdmin, ?array $progress): SettingsController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($callerUid);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(
			static fn (string $uid): bool => $uid === $callerUid && $isAdmin
		);

		$progressTracker = $this->createMock(ProgressTracker::class);
		$progressTracker->method('getProgress')->willReturn($progress);

		return new SettingsController(
			'stackiq',
			$this->createMock(IRequest::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppManager::class),
			$groupManager,
			$userSession,
			$this->createMock(SettingsService::class),
			$this->createMock(OrganizationSyncService::class),
			$this->createMock(ArchiMateService::class),
			$progressTracker,
			$this->createMock(EolSyncService::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end makeController()

	/**
	 * A stored operation with the given owner.
	 *
	 * @param string|null $ownerUid The owner, or null for an operation without one.
	 *
	 * @return array The progress snapshot.
	 */
	private function operation(?string $ownerUid): array {
		return [
			'operation_id' => 'sbom-import_abc',
			'operation_type' => 'sbom-import',
			'owner_uid' => $ownerUid,
			'phase' => 'processing_elements',
			'percentage' => 40,
		];
	}//end operation()

	/**
	 * The owner reads their own operation.
	 *
	 * @return void
	 */
	public function testTheOwnerReadsTheirOperation(): void {
		$response = $this->makeController('alice', false, $this->operation('alice'))->getProgress('sbom-import_abc');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(40, $response->getData()['progress']['percentage']);
	}//end testTheOwnerReadsTheirOperation()

	/**
	 * Another user without admin rights gets 404 for an operation they do not own.
	 *
	 * @return void
	 */
	public function testAnotherUserGetsNotFound(): void {
		$response = $this->makeController('bob', false, $this->operation('alice'))->getProgress('sbom-import_abc');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAnotherUserGetsNotFound()

	/**
	 * An operation without an owner, such as one a background job started,
	 * is not readable by a user without admin rights who has its id.
	 *
	 * @return void
	 */
	public function testAnOperationWithoutOwnerIsNotReadableByANonAdmin(): void {
		$response = $this->makeController('bob', false, $this->operation(null))->getProgress('sbom-import_abc');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAnOperationWithoutOwnerIsNotReadableByANonAdmin()

	/**
	 * A Nextcloud admin reads an operation another user started.
	 *
	 * @return void
	 */
	public function testAnAdminReadsAnyOperation(): void {
		$response = $this->makeController('root', true, $this->operation('alice'))->getProgress('sbom-import_abc');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testAnAdminReadsAnyOperation()

	/**
	 * A Nextcloud admin reads an operation without an owner.
	 *
	 * @return void
	 */
	public function testAnAdminReadsAnOperationWithoutOwner(): void {
		$response = $this->makeController('root', true, $this->operation(null))->getProgress('sbom-import_abc');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testAnAdminReadsAnOperationWithoutOwner()

	/**
	 * An unknown id answers 404.
	 *
	 * @return void
	 */
	public function testAnUnknownOperationIsNotFound(): void {
		$response = $this->makeController('root', true, null)->getProgress('sbom-import_abc');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAnUnknownOperationIsNotFound()

	/**
	 * The stream applies the same rule before it starts.
	 *
	 * @return void
	 */
	public function testTheStreamRefusesAnOperationWithoutOwnerToANonAdmin(): void {
		$response = $this->makeController('bob', false, $this->operation(null))->streamProgress('sbom-import_abc');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testTheStreamRefusesAnOperationWithoutOwnerToANonAdmin()

	/**
	 * The stream refuses another user's operation.
	 *
	 * @return void
	 */
	public function testTheStreamRefusesAnotherUsersOperation(): void {
		$response = $this->makeController('bob', false, $this->operation('alice'))->streamProgress('sbom-import_abc');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testTheStreamRefusesAnotherUsersOperation()

}//end class
