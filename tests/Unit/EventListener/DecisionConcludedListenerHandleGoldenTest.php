<?php

/**
 * Golden test for DecisionConcludedListener::handle().
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\EventListener;

use OCA\Stackiq\EventListener\DecisionConcludedListener;
use OCA\Stackiq\Service\ContractApprovalService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/decision-conclusion-event-doubles.php';

/**
 * Pins which concluded decisions are projected onto a contract, and how failures are logged.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\EventListener
 */
final class DecisionConcludedListenerHandleGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/decision-concluded/handle.json';

	/**
	 * The cases.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function cases(): array {
		$own = ContractApprovalService::SOURCE_APP;
		return [
			'other event'          => ['event' => 'plain'],
			'decidiq, other app'   => ['event' => 'decidiq', 'source' => 'procest'],
			'decidiq, own'         => ['event' => 'decidiq', 'source' => $own],
			'decidesk, own'        => ['event' => 'decidesk', 'source' => $own],
			'no subject'           => ['event' => 'decidiq', 'source' => $own, 'subject' => null],
			'no contract resolved' => ['event' => 'decidiq', 'source' => $own, 'resolved' => null],
			'resolve fails'        => ['event' => 'decidesk', 'source' => $own, 'resolveFails' => true],
			'projection fails'     => ['event' => 'decidiq', 'source' => $own, 'projectFails' => true],
		];
	}//end cases()

	/**
	 * Run one case and record the trace.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<int, array<int, mixed>>
	 */
	private function record(array $case): array {
		$trace    = [];
		$approval = $this->createMock(ContractApprovalService::class);
		$approval->method('resolveContractForOutcome')->willReturnCallback(function (...$args) use (&$trace, $case): ?string {
			$trace[] = ['resolveContractForOutcome', $args];
			if (($case['resolveFails'] ?? false) === true) {
				throw new \RuntimeException('register down');
			}

			return array_key_exists('resolved', $case) === true ? $case['resolved'] : 'contract-1';
		});
		$approval->method('projectOutcome')->willReturnCallback(function (...$args) use (&$trace, $case): bool {
			$trace[] = ['projectOutcome', $args];
			if (($case['projectFails'] ?? false) === true) {
				throw new \Error('write failed');
			}

			return true;
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(function (string $message, array $context = []) use (&$trace): void {
			$trace[] = ['error', $message, $context];
		});

		$subject = array_key_exists('subject', $case) === true ? $case['subject'] : 'subject-1';
		$source  = ($case['source'] ?? '');
		$event   = match ($case['event']) {
			'plain'    => new Event(),
			'decidiq'  => new \OCA\Decidiq\Event\DecisionConcludedEvent('decision-1', 'approved', $source, $subject, 'ext-1'),
			'decidesk' => new \OCA\Decidesk\Event\DecisionConcludedEvent('decision-2', 'rejected', $source, $subject, 'ext-2'),
		};

		(new DecisionConcludedListener($approval, $logger))->handle($event);

		return $trace;
	}//end record()

	/**
	 * Every case matches the recorded trace.
	 *
	 * @return void
	 */
	public function testHandleMatchesGolden(): void {
		$actual = [];
		foreach (self::cases() as $name => $case) {
			$actual[$name] = $this->record(case: $case);
		}

		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$this->assertSame(json_decode((string) file_get_contents(self::FIXTURE), true), json_decode((string) json_encode($actual), true));

	}//end testHandleMatchesGolden()
}//end class
