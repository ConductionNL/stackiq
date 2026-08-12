<?php

/**
 * Unit tests for the decomposed ModuleComplianceSubscriber helpers.
 *
 * Covers method-decomposition task 9.2 (split handle() into
 * extractObjectFromEvent / isModuleObject / dispatchComplianceUpdate /
 * dispatchEnsureDefaultVersion).
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-9-2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\EventListener;

use OCA\SoftwareCatalog\EventListener\ModuleComplianceSubscriber;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for the private helpers extracted from
 * ModuleComplianceSubscriber::handle.
 *
 * @category Test
 * @package  OCA\SoftwareCatalog\Tests\Unit\EventListener
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-9-2
 */
class ModuleComplianceSubscriberDecompositionTest extends TestCase {

	/**
	 * Build a subscriber with a stub container.
	 *
	 * @return ModuleComplianceSubscriber
	 */
	private function makeSubscriber(): ModuleComplianceSubscriber {
		return new ModuleComplianceSubscriber($this->createMock(ContainerInterface::class));
	}//end makeSubscriber()

	/**
	 * extractObjectFromEvent returns null for unsupported event types.
	 *
	 * @return void
	 */
	public function testExtractObjectFromUnsupportedEventReturnsNull(): void {
		$subscriber = $this->makeSubscriber();
		$reflection = new \ReflectionMethod($subscriber, 'extractObjectFromEvent');
		$reflection->setAccessible(true);

		$event = new class extends Event {
		};

		$this->assertNull($reflection->invoke($subscriber, $event));

	}//end testExtractObjectFromUnsupportedEventReturnsNull()

}//end class
