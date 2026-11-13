<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction b.v. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Repair;

use OCA\Stackiq\Repair\BackfillModuleVersionPublication;
use OCA\Stackiq\Repair\InitializeSettings;
use OCA\Stackiq\Repair\MigrateContactsToNc;
use OCA\Stackiq\Repair\RenameCollidingSchemaSlugs;
use OCA\Stackiq\Repair\RenameDutchSchemaSlugs;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pins the human-readable names the repair steps report to `occ`'s repair
 * output, and the privilege-elevation helper that runs a unit of work as the
 * system context when the object service supports it and inline otherwise.
 *
 * @spec openspec/specs/repair-init/spec.md
 */
class RepairStepNamesAndElevationTest extends TestCase {
	/**
	 * @return array<string, array{class-string, string}>
	 */
	public static function stepNames(): array {
		return [
			'initialize settings'   => [InitializeSettings::class, 'Initialize Stackiq settings'],
			'backfill publication'  => [BackfillModuleVersionPublication::class, 'Copy each application\'s publication onto its versions'],
			'namespace slug clash'  => [RenameCollidingSchemaSlugs::class, 'Namespace the stackiq schema slugs that collided with another app'],
			'rename dutch slugs'    => [RenameDutchSchemaSlugs::class, 'Rename Dutch Stackiq schema slugs'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('stepNames')]
	public function testEachRepairStepReportsItsName(string $class, string $expected): void {
		$step = (new ReflectionClass($class))->newInstanceWithoutConstructor();
		$this->assertSame($expected, $step->getName());
	}

	private function callRunElevated(object $objectService, callable $work)
	{
		$migrate = (new ReflectionClass(MigrateContactsToNc::class))->newInstanceWithoutConstructor();
		$method  = new ReflectionMethod($migrate, 'runElevated');
		$method->setAccessible(true);
		return $method->invoke($migrate, $objectService, $work);
	}

	public function testRunElevatedUsesTheSystemContextWhenTheServiceOffersIt(): void {
		$service = new class {
			public bool $elevated = false;

			public function runAsSystem(callable $work) {
				$this->elevated = true;
				return $work();
			}
		};

		$result = $this->callRunElevated($service, static fn () => 'done');

		$this->assertSame('done', $result);
		$this->assertTrue($service->elevated);
	}

	public function testRunElevatedRunsInlineWhenThereIsNoSystemContext(): void {
		$service = new class {
		};
		$ran = false;

		$result = $this->callRunElevated($service, function () use (&$ran) {
			$ran = true;
			return 42;
		});

		$this->assertSame(42, $result);
		$this->assertTrue($ran);
	}
}
