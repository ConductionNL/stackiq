<?php

/**
 * Unit tests for the decomposed ViewController helpers.
 *
 * Covers method-decomposition task 9.6 — extract status-code helpers and
 * the list error payload from `getAllViews()` / `getView()`.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-9-6
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\ViewController;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the private helpers extracted from ViewController.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Controller
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-9-6
 */
class ViewControllerDecompositionTest extends TestCase {

	/**
	 * Build a ViewController without invoking the constructor — none of the
	 * helpers touch a property.
	 *
	 * @return ViewController
	 */
	private function makeController(): ViewController {
		$reflection = new \ReflectionClass(ViewController::class);
		return $reflection->newInstanceWithoutConstructor();
	}//end makeController()

	/**
	 * Verifier for a private method via reflection.
	 *
	 * @param ViewController $controller The controller under test.
	 * @param string $method The private method name.
	 * @param mixed ...$args Arguments to forward.
	 *
	 * @return mixed The invoked method's return value.
	 */
	private function invoke(ViewController $controller, string $method, mixed ...$args): mixed {
		$reflection = new \ReflectionMethod($controller, $method);
		$reflection->setAccessible(true);
		return $reflection->invokeArgs($controller, $args);
	}//end invoke()

	/**
	 * Success → 200; failure → 500 for the list endpoint.
	 *
	 * @return void
	 */
	public function testDetermineListStatusCode(): void {
		$controller = $this->makeController();

		$this->assertSame(200, $this->invoke($controller, 'determineListStatusCode', ['success' => true]));
		$this->assertSame(500, $this->invoke($controller, 'determineListStatusCode', ['success' => false]));

	}//end testDetermineListStatusCode()

	/**
	 * Single-view status code resolution covers 200 / 404 / 500.
	 *
	 * @return void
	 */
	public function testDetermineViewStatusCode(): void {
		$controller = $this->makeController();

		$this->assertSame(
			200,
			$this->invoke($controller, 'determineViewStatusCode', ['success' => true, 'view' => ['id' => 'v1']])
		);
		$this->assertSame(
			404,
			$this->invoke($controller, 'determineViewStatusCode', ['success' => false, 'view' => null])
		);
		$this->assertSame(
			500,
			$this->invoke($controller, 'determineViewStatusCode', ['success' => false, 'view' => ['id' => 'v1']])
		);

	}//end testDetermineViewStatusCode()

	/**
	 * buildListErrorPayload returns the canonical empty-list error envelope
	 * with the exception's message wired in.
	 *
	 * @return void
	 */
	public function testBuildListErrorPayloadIncludesExceptionMessage(): void {
		$controller = $this->makeController();

		$payload = $this->invoke(
			$controller,
			'buildListErrorPayload',
			new \RuntimeException('boom')
		);

		$this->assertSame(false, $payload['success']);
		$this->assertStringContainsString('boom', $payload['error']);
		$this->assertSame([], $payload['views']);
		$this->assertSame(0, $payload['count']);

	}//end testBuildListErrorPayloadIncludesExceptionMessage()

}//end class
