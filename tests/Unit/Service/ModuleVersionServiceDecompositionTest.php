<?php

/**
 * Unit tests for the decomposed ModuleVersionService helpers.
 *
 * Covers method-decomposition task 9.5 (split ensureDefaultVersion into
 * fetchVersionData / compareVersions / updateVersionRecord).
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-9-5
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use OCA\SoftwareCatalog\Service\ModuleVersionService;
use OCA\SoftwareCatalog\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the private helpers extracted from
 * ModuleVersionService::ensureDefaultVersion.
 *
 * @category Test
 * @package  OCA\SoftwareCatalog\Tests\Unit\Service
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-9-5
 */
class ModuleVersionServiceDecompositionTest extends TestCase {

	/**
	 * Build a service with stub collaborators — none are exercised by the
	 * `compareVersions` cases, but the constructor requires them.
	 *
	 * @return ModuleVersionService
	 */
	private function makeService(): ModuleVersionService {
		return new ModuleVersionService(
			$this->createMock(ContainerInterface::class),
			$this->createMock(SettingsService::class),
			$this->createMock(LoggerInterface::class),
		);

	}//end makeService()

	/**
	 * compareVersions returns true when at least one version is present.
	 *
	 * @return void
	 */
	public function testCompareVersionsTrueWhenVersionsExist(): void {
		$service = $this->makeService();
		$reflection = new \ReflectionMethod($service, 'compareVersions');
		$reflection->setAccessible(true);

		$this->assertTrue($reflection->invoke($service, ['versionCount' => 1]));
		$this->assertTrue($reflection->invoke($service, ['versionCount' => 42]));

	}//end testCompareVersionsTrueWhenVersionsExist()

	/**
	 * compareVersions returns false when no versions are present, regardless
	 * of whether the key is missing or zero.
	 *
	 * @return void
	 */
	public function testCompareVersionsFalseWhenNoVersions(): void {
		$service = $this->makeService();
		$reflection = new \ReflectionMethod($service, 'compareVersions');
		$reflection->setAccessible(true);

		$this->assertFalse($reflection->invoke($service, ['versionCount' => 0]));
		$this->assertFalse($reflection->invoke($service, []));

	}//end testCompareVersionsFalseWhenNoVersions()

	/**
	 * The helpers exist and have the documented private visibility — guards
	 * against accidental visibility drift in future edits.
	 *
	 * @return void
	 */
	public function testDecomposedHelpersExistAndArePrivate(): void {
		$reflection = new \ReflectionClass(ModuleVersionService::class);

		foreach (['fetchVersionData', 'compareVersions', 'updateVersionRecord'] as $method) {
			$this->assertTrue(
				$reflection->hasMethod($method),
				sprintf('Expected helper %s() on ModuleVersionService', $method)
			);
			$this->assertTrue(
				$reflection->getMethod($method)->isPrivate(),
				sprintf('Helper %s() must remain private', $method)
			);
		}

	}//end testDecomposedHelpersExistAndArePrivate()

}//end class
