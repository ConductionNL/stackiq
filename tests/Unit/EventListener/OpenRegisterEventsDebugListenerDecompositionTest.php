<?php

/**
 * Unit tests for the decomposed OpenRegisterEventsDebugListener helpers.
 *
 * Covers method-decomposition task 8.8 — split extractEventData() into
 * per-event-family extractors.
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-8-8
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\EventListener;

use OCA\SoftwareCatalog\EventListener\OpenRegisterEventsDebugListener;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the per-family extractors carved out of
 * OpenRegisterEventsDebugListener::extractEventData.
 *
 * @category Test
 * @package  OCA\SoftwareCatalog\Tests\Unit\EventListener
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-8-8
 */
class OpenRegisterEventsDebugListenerDecompositionTest extends TestCase {

	/**
	 * Build the listener with a no-op logger.
	 *
	 * @return OpenRegisterEventsDebugListener
	 */
	private function makeListener(): OpenRegisterEventsDebugListener {
		return new OpenRegisterEventsDebugListener(new NullLogger(), true);
	}//end makeListener()

	/**
	 * Each per-family extractor returns null for an unknown event type,
	 * so extractEventData falls through to the Unknown branch.
	 *
	 * @return void
	 */
	public function testExtractEventDataReturnsUnknownForUnknownEvent(): void {
		$listener = $this->makeListener();
		$event = new class extends Event {
		};

		$reflection = new \ReflectionMethod($listener, 'extractEventData');
		$reflection->setAccessible(true);

		$payload = $reflection->invoke($listener, $event);

		$this->assertSame('Unknown', $payload['eventType']);
		$this->assertSame(get_class($event), $payload['eventClass']);
		$this->assertArrayHasKey('note', $payload);

	}//end testExtractEventDataReturnsUnknownForUnknownEvent()

	/**
	 * Each per-family extractor returns null when the event doesn't match.
	 *
	 * @return void
	 */
	public function testPerFamilyExtractorsReturnNullForUnknownEvent(): void {
		$listener = $this->makeListener();
		$event = new class extends Event {
		};

		foreach (
			[
				'extractObjectEventData',
				'extractRegisterEventData',
				'extractSchemaEventData',
				'extractOrganisationEventData',
			] as $methodName
		) {
			$reflection = new \ReflectionMethod($listener, $methodName);
			$reflection->setAccessible(true);
			$this->assertNull(
				$reflection->invoke($listener, $event),
				"{$methodName} should return null for unrelated events"
			);
		}

	}//end testPerFamilyExtractorsReturnNullForUnknownEvent()

}//end class
