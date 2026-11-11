<?php

/**
 * Golden test for the accept/deny style object actions of the aanbod and
 * aangeboden-gebruik controllers.
 *
 * Pins, for every endpoint, the refused outcomes (anonymous caller 401, empty
 * id 400, authorisation refusal 403, missing object 404) and the allowed one
 * (200), plus the unclassified and thrown failures (500): status, body, the
 * options forwarded to the service, and every log line.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/aanbod-listings/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\AanbodController;
use OCA\Stackiq\Controller\AangebodenGebruikController;
use OCA\Stackiq\Service\AanbodService;
use OCA\Stackiq\Service\AangebodenGebruikService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Records every log call as [level, message, context].
 */
class ObjectActionLogSpy extends AbstractLogger {
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
 * Golden test for the object action endpoints.
 *
 * @spec openspec/specs/aanbod-listings/spec.md
 */
class ObjectActionEndpointsGoldenTest extends TestCase {
	/**
	 * Run one endpoint call and record its outcome.
	 *
	 * @param string $endpoint The endpoint key.
	 * @param array  $scenario The scenario.
	 *
	 * @return array
	 */
	private function callEndpoint(string $endpoint, array $scenario): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($scenario['params']);
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($scenario['user'] === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
		}

		$session->method('getUser')->willReturn($user);
		$log   = new ObjectActionLogSpy();
		$calls = [];
		$reply = function (...$args) use (&$calls, $scenario) {
			$calls[] = $args;
			if ($scenario['result'] === 'throw') {
				throw new \RuntimeException('service down');
			}

			return $scenario['result'];
		};

		if (str_starts_with($endpoint, 'aanbod') === true) {
			$service = $this->createMock(AanbodService::class);
			$service->method('acceptAanbod')->willReturnCallback($reply);
			$service->method('denyAanbod')->willReturnCallback($reply);
			$controller = new AanbodController('stackiq', $request, $session, $service, $log);
		} else {
			$service = $this->createMock(AangebodenGebruikService::class);
			$service->method('setGebruikSelfToActiveOrg')->willReturnCallback($reply);
			$service->method('deleteGebruikAsAfnemer')->willReturnCallback($reply);
			$controller = new AangebodenGebruikController('stackiq', $request, $session, $service, $log, $this->createMock(IGroupManager::class));
		}

		$response = match ($endpoint) {
			'aanbod-accept' => $controller->acceptAanbod($scenario['id']),
			'aanbod-deny'   => $controller->denyAanbod($scenario['id']),
			'gebruik-self'  => $controller->setGebruikSelfToActiveOrg($scenario['id']),
			'gebruik-deny'  => $controller->deleteGebruikAsAfnemer($scenario['id']),
		};

		return [$response->getStatus(), $response->getData(), $calls, $log->records];
	}

	/**
	 * Every endpoint across its refused, allowed and failing outcomes.
	 *
	 * @return void
	 */
	public function testObjectActionEndpoints(): void {
		$results = [
			['success' => true, 'aanbod' => ['id' => 'x'], 'deleted' => true],
			['success' => false, 'error' => 'Aanbod object not found'],
			['success' => false, 'error' => 'Gebruik object not found'],
			['success' => false, 'error' => 'Operation not allowed: not a party'],
			['success' => false, 'error' => 'Something else'],
			'throw',
		];
		$scenarios = [
			'anonymous' => ['user' => false, 'id' => 'abc', 'params' => [], 'result' => $results[0]],
			'empty-id'  => ['user' => true, 'id' => '', 'params' => [], 'result' => $results[0]],
			'zero-id'   => ['user' => true, 'id' => '0', 'params' => [], 'result' => $results[0]],
		];
		foreach ($results as $i => $result) {
			$scenarios['result-' . $i]        = ['user' => true, 'id' => 'abc', 'params' => [], 'result' => $result];
			$scenarios['result-' . $i . '-p'] = ['user' => true, 'id' => 'abc', 'params' => ['uuid' => 'abc', 'gebruikId' => 'abc', 'note' => 'n', 0 => 'z'], 'result' => $result];
		}

		$out = [];
		foreach (['aanbod-accept', 'aanbod-deny', 'gebruik-self', 'gebruik-deny'] as $endpoint) {
			foreach ($scenarios as $name => $scenario) {
				$out[$endpoint . '/' . $name] = $this->callEndpoint(endpoint: $endpoint, scenario: $scenario);
			}
		}

		$path   = dirname(__DIR__, 2) . '/fixtures/object-actions/endpoints.json';
		$actual = json_decode((string)json_encode($out, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR), $actual);
	}
}
