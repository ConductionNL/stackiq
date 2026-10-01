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

use OCA\OpenRegister\Service\MigrationPack\MappingEngine;
use OCA\OpenRegister\Service\MigrationPack\PackDefinitionValidator;
use OCA\Stackiq\Exception\CmdbImportException;
use OCA\Stackiq\Service\Cmdb\CmdbImportProfile;
use OCA\Stackiq\Tests\Unit\Support\CmdbTestSupport;
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
				'Naam' => 'name',
				'Middel-ID' => 'externalId',
				'ICT Applicatienummer' => 'externalNumber',
				'Functionele omschrijving' => 'longDescription',
				'ICT BBN Classificatie' => 'bbnLevel',
				'Aanmaakdatum' => 'externalCreatedAt',
				'Wijzigingsdatum' => 'externalModifiedAt',
			],
			$targets('module')
		);
		$this->assertSame(['Fabrikant' => 'name'], $targets('manufacturer'));
		$this->assertSame(['municipalityName' => 'name'], $targets('municipality'));
		$this->assertSame(
			['Status' => 'status', 'ICT TIME Classificatie' => 'timeClassification', 'End of Life Business' => 'startDateOutPhased', 'Eigenaar afdeling' => 'interneAnnotation'],
			$targets('usage')
		);
		$this->assertSame(['Eigenaar' => 'name', 'Eigenaar e-mail' => 'email', 'Eigenaar functie' => 'role'], $targets('businessOwner'));
		$this->assertSame(['FB contactpersoon 1' => 'name'], $targets('technicalOwner'));

		$this->assertSame(['type' => 'Supplier', 'status' => 'Active'], $profile->pack(target: 'manufacturer')['defaults']);
		$this->assertSame(['type' => 'Municipality', 'status' => 'Active'], $profile->pack(target: 'municipality')['defaults']);
		$this->assertSame(['type' => 'Application'], $profile->createOnlyDefaults(target: 'module'));
		$this->assertSame(['interneAnnotation'], $profile->createOnlyFields(target: 'usage'));
		$this->assertSame(['publicationDate', 'depublicationDate'], $profile->neverWrittenOnUpdate(target: 'module'));
		$this->assertSame(['Middel-ID', 'Naam'], $profile->requiredColumns());
		$this->assertSame(['Invoer AIA data', 'Invoer APP data'], $profile->sheetNames());
		$this->assertSame(10485760, $profile->maxFileBytes());
		$this->assertSame(10000, $profile->maxRowsPerSheet());
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
			['Status' => 'In voorraad', 'ICT TIME Classificatie' => 'Tolereren', 'End of Life Business' => '2046-02-01', 'Eigenaar afdeling' => 'H10 Accounting', 'Eigenaar cluster' => 'H10 Bestuur'],
			2
		);
		$this->assertSame([], $usage['errors']);
		$this->assertSame(
			['status' => 'Planned', 'timeClassification' => 'Tolerate', 'startDateOutPhased' => '2046-02-01', 'interneAnnotation' => 'H10 Accounting / H10 Bestuur'],
			$usage['data']
		);

		$module = $engine->mapRow($profile->pack(target: 'module'), ['Naam' => 'X', 'Middel-ID' => 'APP-1', 'ICT BBN Classificatie' => 'BBN 2'], 2);
		$this->assertSame('BBN2', $module['data']['bbnLevel']);

		$unknown = $engine->mapRow($profile->pack(target: 'usage'), ['Status' => 'Onbekende status'], 3);
		$this->assertArrayNotHasKey('status', $unknown['data']);
		$this->assertSame('Status', $unknown['errors'][0]['source']);
		$this->assertStringContainsString('Onbekende status', $unknown['errors'][0]['message']);
	}//end testTheLookupsMapThroughTheEngine()

	/**
	 * The read allowlist leaves out personnel numbers, phones, group owners and group mailboxes.
	 *
	 * @return void
	 */
	public function testPersonColumnsAreNeverReferenced(): void {
		CmdbTestSupport::loadMigrationPack();
		$profile = new CmdbImportProfile(container: $this->emptyContainer());
		$columns = $profile->referencedColumns();

		foreach ([
			'Personeelsnummer',
			'Eigenaar mobiel nummer',
			'Groepseigenaar mail⚡',
			'Groepseigenaar naam⚡',
			'Groepseigenaar telefoon⚡',
			'Groepsmail⚡',
			'Groepsnummer⚡',
			'Configuratie coördinator⚡',
			'FB contactpersoon 2',
			'Opmerkingen',
			'municipalityName',
		] as $never) {
			$this->assertNotContains($never, $columns);
		}

		$this->assertContains('Eigenaar e-mail', $columns);
		$this->assertContains('Eigenaar cluster', $columns, 'the concat field is read too');
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
	 * The upload limit is readable without OpenRegister.
	 *
	 * @return void
	 */
	public function testTheUploadLimitNeedsNoOpenRegister(): void {
		$profile = new CmdbImportProfile(container: $this->emptyContainer(), directory: '/nonexistent');
		$this->assertSame(CmdbImportProfile::DEFAULT_MAX_FILE_BYTES, $profile->maxFileBytes());
	}//end testTheUploadLimitNeedsNoOpenRegister()
}//end class
