<?php

/**
 * Tests for the `view_document_export` initial state the Export menu reads.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\AppInfo
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-003-the-document-actions-shall-say-why-they-are-unavailable-when-filinq-cannot-take-the-request
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\AppInfo;

if (class_exists('\\OCA\\Filinq\\Event\\DocumentGenerationRequestedEvent') === false) {
	require_once __DIR__ . '/../../Stubs/Filinq/Event/DocumentGenerationRequestedEvent.php';
}

use Closure;
use OCA\Filinq\Event\DocumentGenerationRequestedEvent;
use OCA\Stackiq\AppInfo\Application;
use OCA\Stackiq\Service\ViewDocumentGateway;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * The flag is provided lazily under `view_document_export` and answers whether filinq's contract resolves.
 *
 * @covers \OCA\Stackiq\AppInfo\Application
 * @uses   \OCA\Stackiq\Service\ViewDocumentGateway
 */
class ApplicationViewDocumentExportTest extends TestCase {

	/**
	 * The flag answers true when the contract resolves.
	 *
	 * @return void
	 */
	public function testTheFlagIsTrueWhenTheContractResolves(): void {
		$this->assertTrue(condition: $this->providedFlag(contractEvent: DocumentGenerationRequestedEvent::class));
	}//end testTheFlagIsTrueWhenTheContractResolves()

	/**
	 * The flag answers false when filinq's contract is absent.
	 *
	 * @return void
	 */
	public function testTheFlagIsFalseWithoutTheContract(): void {
		$this->assertFalse(condition: $this->providedFlag(contractEvent: '\\OCA\\Filinq\\Event\\NoSuchEvent'));
	}//end testTheFlagIsFalseWithoutTheContract()

	/**
	 * Providing the state does not touch the container; only rendering the page does.
	 *
	 * @return void
	 */
	public function testTheGatewayIsOnlyAskedWhenThePageRenders(): void {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$initialState = $this->createMock(originalClassName: IInitialState::class);
		$initialState->expects($this->once())
			->method('provideLazyInitialState')
			->with('view_document_export', $this->isInstanceOf(className: Closure::class));
		$initialState->expects($this->never())->method('provideInitialState');

		$this->provide(initialState: $initialState, container: $container);
	}//end testTheGatewayIsOnlyAskedWhenThePageRenders()

	/**
	 * Provide the flag with a gateway naming the given contract, then render it.
	 *
	 * @param string $contractEvent The contract class name the gateway probes.
	 *
	 * @return bool The value the page would receive.
	 */
	private function providedFlag(string $contractEvent): bool {
		$gateway = new ViewDocumentGateway(
			eventDispatcher: $this->createMock(originalClassName: IEventDispatcher::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			contractEvent: $contractEvent
		);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->expects($this->once())->method('get')->with(ViewDocumentGateway::class)->willReturn($gateway);

		$provided = null;
		$initialState = $this->createMock(originalClassName: IInitialState::class);
		$initialState->method('provideLazyInitialState')->willReturnCallback(
			static function (string $key, Closure $closure) use (&$provided): void {
				$provided = [$key, $closure];
			}
		);

		$this->provide(initialState: $initialState, container: $container);

		$this->assertSame(expected: 'view_document_export', actual: $provided[0]);
		return ($provided[1])();
	}//end providedFlag()

	/**
	 * Call the private provider on an application built without its constructor.
	 *
	 * @param IInitialState      $initialState The initial state service.
	 * @param ContainerInterface $container    The container.
	 *
	 * @return void
	 */
	private function provide(IInitialState $initialState, ContainerInterface $container): void {
		$app = (new ReflectionClass(objectOrClass: Application::class))->newInstanceWithoutConstructor();
		(new ReflectionMethod(objectOrMethod: Application::class, method: 'provideViewDocumentExport'))->invoke($app, $initialState, $container);
	}//end provide()
}//end class
