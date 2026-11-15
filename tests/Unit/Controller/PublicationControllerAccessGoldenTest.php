<?php

/**
 * Golden test of who may publish and depublish a catalog entry.
 *
 * Pins, for publish and depublish, the allowed and refused outcomes: no user,
 * a type that is not publishable, an unknown entry, a peer-sourced entry, an
 * admin, an aanbod-beheerder of the owning organisation (by _organisation or
 * by provider), one of another or of no organisation, a user in neither
 * group, and a service refusal: status, body, the service calls, the config
 * reads and the log lines.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/open-data-publishing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\PublicationController;
use OCA\Stackiq\Service\PublicationService;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden test of the publication access rule.
 */
class PublicationControllerAccessGoldenTest extends TestCase {
	/**
	 * What the doubles saw.
	 *
	 * @var array<int, array>
	 */
	private array $calls = [];

	/**
	 * Run one action for one caller and entry and record the outcome.
	 *
	 * @param string     $action The controller action.
	 * @param array      $case   uid, groups, org, publishable, entry and the service result.
	 *
	 * @return array
	 */
	private function record(string $action, array $case): array {
		$this->calls = [];
		$user = null;
		if ($case['uid'] !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($case['uid']);
		}

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroups')->willReturnCallback(function () use ($case): array {
			$this->calls[] = ['getUserGroups'];
			return array_map(function (string $gid): IGroup {
				$group = $this->createMock(IGroup::class);
				$group->method('getGID')->willReturn($gid);
				return $group;
			}, $case['groups']);
		});
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(function (string $userId, string $appName, string $key) use ($case): string {
			$this->calls[] = ['getUserValue', $userId, $appName, $key];
			return $case['org'];
		});
		$service = $this->createMock(PublicationService::class);
		$service->method('isPublishableType')->willReturnCallback(function (string $type) use ($case): bool {
			$this->calls[] = ['isPublishableType', $type];
			return $case['publishable'];
		});
		$service->method('resolveEntry')->willReturnCallback(function (string $type, string $uuid) use ($case): ?array {
			$this->calls[] = ['resolveEntry', $type, $uuid];
			return $case['entry'];
		});
		foreach (['publish', 'depublish'] as $method) {
			$service->method($method)->willReturnCallback(function (...$args) use ($case, $method): array {
				$this->calls[] = array_merge([$method], $args);
				return $case['result'];
			});
		}

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
			$this->calls[] = ['warning', $message, $context];
		});

		$controller = new PublicationController($this->createMock(IRequest::class), $userSession, $groupManager, $config, $service, $logger);
		$response = $controller->$action('module', 'uuid-1');

		return ['status' => $response->getStatus(), 'data' => $response->getData(), 'calls' => $this->calls];
	}//end record()

	/**
	 * Every caller and entry, for publish and depublish, match the golden.
	 *
	 * @return void
	 */
	public function testPublicationAccessMatchesTheGolden(): void {
		$owned = ['data' => ['_organisation' => 'org-a', 'provider' => 'org-b']];
		$base = ['uid' => 'alice', 'groups' => ['aanbod-beheerder'], 'org' => 'org-a', 'publishable' => true, 'entry' => $owned, 'result' => ['ok' => true, 'publicatiedatum' => '2026-01-01']];
		$cases = [
			'no user' => ['uid' => null] + $base,
			'type not publishable' => ['publishable' => false] + $base,
			'entry not found' => ['entry' => null] + $base,
			'peer-sourced entry' => ['entry' => ['data' => ['_organisation' => 'org-a', '_source' => ['instance' => 'peer.example']]]] + $base,
			'blank peer instance' => ['entry' => ['data' => ['_organisation' => 'org-a', '_source' => ['instance' => '  ']]]] + $base,
			'source not an array' => ['entry' => ['data' => ['_organisation' => 'org-a', '_source' => 'peer']]] + $base,
			'admin of another organisation' => ['groups' => ['admin'], 'org' => 'org-z'] + $base,
			'beheerder owns by organisation' => $base,
			'beheerder owns by provider' => ['entry' => ['data' => ['provider' => 'org-a']]] + $base,
			'beheerder of another organisation' => ['org' => 'org-z'] + $base,
			'beheerder without organisation' => ['org' => ''] + $base,
			'entry without owner' => ['entry' => ['data' => []]] + $base,
			'user in neither group' => ['groups' => ['users']] + $base,
			'service refuses' => ['result' => ['ok' => false, 'reason' => 'already published']] + $base,
		];

		$out = [];
		foreach (['publish', 'depublish'] as $action) {
			foreach ($cases as $name => $case) {
				$out[$action . ' | ' . $name] = $this->record(action: $action, case: $case);
			}
		}

		$path = dirname(__DIR__, 2) . '/fixtures/publication/access.json';
		$this->assertSame(
			json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR),
			json_decode((string)json_encode($out, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)
		);
	}//end testPublicationAccessMatchesTheGolden()
}//end class
