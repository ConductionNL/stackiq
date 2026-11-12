<?php

/**
 * Golden test of the progress event stream of SettingsController.
 *
 * Pins who may open the stream (no user, the owner, another user, an admin)
 * and, for an opened stream, every event it writes and every log line: an
 * unknown operation, an operation that completes at once, one that runs,
 * repeats and completes, and one whose lookup fails.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-001-progress-of-a-long-operation-shall-be-readable-from-any-request-and-only-by-users-allowed-to-read-it
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\SettingsController;
use OCA\Stackiq\Service\ArchiMateService;
use OCA\Stackiq\Service\EolSyncService;
use OCA\Stackiq\Service\OrganizationSyncService;
use OCA\Stackiq\Service\ProgressTracker;
use OCA\Stackiq\Service\Settings\EmailSettings;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
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
 * Golden test of the progress stream.
 */
class SettingsControllerProgressStreamGoldenTest extends TestCase {
	/**
	 * Log lines of the last built controller.
	 *
	 * @var array<int, array>
	 */
	private array $logged = [];

	/**
	 * A controller for a caller over a scripted sequence of progress lookups.
	 *
	 * @param string|null $callerUid The caller, or null for no user.
	 * @param array       $lookups   What each getProgress call answers; an exception is thrown.
	 *
	 * @return SettingsController
	 */
	private function makeController(?string $callerUid, array $lookups): SettingsController {
		$userSession = $this->createMock(IUserSession::class);
		$user = null;
		if ($callerUid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($callerUid);
		}

		$userSession->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');

		$progressTracker = $this->createMock(ProgressTracker::class);
		$progressTracker->method('getProgress')->willReturnCallback(
			static function () use (&$lookups) {
				$next = array_shift($lookups);
				if ($next instanceof \Exception) {
					throw $next;
				}

				return $next;
			}
		);

		$this->logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(function (string $message, array $context = []): void {
			$this->logged[] = ['error', $message, $context];
		});

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
			$this->createMock(EmailSettings::class),
			$logger
		);
	}//end makeController()

	/**
	 * One operation's progress.
	 *
	 * @param string $phase      The phase.
	 * @param int    $percentage The percentage.
	 *
	 * @return array
	 */
	private function progress(string $phase, int $percentage): array {
		return ['operation_id' => 'op-1', 'owner_uid' => 'alice', 'phase' => $phase, 'percentage' => $percentage];
	}//end progress()

	/**
	 * Open the stream and record the response, its headers, its events and the log lines.
	 *
	 * @param string|null $callerUid The caller.
	 * @param array       $lookups   The progress lookups.
	 *
	 * @return array
	 */
	private function record(?string $callerUid, array $lookups): array {
		$response = $this->makeController($callerUid, $lookups)->streamProgress('op-1');
		if ($response instanceof JSONResponse) {
			return ['json' => [$response->getStatus(), $response->getData()]];
		}

		$headers = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');
		$headers->setAccessible(true);
		ob_start();
		ob_start();
		$returned = $response->render();
		$output = (string)ob_get_clean();

		return [
			'headers' => $headers->getValue($response),
			'returned' => $returned,
			'output' => preg_replace('/"timestamp":\d+/', '"timestamp":"<time>"', $output),
			'log' => $this->logged,
		];
	}//end record()

	/**
	 * Who may open the stream and what an open stream writes match the golden.
	 *
	 * @return void
	 */
	public function testTheStreamMatchesTheGolden(): void {
		$running = $this->progress('processing', 40);
		$completed = $this->progress('completed', 100);
		$out = [
			'no user' => $this->record(null, [$running]),
			'another user' => $this->record('bob', [$running]),
			'an admin, completed at once' => $this->record('admin', [$running, $completed]),
			'the owner, unknown operation' => $this->record('alice', [null, null]),
			'the owner, completed at once' => $this->record('alice', [$running, $completed]),
			'the owner, runs, repeats and completes' => $this->record('alice', [$running, $running, $running, $completed]),
			'the owner, lookup fails' => $this->record('alice', [$running, new \RuntimeException('tracker down')]),
		];

		$path = dirname(__DIR__, 2) . '/fixtures/settings/progress-stream.json';
		$this->assertSame(
			json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR),
			json_decode((string)json_encode($out, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)
		);
	}//end testTheStreamMatchesTheGolden()
}//end class
