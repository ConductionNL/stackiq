<?php

/**
 * Golden test of the CMDB import mapping overview and the load refusals.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Cmdb
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Cmdb;

require_once __DIR__ . '/../../Support/CmdbTestSupport.php';
require_once __DIR__ . '/../../Support/FakeMigrationPackService.php';

use OCA\Stackiq\Exception\CmdbImportException;
use OCA\Stackiq\Service\Cmdb\CmdbImportPackMarker;
use OCA\Stackiq\Service\Cmdb\CmdbImportProfile;
use OCA\Stackiq\Tests\Unit\Support\CmdbTestSupport;
use OCA\Stackiq\Tests\Unit\Support\FakeMigrationPackService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Pins the whole mapping overview and every load refusal, so the profile can be split safely.
 */
class CmdbImportProfileOverviewGoldenTest extends TestCase {
	/**
	 * A shipped pack, decoded.
	 *
	 * @param string $file The file name.
	 *
	 * @return array<string, mixed>
	 */
	private function shipped(string $file): array {
		return json_decode((string)file_get_contents(CmdbTestSupport::appRoot() . '/lib/Settings/cmdb-import/' . $file), true);
	}//end shipped()

	/**
	 * A profile on the fake OpenRegister service.
	 *
	 * @param FakeMigrationPackService $service The fake service.
	 *
	 * @return CmdbImportProfile
	 */
	private function storedProfile(FakeMigrationPackService $service): CmdbImportProfile {
		$config = [];
		return new CmdbImportProfile(
			container: $service->container(test: $this, appConfig: FakeMigrationPackService::appConfig(test: $this, values: $config))
		);
	}//end storedProfile()

	/**
	 * A profile without OpenRegister's store.
	 *
	 * @return CmdbImportProfile
	 */
	private function fileProfile(): CmdbImportProfile {
		$config = [];
		$container = $this->createMock(ContainerInterface::class);
		$appConfig = FakeMigrationPackService::appConfig(test: $this, values: $config);
		$factory = CmdbTestSupport::translationFactory(test: $this);
		$container->method('has')->willReturnCallback(fn (string $id): bool => $id === \OCP\IAppConfig::class || $id === \OCP\L10N\IFactory::class);
		$container->method('get')->willReturnCallback(fn (string $id): object => $id === \OCP\L10N\IFactory::class ? $factory : $appConfig);
		return new CmdbImportProfile(container: $container);
	}//end fileProfile()

	/**
	 * The service with an edited module pack and an outdated usage pack.
	 *
	 * @return FakeMigrationPackService
	 */
	private function editedService(): FakeMigrationPackService {
		$service = new FakeMigrationPackService();
		$module = (new CmdbImportPackMarker())->seed(shipped: $this->shipped(file: 'topdesk-module.json'));
		$module['fieldMappings'][7]['transform']['map']['Cloud'] = ['SaaS'];
		$module['version'] = '2.0.1';
		$service->put(definition: $module);
		$usage = $this->shipped(file: 'topdesk-usage.json');
		$usage['version'] = '0.9.0';
		$service->put(definition: (new CmdbImportPackMarker())->seed(shipped: $usage));
		return $service;
	}//end editedService()

	/**
	 * The error code, message and details of a refused load.
	 *
	 * @param callable $load Loads the profile.
	 *
	 * @return array<string, mixed>
	 */
	private function refusal(callable $load): array {
		try {
			$load();
		} catch (CmdbImportException $e) {
			return ['code' => $e->getErrorCode(), 'message' => $e->getMessage(), 'details' => $e->getDetails()];
		}

		return ['code' => null];
	}//end refusal()

	/**
	 * The overview and the refusals match the recorded golden.
	 *
	 * @return void
	 */
	public function testOverviewAndRefusalsMatchTheGolden(): void {
		CmdbTestSupport::loadMigrationPack();
		$out = [];
		$out['files'] = $this->fileProfile()->mappingOverview();
		$out['view'] = $this->storedProfile(service: $this->editedService())->mappingOverview();
		$loaded = $this->storedProfile(service: $this->editedService());
		$loaded->load();
		$out['loaded'] = $loaded->mappingOverview();

		$invalid = new FakeMigrationPackService();
		$usage = (new CmdbImportPackMarker())->seed(shipped: $this->shipped(file: 'topdesk-usage.json'));
		$usage['fieldMappings'][0]['transform'] = ['type' => 'uppercase'];
		$invalid->put(definition: $usage);
		$out['invalid stored'] = $this->refusal(load: fn () => $this->storedProfile(service: $invalid)->load());

		$broken = new FakeMigrationPackService();
		$module = (new CmdbImportPackMarker())->seed(shipped: $this->shipped(file: 'topdesk-module.json'));
		$module['fieldMappings'][2]['target'] = 'externalKey';
		$broken->put(definition: $module);
		$out['rule broken stored'] = $this->refusal(load: fn () => $this->storedProfile(service: $broken)->load());

		$path = dirname(__DIR__, 3) . '/fixtures/cmdb/import-profile-overview.json';
		$this->assertSame(
			json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR),
			json_decode((string)json_encode($out, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)
		);
	}//end testOverviewAndRefusalsMatchTheGolden()
}//end class
