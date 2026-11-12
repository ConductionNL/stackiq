<?php

/**
 * Tests that the catalogue services depend on the register configuration reader.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Settings;

use OCA\Stackiq\Service\AanbodService;
use OCA\Stackiq\Service\AangebodenGebruikService;
use OCA\Stackiq\Service\ArchiMateService;
use OCA\Stackiq\Service\CmdbExportImportService;
use OCA\Stackiq\Service\ContactpersoonService;
use OCA\Stackiq\Service\Contactpersoon\ContactPersonOrganisationGuard;
use OCA\Stackiq\Service\GebruikSyncService;
use OCA\Stackiq\Service\MergeOrganisatieService;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * These services only read configuration, so they depend on the register
 * configuration reader instead of the settings service.
 *
 * @coversNothing
 *
 * @spec openspec/specs/settings-service/spec.md
 */
class RegisterConfigReaderCatalogueServicesTest extends TestCase {

	/**
	 * The services that only read configuration.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function readerConsumers(): array {
		return [
			'AanbodService'                  => [AanbodService::class],
			'AangebodenGebruikService'       => [AangebodenGebruikService::class],
			'ArchiMateService'               => [ArchiMateService::class],
			'CmdbExportImportService'        => [CmdbExportImportService::class],
			'ContactPersonOrganisationGuard' => [ContactPersonOrganisationGuard::class],
			'ContactpersoonService'          => [ContactpersoonService::class],
			'GebruikSyncService'             => [GebruikSyncService::class],
			'MergeOrganisatieService'        => [MergeOrganisatieService::class],
		];
	}//end readerConsumers()

	/**
	 * A configuration reading service asks for the reader, not the settings service.
	 *
	 * @param class-string $class The consumer class.
	 *
	 * @return void
	 */
	#[DataProvider('readerConsumers')]
	public function testTheConsumerAsksForTheReader(string $class): void {
		$types = [];
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $parameter) {
			$type = $parameter->getType();
			if ($type instanceof ReflectionNamedType) {
				$types[] = $type->getName();
			}
		}

		self::assertContains(needle: RegisterConfigReaderInterface::class, haystack: $types);
		self::assertNotContains(needle: SettingsService::class, haystack: $types);
	}//end testTheConsumerAsksForTheReader()
}//end class
