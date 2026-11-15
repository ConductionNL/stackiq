<?php

/**
 * Tests the order in which the composition root registers the domain services.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\AppInfo
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\AppInfo;

use OCA\Stackiq\AppInfo\Application;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Application::registerDomainServices() registers the recorded services and
 * dashboard widget, in the recorded order.
 *
 * @coversNothing
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-9-1
 */
class DomainServiceRegistrationOrderTest extends TestCase {

	/**
	 * The golden file.
	 *
	 * @return string
	 */
	private static function golden(): string {
		return __DIR__ . '/golden/domainServiceRegistrations.json';

	}//end golden()

	/**
	 * The registrations match the recording.
	 *
	 * @return void
	 */
	public function testRegistrationsMatchTheRecording(): void {
		$registrations = [];
		$context       = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			function (string $name, \Closure $factory, bool $shared = true) use (&$registrations): void {
				$registrations[] = ['service', $name, $shared];
			}
		);
		$context->method('registerDashboardWidget')->willReturnCallback(
			function (string $widget) use (&$registrations): void {
				$registrations[] = ['widget', $widget];
			}
		);

		$application = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
		(new ReflectionMethod($application, 'registerDomainServices'))->invoke($application, $context);

		if (getenv(name: 'RECORD_GOLDEN') !== false) {
			file_put_contents(filename: self::golden(), data: json_encode(value: $registrations, flags: (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
			$this->addToAssertionCount(count: 1);
			return;
		}

		$this->assertSame(
			expected: json_decode(json: (string) file_get_contents(filename: self::golden()), associative: true),
			actual: $registrations
		);

	}//end testRegistrationsMatchTheRecording()
}//end class
