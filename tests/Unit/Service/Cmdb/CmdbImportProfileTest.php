<?php

/**
 * Tests for the CMDB import profile and its six mapping packs.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Cmdb
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Cmdb;

require_once __DIR__ . '/../../Support/CmdbTestSupport.php';
require_once __DIR__ . '/../../Support/FakeMigrationPackService.php';

use OCA\OpenRegister\Service\MigrationPack\MappingEngine;
use OCA\OpenRegister\Service\MigrationPack\PackDefinitionValidator;
use OCA\Stackiq\Exception\CmdbImportException;
use OCA\Stackiq\Service\Cmdb\CmdbImportPackMarker;
use OCA\Stackiq\Service\Cmdb\CmdbImportPackStore;
use OCA\Stackiq\Service\Cmdb\CmdbImportProfile;
use OCA\Stackiq\Tests\Unit\Support\CmdbTestSupport;
use OCA\Stackiq\Tests\Unit\Support\FakeMigrationPackService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * The packs are valid OpenRegister packs that implement design.md's column table.
 */
class CmdbImportProfileTest extends TestCase {
	/**
	 * A container that knows nothing, so the validator comes from class_exists.
	 *
	 * @return ContainerInterface
	 */
	private function emptyContainer(): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(false);
		return $container;
	}//end emptyContainer()

	/**
	 * A copy of the shipped profile directory, to break on purpose.
	 *
	 * @return string The directory.
	 */
	private function copyOfShippedDirectory(): string {
		$directory = sys_get_temp_dir() . '/stackiq-cmdb-profile-' . bin2hex(random_bytes(4));
		mkdir($directory);
		foreach (glob(CmdbTestSupport::appRoot() . '/lib/Settings/cmdb-import/*.json') as $file) {
			copy($file, $directory . '/' . basename($file));
		}

		return $directory;
	}//end copyOfShippedDirectory()

	/**
	 * Remove a directory made by copyOfShippedDirectory().
	 *
	 * @param string $directory The directory.
	 *
	 * @return void
	 */
	private function remove(string $directory): void {
		array_map('unlink', glob($directory . '/*.json'));
		rmdir($directory);
	}//end remove()

	/**
	 * Every pack passes OpenRegister's validator as an excel pack with a generated id.
	 *
	 * @return void
	 */
	public function testEveryPackIsAValidOpenRegisterPack(): void {
		CmdbTestSupport::loadMigrationPack();
		$profile = new CmdbImportProfile(container: $this->emptyContainer());
		$profile->load();

		$validator = new PackDefinitionValidator();
		foreach (CmdbImportProfile::TARGETS as $target) {
			$pack = $profile->pack(target: $target);
			$this->assertSame([], $validator->validate($pack), $target);
			$this->assertSame('excel', $pack['sourceFormat'], $target);
			$this->assertSame(['type' => 'generate'], $pack['idStrategy'], $target);
		}
	}//end testEveryPackIsAValidOpenRegisterPack()

	/**
	 * The packs implement the column table of design.md.
	 *
	 * @return void
	 */
	public function testThePacksImplementTheColumnTable(): void {
		CmdbTestSupport::loadMigrationPack();
		$profile = new CmdbImportProfile(container: $this->emptyContainer());
		$profile->load();

		$targets = function (string $pack) use ($profile): array {
			$map = [];
			foreach ($profile->pack(target: $pack)['fieldMappings'] as $mapping) {
				$map[$mapping['source']] = $mapping['target'];
			}

			return $map;
		};

		$this->assertSame(
			[
				'Applicatie Naam' => 'name',
				'APPID' => 'externalNumber',
				'Applicatie Code' => 'externalId',
				'Nickname' => 'shortDescription',
				'Roepnaam' => 'shortDescription',
				'Functionele Omschrijving' => 'longDescription',
				'Applicatiesoort' => 'cloudDienstverleningsmodel',
				'BNN Classificatie' => 'bbnLevel',
				'Datum' => 'externalCreatedAt',
				'Referentie datum wijziging' => 'externalModifiedAt',
			],
			$targets('module')
		);
		$this->assertSame(['Vendor' => 'name'], $targets('manufacturer'));
		$this->assertSame(['municipalityName' => 'name'], $targets('municipality'));
		$this->assertSame(
			['Applicatie Status' => 'status', 'Classificatie' => 'timeClassification', 'End-of-Life Functioneel' => 'startDateOutPhased', 'Beheer' => 'interneAnnotation'],
			$targets('usage')
		);
		$this->assertSame(['Applicatie Eigenaar (Persoon)' => 'name', 'Applicatie Eigenaar (Functie)' => 'role'], $targets('businessOwner'));
		$this->assertSame(['module', 'manufacturer', 'municipality', 'usage', 'businessOwner'], CmdbImportProfile::TARGETS, 'no technical owner');

		$this->assertSame(['type' => 'Supplier', 'status' => 'Active', 'registeredBy' => 'Supplier'], $profile->pack(target: 'manufacturer')['defaults']);
		$this->assertSame(['type' => 'Municipality', 'status' => 'Active'], $profile->pack(target: 'municipality')['defaults']);
		$this->assertSame(['type' => 'Application'], $profile->createOnlyDefaults(target: 'module'));
		$this->assertSame(['interneAnnotation', 'timeClassification'], $profile->createOnlyFields(target: 'usage'), 'status follows TOPdesk on every import');
		$this->assertSame(['publicationDate', 'depublicationDate'], $profile->neverWrittenOnUpdate(target: 'module'));
		$this->assertSame(['APPID', 'Applicatie Naam'], $profile->requiredColumns());
		$this->assertSame('APPID', $profile->keyColumn());
		$this->assertSame(['Onbeh Applicaties CMDB', 'Beheerde Applicaties CMDB'], $profile->sheetNames());
		$this->assertSame(['Beheer' => 'Beheer geregeld: nee'], $profile->sheetConstants(sheetName: 'Onbeh Applicaties CMDB'));
		$this->assertSame(['Beheer' => 'Beheer geregeld: ja'], $profile->sheetConstants(sheetName: 'Beheerde Applicaties CMDB'));
		$this->assertSame(['Nickname'], $profile->absentColumns(sheetName: 'Onbeh Applicaties CMDB'));
		$this->assertSame([], $profile->absentColumns(sheetName: 'Beheerde Applicaties CMDB'));
		$this->assertSame(['BNN Classificatie' => ['NB']], $profile->emptyValues());
		$this->assertSame(10485760, $profile->maxFileBytes());
		$this->assertSame(10000, $profile->maxRowsPerSheet());
		$this->assertSame(10485760, $profile->maxPartBytes());
		$this->assertSame(200000, $profile->maxSharedStrings());
		$this->assertSame(67108864, $profile->maxReferencedStringBytes());
	}//end testThePacksImplementTheColumnTable()

	/**
	 * The lookups map the TOPdesk values through the real engine, and an unknown value errors instead of passing through.
	 *
	 * @return void
	 */
	public function testTheLookupsMapThroughTheEngine(): void {
		CmdbTestSupport::loadMigrationPack();
		$profile = new CmdbImportProfile(container: $this->emptyContainer());
		$profile->load();
		$engine = new MappingEngine();

		$usage = $engine->mapRow(
			$profile->pack(target: 'usage'),
			[
				'Applicatie Status' => 'In voorraad',
				'Classificatie' => 'Tolereren',
				'End-of-Life Functioneel' => '2046-02-01',
				'Beheer' => 'Beheer geregeld: ja',
				'Cluster' => 'H10',
				'Applicatie Eigenaar (Afdeling)' => 'H10 Accounting',
			],
			2
		);
		$this->assertSame([], $usage['errors']);
		$this->assertSame(
			['status' => 'Planned', 'timeClassification' => 'Tolerate', 'startDateOutPhased' => '2046-02-01', 'interneAnnotation' => 'Beheer geregeld: ja / H10 / H10 Accounting'],
			$usage['data']
		);

		$module = $engine->mapRow(
			$profile->pack(target: 'module'),
			['Applicatie Naam' => 'X', 'APPID' => '1', 'BNN Classificatie' => 'BBN 2', 'Applicatiesoort' => 'Saas', 'Nickname' => 'Bijnaam', 'Roepnaam' => 'Roep'],
			2
		);
		$this->assertSame([], $module['errors']);
		$this->assertSame('BBN2', $module['data']['bbnLevel']);
		$this->assertSame(['SaaS'], $module['data']['cloudDienstverleningsmodel']);
		$this->assertSame('Roep', $module['data']['shortDescription'], 'Roepnaam wins over Nickname');
		$nickname = $engine->mapRow($profile->pack(target: 'module'), ['Applicatie Naam' => 'X', 'APPID' => '1', 'Nickname' => 'Bijnaam', 'Roepnaam' => ''], 2);
		$this->assertSame('Bijnaam', $nickname['data']['shortDescription'], 'Nickname when Roepnaam is empty');

		$unknown = $engine->mapRow($profile->pack(target: 'usage'), ['Applicatie Status' => 'Onbekende status'], 3);
		$this->assertArrayNotHasKey('status', $unknown['data']);
		$this->assertSame('Applicatie Status', $unknown['errors'][0]['source']);
		$this->assertStringContainsString('Onbekende status', $unknown['errors'][0]['message']);

		$soort = $engine->mapRow($profile->pack(target: 'module'), ['Applicatie Naam' => 'X', 'APPID' => '1', 'Applicatiesoort' => 'Webapplicatie'], 3);
		$this->assertNull($soort['data']['cloudDienstverleningsmodel'], 'an application kind is not a hosting model; the service leaves the null out');
		$this->assertSame('Webapplicatie', $soort['data']['applicationType']);
		$this->assertSame([], $soort['errors']);
	}//end testTheLookupsMapThroughTheEngine()

	/**
	 * The read allowlist holds the owner columns but no other person or group column, nor
	 * the unmapped columns of the CMDB sheets.
	 *
	 * @return void
	 */
	public function testPersonColumnsAreNeverReferenced(): void {
		CmdbTestSupport::loadMigrationPack();
		$profile = new CmdbImportProfile(container: $this->emptyContainer());
		$columns = $profile->referencedColumns();

		foreach ([
			'Personeelsnummer',
			'Eigenaar',
			'Eigenaar e-mail',
			'FB contactpersoon 1',
			'FB contactpersoon 2',
			'Groepseigenaar mail⚡',
			'Behandelgroep',
			'Hostingpartij',
			'Leverancier',
			'Beschikbaarheid',
			'Rappelreden',
			'Opmerkingen',
			'municipalityName',
			'Beheer',
		] as $never) {
			$this->assertNotContains($never, $columns);
		}

		$this->assertContains('Applicatie Eigenaar (Persoon)', $columns);
		$this->assertContains('Applicatie Eigenaar (Functie)', $columns);
		$this->assertContains('Applicatie Eigenaar (Afdeling)', $columns, 'the concat field is read too');
		$this->assertContains('Cluster', $columns);
	}//end testPersonColumnsAreNeverReferenced()

	/**
	 * A pack with an unknown transform stops the import with MAPPING_UNAVAILABLE (503).
	 *
	 * @return void
	 */
	public function testAnInvalidPackIsMappingUnavailable(): void {
		CmdbTestSupport::loadMigrationPack();
		$directory = $this->copyOfShippedDirectory();
		$pack = json_decode((string)file_get_contents($directory . '/topdesk-usage.json'), true);
		$pack['fieldMappings'][0]['transform'] = ['type' => 'uppercase'];
		file_put_contents($directory . '/topdesk-usage.json', json_encode($pack));

		try {
			(new CmdbImportProfile(container: $this->emptyContainer(), directory: $directory))->load();
			$this->fail('MAPPING_UNAVAILABLE expected');
		} catch (CmdbImportException $e) {
			$this->assertSame('MAPPING_UNAVAILABLE', $e->getErrorCode());
			$this->assertSame(503, $e->getHttpStatus());
			$this->assertStringContainsString('topdesk-usage.json', $e->getMessage());
		} finally {
			$this->remove(directory: $directory);
		}
	}//end testAnInvalidPackIsMappingUnavailable()

	/**
	 * A missing pack file, or a validator the container cannot give and that does not exist, is MAPPING_UNAVAILABLE.
	 *
	 * @return void
	 */
	public function testAMissingPackOrValidatorIsMappingUnavailable(): void {
		CmdbTestSupport::loadMigrationPack();
		$directory = $this->copyOfShippedDirectory();
		unlink($directory . '/topdesk-module.json');

		try {
			(new CmdbImportProfile(container: $this->emptyContainer(), directory: $directory))->load();
			$this->fail('MAPPING_UNAVAILABLE expected');
		} catch (CmdbImportException $e) {
			$this->assertSame('MAPPING_UNAVAILABLE', $e->getErrorCode());
		} finally {
			$this->remove(directory: $directory);
		}

		$profile = new class(container: $this->emptyContainer()) extends CmdbImportProfile {
			public const VALIDATOR_CLASS = 'OCA\OpenRegister\Service\MigrationPack\NoSuchValidator';
		};
		try {
			$profile->load();
			$this->fail('MAPPING_UNAVAILABLE expected without a validator');
		} catch (CmdbImportException $e) {
			$this->assertSame('MAPPING_UNAVAILABLE', $e->getErrorCode());
			$this->assertSame(503, $e->getHttpStatus());
		}
	}//end testAMissingPackOrValidatorIsMappingUnavailable()

	/**
	 * A create-only default keeps its mapped field in createOnlyFields; only an unmapped default leaves it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-show-the-mapping-the-import-uses-req-cmdb-020
	 */
	public function testAMappedCreateOnlyDefaultStaysACreateOnlyField(): void {
		CmdbTestSupport::loadMigrationPack();
		$profile = new class(container: $this->emptyContainer()) extends CmdbImportProfile {
			/**
			 * The usage pack's create-only fields with a create default on one mapped field and one unmapped one.
			 *
			 * @param string $target The pack target.
			 *
			 * @return array<string, mixed>
			 */
			public function createOnlyDefaults(string $target): array {
				if ($target !== 'usage') {
					return parent::createOnlyDefaults(target: $target);
				}

				return ['timeClassification' => 'Tolerate', 'unmappedFlag' => true];
			}

			/**
			 * The usage pack's create-only fields plus the unmapped default's key.
			 *
			 * @param string $target The pack target.
			 *
			 * @return array<int, string>
			 */
			public function createOnlyFields(string $target): array {
				if ($target !== 'usage') {
					return parent::createOnlyFields(target: $target);
				}

				return [...parent::createOnlyFields(target: $target), 'unmappedFlag'];
			}
		};

		$usage = array_values(array_filter($profile->mappingOverview()['packs'], static fn (array $pack): bool => $pack['target'] === 'usage'))[0];

		$this->assertSame(['interneAnnotation', 'timeClassification'], $usage['createOnlyFields'], 'the mapped field stays, the unmapped default leaves');
	}//end testAMappedCreateOnlyDefaultStaysACreateOnlyField()

	/**
	 * The overview the admin settings show is built from the validated packs, and a broken pack breaks it too.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-show-the-mapping-the-import-uses-req-cmdb-020
	 */
	public function testTheOverviewShowsWhatTheImportRuns(): void {
		CmdbTestSupport::loadMigrationPack();
		$profile = new CmdbImportProfile(container: $this->emptyContainer());

		$overview = $profile->mappingOverview();

		$this->assertSame(['profile', 'packs', 'openRegisterPacks'], array_keys($overview));
		$this->assertFalse($overview['openRegisterPacks'], 'no OpenRegister pack store in this container');
		$this->assertSame('topdesk-cmdb', $overview['profile']['id']);
		$this->assertSame($profile->sheets(), $overview['profile']['sheets']);
		$this->assertSame(['Beheerde Applicaties CMDB', 'Onbeh Applicaties CMDB'], $overview['profile']['sheetPrecedence']);
		$this->assertSame($profile->keyColumn(), $overview['profile']['keyColumn']);
		$this->assertSame($profile->requiredColumns(), $overview['profile']['requiredColumns']);
		$this->assertSame($profile->idColumns(), $overview['profile']['idColumns']);
		$this->assertSame($profile->emptyValues(), $overview['profile']['emptyValues']);
		$this->assertSame(CmdbImportProfile::TARGETS, array_column($overview['packs'], 'target'));
		foreach ($overview['packs'] as $pack) {
			$shipped = $profile->pack(target: $pack['target']);
			$this->assertSame($shipped['id'], $pack['id']);
			$this->assertSame($shipped['version'], $pack['version']);
			$this->assertSame(array_column($shipped['fieldMappings'], 'source'), array_column($pack['fieldMappings'], 'source'), $pack['target']);
			$this->assertSame(array_column($shipped['fieldMappings'], 'transform'), array_column($pack['fieldMappings'], 'transform'), $pack['target'] . ' transforms as stored');
			foreach ($pack['fieldMappings'] as $mapping) {
				$this->assertIsBool($mapping['required'], $pack['target']);
			}

			$this->assertSame($profile->createOnlyDefaults(target: $pack['target']), $pack['createOnlyDefaults'], $pack['target']);
			$this->assertSame([], array_intersect($pack['createOnlyFields'], array_diff(array_keys($pack['createOnlyDefaults']), array_column($pack['fieldMappings'], 'target'))), $pack['target']);
			$this->assertSame($profile->neverWrittenOnUpdate(target: $pack['target']), $pack['neverWritten'], $pack['target']);

			$this->assertSame('file', $pack['source'], $pack['target']);
			$this->assertNull($pack['packId'], $pack['target']);
			$this->assertNull($pack['storedVersion'], $pack['target']);
			$this->assertSame($shipped['version'], $pack['shippedVersion'], $pack['target']);
			$this->assertNull($pack['edited'], $pack['target']);
			$this->assertFalse($pack['newerShipped'], $pack['target']);
			$this->assertSame($pack['target'] !== 'municipality', $pack['editable'], $pack['target']);
		}

		$directory = $this->copyOfShippedDirectory();
		unlink($directory . '/topdesk-module.json');
		try {
			(new CmdbImportProfile(container: $this->emptyContainer(), directory: $directory))->mappingOverview();
			$this->fail('MAPPING_UNAVAILABLE expected');
		} catch (CmdbImportException $e) {
			$this->assertSame('MAPPING_UNAVAILABLE', $e->getErrorCode());
			$this->assertStringContainsString('topdesk-module.json', $e->getMessage());
		} finally {
			$this->remove(directory: $directory);
		}
	}//end testTheOverviewShowsWhatTheImportRuns()

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
	 * A profile whose container holds the fake OpenRegister service and an app config.
	 *
	 * @param FakeMigrationPackService $service The fake service.
	 * @param array<string, string> $config The app config values.
	 *
	 * @return CmdbImportProfile
	 */
	private function storedProfile(FakeMigrationPackService $service, array &$config): CmdbImportProfile {
		return new CmdbImportProfile(
			container: $service->container(test: $this, appConfig: FakeMigrationPackService::appConfig(test: $this, values: $config))
		);
	}//end storedProfile()

	/**
	 * The import runs the pack an administrator edited in OpenRegister; missing packs are seeded, the municipality stays a file.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function testAStoredPackIsWhatTheImportRuns(): void {
		CmdbTestSupport::loadMigrationPack();
		$service = new FakeMigrationPackService();
		$module = (new CmdbImportPackMarker())->seed(shipped: $this->shipped(file: 'topdesk-module.json'));
		$module['fieldMappings'][7]['transform']['map']['Cloud'] = ['SaaS'];
		$module['version'] = '2.0.1';
		$moduleId = $service->put(definition: $module);
		$config = [];

		$profile = $this->storedProfile(service: $service, config: $config);
		$overview = $profile->mappingOverview();

		$this->assertSame(['SaaS'], $profile->pack(target: 'module')['fieldMappings'][7]['transform']['map']['Cloud']);
		$this->assertArrayNotHasKey(CmdbImportPackMarker::KEY, $profile->pack(target: 'module'), 'the engine gets the pack without the marker');
		$this->assertSame(
			['create stackiq-topdesk-manufacturer', 'create stackiq-topdesk-usage', 'create stackiq-topdesk-business-owner'],
			$service->writes,
			'the missing sheet packs are seeded; the municipality pack never is'
		);
		$this->assertTrue($overview['openRegisterPacks']);
		$this->assertFalse($profile->usesShippedFallback());

		$byTarget = array_column($overview['packs'], null, 'target');
		$this->assertSame('openregister', $byTarget['module']['source']);
		$this->assertSame($moduleId, $byTarget['module']['packId']);
		$this->assertSame('2.0.1', $byTarget['module']['storedVersion']);
		$this->assertSame('2.0.1', $byTarget['module']['version']);
		$this->assertSame('2.0.0', $byTarget['module']['shippedVersion']);
		$this->assertTrue($byTarget['module']['edited']);
		$this->assertFalse($byTarget['module']['newerShipped'], 'seeded from the pack that still ships');
		$this->assertTrue($byTarget['module']['editable']);
		$this->assertSame('openregister', $byTarget['usage']['source']);
		$this->assertFalse($byTarget['usage']['edited']);
		$this->assertSame('file', $byTarget['municipality']['source']);
		$this->assertNull($byTarget['municipality']['packId']);
		$this->assertFalse($byTarget['municipality']['editable']);

		$recorded = json_decode($config[CmdbImportPackStore::CONFIG_KEY], true);
		$this->assertSame(['stackiq-topdesk-business-owner', 'stackiq-topdesk-manufacturer', 'stackiq-topdesk-module', 'stackiq-topdesk-usage'], array_keys($recorded));
		$this->assertSame($moduleId, $recorded['stackiq-topdesk-module']);
	}//end testAStoredPackIsWhatTheImportRuns()

	/**
	 * An unedited stored pack from an older release follows the shipped pack; an edited one is kept and reported.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function testUneditedPacksFollowTheReleaseAndEditedOnesAreReported(): void {
		CmdbTestSupport::loadMigrationPack();
		$service = new FakeMigrationPackService();
		$olderUsage = $this->shipped(file: 'topdesk-usage.json');
		$olderUsage['version'] = '1.9.0';
		unset($olderUsage['fieldMappings'][0]['transform']['map']['Besteld']);
		$usageId = $service->put(definition: (new CmdbImportPackMarker())->seed(shipped: $olderUsage));

		$olderModule = $this->shipped(file: 'topdesk-module.json');
		$olderModule['version'] = '1.9.0';
		$editedModule = (new CmdbImportPackMarker())->seed(shipped: $olderModule);
		$editedModule['fieldMappings'][0]['transform'] = ['type' => 'trim'];
		$editedModule['description'] = 'Edited by the administrator';
		$moduleId = $service->put(definition: $editedModule);
		$config = [];

		$profile = $this->storedProfile(service: $service, config: $config);
		$byTarget = array_column($profile->mappingOverview()['packs'], null, 'target');

		$this->assertContains('update ' . $usageId, $service->writes);
		$this->assertNotContains('update ' . $moduleId, $service->writes);
		$this->assertSame('Acquisition', $profile->pack(target: 'usage')['fieldMappings'][0]['transform']['map']['Besteld']);
		$this->assertFalse($byTarget['usage']['edited']);
		$this->assertFalse($byTarget['usage']['newerShipped']);
		$this->assertSame('2.0.0', $byTarget['usage']['storedVersion']);

		$this->assertSame('Edited by the administrator', $profile->pack(target: 'module')['description']);
		$this->assertTrue($byTarget['module']['edited']);
		$this->assertTrue($byTarget['module']['newerShipped']);
		$this->assertSame('1.9.0', $byTarget['module']['storedVersion']);
		$this->assertSame('2.0.0', $byTarget['module']['shippedVersion']);
	}//end testUneditedPacksFollowTheReleaseAndEditedOnesAreReported()

	/**
	 * A stored pack OpenRegister's validator refuses stops the import, naming the pack and its id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function testAnInvalidStoredPackIsMappingUnavailable(): void {
		CmdbTestSupport::loadMigrationPack();
		$service = new FakeMigrationPackService();
		$usage = (new CmdbImportPackMarker())->seed(shipped: $this->shipped(file: 'topdesk-usage.json'));
		$usage['fieldMappings'][0]['transform'] = ['type' => 'uppercase'];
		$id = $service->put(definition: $usage);
		$config = [];

		try {
			$this->storedProfile(service: $service, config: $config)->load();
			$this->fail('MAPPING_UNAVAILABLE expected');
		} catch (CmdbImportException $e) {
			$this->assertSame('MAPPING_UNAVAILABLE', $e->getErrorCode());
			$this->assertSame(503, $e->getHttpStatus());
			$this->assertStringStartsWith('OpenRegister migration pack stackiq-topdesk-usage (id ' . $id . ') is invalid: ', $e->getMessage());
			$this->assertStringContainsString('uppercase', $e->getMessage());
			$this->assertSame(['pack' => 'stackiq-topdesk-usage', 'packId' => $id], $e->getDetails());
		}
	}//end testAnInvalidStoredPackIsMappingUnavailable()

	/**
	 * Edits a stored pack may not make, and the rule each one breaks.
	 *
	 * @return array<string, array{0: string, 1: callable, 2: string}>
	 */
	public static function brokenRules(): array {
		$target = static fn (int $index, string $field): callable => static function (array $pack) use ($index, $field): array {
			$pack['fieldMappings'][$index]['target'] = $field;
			return $pack;
		};

		return [
			'module name no longer required' => ['topdesk-module.json', static function (array $pack): array {
				$pack['fieldMappings'][0]['required'] = false;
				return $pack;
			}, '"name" must be mapped as required'],
			'externalNumber from another column' => ['topdesk-module.json', static function (array $pack): array {
				$pack['fieldMappings'][1]['source'] = 'Applicatie Code';
				return $pack;
			}, 'the key column "APPID" must be mapped to "externalNumber" as required'],
			'the object metadata' => ['topdesk-module.json', $target(2, '@self'), '"@self" is set by the import and cannot be mapped'],
			'the match key' => ['topdesk-module.json', $target(2, 'externalKey'), '"externalKey" is set by the import'],
			'a field never written' => ['topdesk-module.json', $target(2, 'publicationDate'), '"publicationDate" is set by the import'],
			'a field the schema lacks' => ['topdesk-module.json', $target(2, 'secretNotes'), '"secretNotes" is not a field the module pack may map'],
			'the usage provider' => ['topdesk-usage.json', $target(0, 'provider'), '"provider" is set by the import'],
			'the usage consumer' => ['topdesk-usage.json', $target(1, 'consumer'), '"consumer" is set by the import'],
			'the usage business owner' => ['topdesk-usage.json', $target(1, 'businessOwner'), '"businessOwner" is set by the import'],
			'a default the import sets' => ['topdesk-usage.json', static function (array $pack): array {
				$pack['defaults'] = ['module' => 'x'];
				return $pack;
			}, 'default "module" is set by the import'],
			'the manufacturer type mapped' => ['topdesk-manufacturer.json', $target(0, 'type'), '"type" is set by the import'],
			'the manufacturer type default changed' => ['topdesk-manufacturer.json', static function (array $pack): array {
				$pack['defaults']['type'] = 'Municipality';
				return $pack;
			}, 'default "type" must stay "Supplier"'],
			'an owner field that is no contact field' => ['topdesk-business-owner.json', $target(1, 'email'), '"email" is not a field the businessOwner pack may map'],
		];
	}//end brokenRules()

	/**
	 * A stored pack that breaks a rule of the import stops it, naming the pack, its id and the rule.
	 *
	 * @param string $file The shipped file the stored pack starts from.
	 * @param callable $edit The edit.
	 * @param string $rule The text of the broken rule.
	 *
	 * @return void
	 *
	 * @dataProvider brokenRules
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function testAStoredPackThatBreaksAnImportRuleIsRefused(string $file, callable $edit, string $rule): void {
		CmdbTestSupport::loadMigrationPack();
		$service = new FakeMigrationPackService();
		$pack = $edit((new CmdbImportPackMarker())->seed(shipped: $this->shipped(file: $file)));
		$id = $service->put(definition: $pack);
		$config = [];

		try {
			$this->storedProfile(service: $service, config: $config)->load();
			$this->fail('MAPPING_UNAVAILABLE expected');
		} catch (CmdbImportException $e) {
			$this->assertSame('MAPPING_UNAVAILABLE', $e->getErrorCode());
			$this->assertStringStartsWith('OpenRegister migration pack ' . $pack['id'] . ' (id ' . $id . ') breaks a rule of the CMDB import: ', $e->getMessage());
			$this->assertStringContainsString($rule, $e->getMessage());
			$this->assertSame(['pack' => $pack['id'], 'packId' => $id], $e->getDetails());
		}
	}//end testAStoredPackThatBreaksAnImportRuleIsRefused()

	/**
	 * Without OpenRegister's store, and no pack ever stored there, the import uses the shipped files and says so.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function testWithoutTheStoreBeforeASeedTheShippedFilesAreUsed(): void {
		CmdbTestSupport::loadMigrationPack();
		$config = [];
		$container = $this->createMock(ContainerInterface::class);
		$appConfig = FakeMigrationPackService::appConfig(test: $this, values: $config);
		$container->method('has')->willReturnCallback(fn (string $id): bool => $id === \OCP\IAppConfig::class);
		$container->method('get')->willReturn($appConfig);

		$profile = new CmdbImportProfile(container: $container);
		$overview = $profile->mappingOverview();

		$this->assertTrue($profile->usesShippedFallback());
		$this->assertFalse($overview['openRegisterPacks']);
		$this->assertSame(['file'], array_values(array_unique(array_column($overview['packs'], 'source'))));
		$this->assertSame($this->shipped(file: 'topdesk-module.json'), $profile->pack(target: 'module'));
	}//end testWithoutTheStoreBeforeASeedTheShippedFilesAreUsed()

	/**
	 * Once the packs were stored in OpenRegister, a store that is gone fails closed, naming the packs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function testWithoutTheStoreAfterASeedTheImportFailsClosed(): void {
		CmdbTestSupport::loadMigrationPack();
		$config = [CmdbImportPackStore::CONFIG_KEY => (string)json_encode(['stackiq-topdesk-module' => 41, 'stackiq-topdesk-usage' => 43])];
		$container = $this->createMock(ContainerInterface::class);
		$appConfig = FakeMigrationPackService::appConfig(test: $this, values: $config);
		$container->method('has')->willReturnCallback(fn (string $id): bool => $id === \OCP\IAppConfig::class);
		$container->method('get')->willReturn($appConfig);

		try {
			(new CmdbImportProfile(container: $container))->load();
			$this->fail('MAPPING_UNAVAILABLE expected');
		} catch (CmdbImportException $e) {
			$this->assertSame('MAPPING_UNAVAILABLE', $e->getErrorCode());
			$this->assertStringContainsString('OpenRegister migration pack store is not available', $e->getMessage());
			$this->assertStringContainsString('stackiq-topdesk-module (id 41)', $e->getMessage());
			$this->assertStringContainsString('stackiq-topdesk-usage (id 43)', $e->getMessage());
			$this->assertSame(['packs' => ['stackiq-topdesk-module' => 41, 'stackiq-topdesk-usage' => 43]], $e->getDetails());
		}
	}//end testWithoutTheStoreAfterASeedTheImportFailsClosed()

	/**
	 * A column a stored pack maps with a date transform is converted from an Excel date, and is read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function testADateColumnOfAStoredPackIsConverted(): void {
		CmdbTestSupport::loadMigrationPack();
		$service = new FakeMigrationPackService();
		$usage = (new CmdbImportPackMarker())->seed(shipped: $this->shipped(file: 'topdesk-usage.json'));
		$usage['fieldMappings'][] = [
			'source' => 'In productie sinds',
			'target' => 'startDateInProduction',
			'transform' => ['type' => 'date', 'sourceFormat' => '!Y-m-d', 'targetFormat' => 'Y-m-d'],
		];
		$service->put(definition: $usage);
		$config = [];

		$profile = $this->storedProfile(service: $service, config: $config);

		$this->assertSame(
			['Datum', 'Referentie datum wijziging', 'End-of-Life Functioneel', 'In productie sinds'],
			$profile->dateColumns()
		);
		$this->assertContains('In productie sinds', $profile->referencedColumns(), 'a mapped column is read');
		$this->assertSame(
			['Datum', 'Referentie datum wijziging', 'End-of-Life Functioneel'],
			(new CmdbImportProfile(container: $this->emptyContainer()))->dateColumns(),
			'the shipped packs add no column the profile does not list'
		);
	}//end testADateColumnOfAStoredPackIsConverted()

	/**
	 * The separator the import drops empty note parts on is the pack's own.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function testTheConcatSeparatorIsThePacks(): void {
		CmdbTestSupport::loadMigrationPack();
		$shipped = new CmdbImportProfile(container: $this->emptyContainer());
		$this->assertSame(' / ', $shipped->concatSeparator(target: 'usage', field: 'interneAnnotation'));
		$this->assertNull($shipped->concatSeparator(target: 'usage', field: 'status'), 'no concat mapping writes it');

		$service = new FakeMigrationPackService();
		$usage = (new CmdbImportPackMarker())->seed(shipped: $this->shipped(file: 'topdesk-usage.json'));
		$usage['fieldMappings'][3]['transform']['separator'] = ' | ';
		$service->put(definition: $usage);
		$config = [];
		$this->assertSame(' | ', $this->storedProfile(service: $service, config: $config)->concatSeparator(target: 'usage', field: 'interneAnnotation'));

		unset($usage['fieldMappings'][3]['transform']['separator']);
		$service = new FakeMigrationPackService();
		$service->put(definition: $usage);
		$this->assertSame(' ', $this->storedProfile(service: $service, config: $config)->concatSeparator(target: 'usage', field: 'interneAnnotation'), "OpenRegister's default");
	}//end testTheConcatSeparatorIsThePacks()

	/**
	 * A plain mapping after the concat one keeps the separator: on an empty column the engine keeps the concatenated value.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function testALaterPlainMappingKeepsTheConcatSeparator(): void {
		CmdbTestSupport::loadMigrationPack();
		$service = new FakeMigrationPackService();
		$usage = (new CmdbImportPackMarker())->seed(shipped: $this->shipped(file: 'topdesk-usage.json'));
		$usage['fieldMappings'][] = ['source' => 'Opmerking', 'target' => 'interneAnnotation', 'transform' => ['type' => 'trim']];
		$service->put(definition: $usage);
		$config = [];

		$this->assertSame(' / ', $this->storedProfile(service: $service, config: $config)->concatSeparator(target: 'usage', field: 'interneAnnotation'));
	}//end testALaterPlainMappingKeepsTheConcatSeparator()

	/**
	 * The upload limit is readable without OpenRegister.
	 *
	 * @return void
	 */
	public function testTheUploadLimitNeedsNoOpenRegister(): void {
		$profile = new CmdbImportProfile(container: $this->emptyContainer(), directory: '/nonexistent');
		$this->assertSame(CmdbImportProfile::DEFAULT_MAX_FILE_BYTES, $profile->maxFileBytes());
	}//end testTheUploadLimitNeedsNoOpenRegister()
}//end class
