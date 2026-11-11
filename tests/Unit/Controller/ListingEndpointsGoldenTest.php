<?php

/**
 * Golden test for the listing endpoints of the aanbod and aangeboden-gebruik
 * controllers.
 *
 * Pins the refused outcomes (anonymous 401, a caller outside the admin and
 * ambtenaar groups gets the empty 200 without the bypass read) and the
 * allowed ones (200, and 500 when the service reports or throws an error):
 * status, body, the service calls with their arguments, and every log line.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/aangeboden-gebruik-api/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\AanbodController;
use OCA\Stackiq\Controller\AangebodenGebruikController;
use OCA\Stackiq\Service\AanbodService;
use OCA\Stackiq\Service\AangebodenGebruikService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Records every log call as [level, message, context].
 */
class ListingEndpointLogSpy extends AbstractLogger {
	/**
	 * Recorded log calls.
	 *
	 * @var array<int, array{0:string,1:string,2:array}>
	 */
	public array $records = [];

	/**
	 * Record one log call; stack traces are replaced by a marker.
	 *
	 * @param mixed              $level   The level.
	 * @param string|\Stringable $message The message.
	 * @param array              $context The context.
	 *
	 * @return void
	 */
	public function log($level, string|\Stringable $message, array $context=[]): void {
		if (isset($context['trace']) === true) {
			$context['trace'] = '<trace>';
		}

		$this->records[] = [(string)$level, (string)$message, $context];
	}
}

/**
 * Golden test for the listing endpoints.
 *
 * @spec openspec/specs/aangeboden-gebruik-api/spec.md
 */
class ListingEndpointsGoldenTest extends TestCase {
	/**
	 * Call one listing endpoint in one scenario and record the outcome.
	 *
	 * @param string $endpoint The endpoint key.
	 * @param array  $scenario The scenario.
	 *
	 * @return array
	 */
	private function callEndpoint(string $endpoint, array $scenario): array {
		$params  = $scenario['params'];
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(fn (string $name) => $params[$name] ?? null);
		$user = null;
		if ($scenario['user'] !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($scenario['user']);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(
			function (string $name) use ($scenario) {
				if (in_array($name, $scenario['missingGroups'], true) === true) {
					return null;
				}

				$group = $this->createMock(IGroup::class);
				$group->method('inGroup')->willReturn(in_array($name, $scenario['groups'], true));
				return $group;
			}
		);
		$log   = new ListingEndpointLogSpy();
		$calls = [];
		$reply = function (...$args) use (&$calls, $scenario) {
			$calls[] = $args;
			if ($scenario['result'] === 'throw') {
				throw new \RuntimeException('service down');
			}

			return $scenario['result'];
		};

		if ($endpoint === 'aanbod') {
			$service = $this->createMock(AanbodService::class);
			$service->method('getAanbod')->willReturnCallback($reply);
			$response = (new AanbodController('stackiq', $request, $session, $service, $log))->getAanbod();
			return [$response->getStatus(), $response->getData(), $calls, $log->records];
		}

		$service = $this->createMock(AangebodenGebruikService::class);
		$service->method('getGebruiksWhereAfnemer')->willReturnCallback($reply);
		$service->method('getKoppelingenGebruikByUuid')->willReturnCallback($reply);
		$service->method('getAllGebruiksForAmbtenaar')->willReturnCallback($reply);
		$service->method('getSingleGebruikForAmbtenaar')->willReturnCallback($reply);
		$controller = new AangebodenGebruikController('stackiq', $request, $session, $service, $log, $groups);
		$response   = match ($endpoint) {
			'afnemer'     => $controller->getGebruiksWhereAfnemer(),
			'koppelingen' => $controller->getKoppelingenGebruikByUuid('app-1'),
			'all'         => $controller->getAllGebruiksForAmbtenaar(),
			'single'      => $controller->getSingleGebruikForAmbtenaar('use-1'),
		};

		return [$response->getStatus(), $response->getData(), $calls, $log->records];
	}

	/**
	 * Every listing endpoint across its refused, allowed and failing outcomes.
	 *
	 * @return void
	 */
	public function testListingEndpoints(): void {
		$ok        = ['results' => [['id' => 'a']], 'total' => 1, 'page' => 1, 'pages' => 1];
		$base      = ['user' => 'alice', 'groups' => [], 'missingGroups' => [], 'params' => ['limit' => '5'], 'result' => $ok];
		$scenarios = [
			'anonymous'        => ['user' => null] + $base,
			'ordinary'         => $base,
			'ambtenaar'        => ['groups' => ['ambtenaar']] + $base,
			'admin'            => ['groups' => ['admin']] + $base,
			'both'             => ['groups' => ['admin', 'ambtenaar']] + $base,
			'no-groups-exist'  => ['missingGroups' => ['admin', 'ambtenaar']] + $base,
			'org-filter'       => ['groups' => ['ambtenaar'], 'params' => ['organisation' => 'org-9', 'offset' => '2']] + $base,
			'org-filter-plain' => ['params' => ['organisation' => 'org-9']] + $base,
			'service-error'    => ['groups' => ['admin'], 'result' => ['results' => [], 'error' => 'boom']] + $base,
			'service-bare'     => ['groups' => ['admin'], 'result' => []] + $base,
			'service-throws'   => ['groups' => ['ambtenaar'], 'result' => 'throw'] + $base,
		];

		$out = [];
		foreach (['aanbod', 'afnemer', 'koppelingen', 'all', 'single'] as $endpoint) {
			foreach ($scenarios as $name => $scenario) {
				$out[$endpoint . '/' . $name] = $this->callEndpoint(endpoint: $endpoint, scenario: $scenario);
			}
		}

		$path   = dirname(__DIR__, 2) . '/fixtures/object-actions/listings.json';
		$actual = json_decode((string)json_encode($out, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR), $actual);
	}
}
