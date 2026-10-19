<?php

/**
 * Tests for the repair step that stores the CMDB import packs in OpenRegister.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Repair
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Repair;

require_once __DIR__ . '/../Support/CmdbTestSupport.php';
require_once __DIR__ . '/../Support/FakeMigrationPackService.php';

use OCA\Stackiq\Repair\SeedCmdbImportPacks;
use OCA\Stackiq\Service\Cmdb\CmdbImportPackMarker;
use OCA\Stackiq\Service\Cmdb\CmdbImportPackStore;
use OCA\Stackiq\Service\Cmdb\CmdbImportProfile;
use OCA\Stackiq\Tests\Unit\Support\CmdbTestSupport;
use OCA\Stackiq\Tests\Unit\Support\FakeMigrationPackService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * SeedCmdbImportPacks against an in-memory MigrationPackService.
 */
class SeedCmdbImportPacksTest extends TestCase {
	/**
	 * What the step wrote to its output, as "info: …" and "warning: …".
	 *
	 * @var array<int, string>
	 */
	private array $lines = [];

	/**
	 * An output that records its lines.
	 *
	 * @return IOutput
	 */
	private function recordingOutput(): IOutput {
		$this->lines = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $message): void {
			$this->lines[] = 'info: ' . $message;
		});
		$output->method('warning')->willReturnCallback(function (string $message): void {
			$this->lines[] = 'warning: ' . $message;
		});

		return $output;
	}//end output()

	/**
	 * The step on the fake service.
	 *
	 * @param FakeMigrationPackService $service The fake service.
	 * @param array<string, string> $config The app config values.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return SeedCmdbImportPacks
	 */
	private function step(FakeMigrationPackService $service, array &$config, ?LoggerInterface $logger = null): SeedCmdbImportPacks {
		$container = $service->container(test: $this, appConfig: FakeMigrationPackService::appConfig(test: $this, values: $config));
		return new SeedCmdbImportPacks(
			profile: new CmdbImportProfile(container: $container),
			store: new CmdbImportPackStore(container: $container),
			logger: ($logger ?? $this->createMock(LoggerInterface::class))
		);
	}//end step()

	/**
	 * The step is a repair step and names itself.
	 *
	 * @return void
	 */
	public function testItIsARepairStepWithAName(): void {
		$config = [];
		$step = $this->step(service: new FakeMigrationPackService(), config: $config);

		$this->assertInstanceOf(IRepairStep::class, $step);
		$this->assertSame('Seed the CMDB import mapping packs into OpenRegister', $step->getName());
	}//end testItIsARepairStepWithAName()

	/**
	 * A fresh store gets the four sheet packs as built-in packs, and their ids are recorded; the municipality pack never.
	 *
	 * @return void
	 */
	public function testTheSheetPacksAreSeeded(): void {
		CmdbTestSupport::loadMigrationPack();
		$service = new FakeMigrationPackService();
		$config = [];

		$this->step(service: $service, config: $config)->run($this->recordingOutput());

		$this->assertSame(
			['create stackiq-topdesk-module', 'create stackiq-topdesk-manufacturer', 'create stackiq-topdesk-usage', 'create stackiq-topdesk-business-owner'],
			$service->writes
		);
		$this->assertSame([true], array_values(array_unique(array_column($service->rows, 'builtin'))));
		$this->assertCount(4, json_decode($config[CmdbImportPackStore::CONFIG_KEY], true));
		$this->assertCount(4, preg_grep('/^info: Seeded CMDB import pack stackiq-topdesk-/', $this->lines));

		$this->step(service: $service, config: $config)->run($this->recordingOutput());
		$this->assertCount(4, $service->writes, 'a second run writes nothing');
		$this->assertCount(4, preg_grep('/already present$/', $this->lines));
	}//end testTheSheetPacksAreSeeded()

	/**
	 * An unedited pack from an older release is updated; an edited one is kept with a warning.
	 *
	 * @return void
	 */
	public function testUneditedPacksAreUpdatedAndEditedOnesKept(): void {
		CmdbTestSupport::loadMigrationPack();
		$service = new FakeMigrationPackService();
		$older = json_decode((string)file_get_contents(CmdbTestSupport::appRoot() . '/lib/Settings/cmdb-import/topdesk-usage.json'), true);
		$older['version'] = '1.9.0';
		unset($older['fieldMappings'][0]['transform']['map']['Besteld']);
		$usageId = $service->put(definition: (new CmdbImportPackMarker())->seed(shipped: $older));

		$olderOwner = json_decode((string)file_get_contents(CmdbTestSupport::appRoot() . '/lib/Settings/cmdb-import/topdesk-business-owner.json'), true);
		$olderOwner['version'] = '1.9.0';
		$edited = (new CmdbImportPackMarker())->seed(shipped: $olderOwner);
		$edited['fieldMappings'][1]['source'] = 'Functie eigenaar';
		$ownerId = $service->put(definition: $edited);
		$config = [];

		$this->step(service: $service, config: $config)->run($this->recordingOutput());

		$this->assertContains('update ' . $usageId, $service->writes);
		$this->assertNotContains('update ' . $ownerId, $service->writes);
		$this->assertSame('Functie eigenaar', $service->definition(id: $ownerId)['fieldMappings'][1]['source']);
		$this->assertContains(
			'info: Updated unedited CMDB import pack stackiq-topdesk-usage (id ' . $usageId . ') to version 1.0.0',
			$this->lines
		);
		$this->assertContains(
			'warning: CMDB import pack stackiq-topdesk-business-owner (id ' . $ownerId . ') was edited in OpenRegister and is kept; '
			. 'this release ships version 1.1.0, which is not applied',
			$this->lines
		);
	}//end testUneditedPacksAreUpdatedAndEditedOnesKept()

	/**
	 * Without OpenRegister's store the step does nothing and does not throw.
	 *
	 * @return void
	 */
	public function testWithoutTheStoreNothingIsSeeded(): void {
		$container = CmdbTestSupport::emptyContainer(test: $this);
		$step = new SeedCmdbImportPacks(
			profile: new CmdbImportProfile(container: $container),
			store: new CmdbImportPackStore(container: $container),
			logger: $this->createMock(LoggerInterface::class)
		);

		$step->run($this->recordingOutput());

		$this->assertSame(['info: OpenRegister migration pack store not available; CMDB import packs not seeded'], $this->lines);
	}//end testWithoutTheStoreNothingIsSeeded()

	/**
	 * A failing store is reported per pack and logged, never thrown, so an install is not aborted.
	 *
	 * @return void
	 */
	public function testAFailingStoreIsReportedNotThrown(): void {
		CmdbTestSupport::loadMigrationPack();
		$service = new FakeMigrationPackService();
		$service->failure = new RuntimeException('database gone');
		$config = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(4))->method('warning');

		$this->step(service: $service, config: $config, logger: $logger)->run($this->recordingOutput());

		$this->assertCount(4, preg_grep('/^warning: CMDB import pack stackiq-topdesk-[a-z-]+ not seeded: /', $this->lines));
		$this->assertSame([], $service->writes);
	}//end testAFailingStoreIsReportedNotThrown()

	/**
	 * The step is registered after InitializeSettings, on install and on upgrade.
	 *
	 * @return void
	 */
	public function testItIsRegisteredOnInstallAndUpgrade(): void {
		// Read the bytes, not the path: Nextcloud's external entity loader makes
		// simplexml_load_file() return false for a valid file in CI.
		$info = simplexml_load_string((string) file_get_contents(__DIR__ . '/../../../appinfo/info.xml'));
		$this->assertNotFalse($info);
		foreach (['install', 'post-migration'] as $block) {
			$steps = array_map('strval', iterator_to_array($info->{'repair-steps'}->{$block}->step, false));
			$seed = array_search(SeedCmdbImportPacks::class, $steps, true);
			$this->assertIsInt($seed, $block);
			$this->assertGreaterThan(array_search('OCA\Stackiq\Repair\InitializeSettings', $steps, true), $seed, $block);
		}
	}//end testItIsRegisteredOnInstallAndUpgrade()
}//end class
