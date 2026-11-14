<?php

/**
 * Tests that the module, organisation and view services depend on the register configuration reader.
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

use OCA\Stackiq\Service\ArchiMate\ArchiMateContext;
use OCA\Stackiq\Service\ModuleComplianceService;
use OCA\Stackiq\Service\ModuleEventProcessor;
use OCA\Stackiq\Service\ModuleRegistrationService;
use OCA\Stackiq\Service\ModuleVersionService;
use OCA\Stackiq\Service\OrganizationSyncService;
use OCA\Stackiq\Service\PublicCatalogueRecords;
use OCA\Stackiq\Service\ReferenceComponentCoverageService;
use OCA\Stackiq\Service\SbomImportService;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCA\Stackiq\Service\Settings\SettingsObjectCounts;
use OCA\Stackiq\Service\ViewService;
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
class RegisterConfigReaderModuleServicesTest extends TestCase {

	/**
	 * The services that only read configuration.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function readerConsumers(): array {
		return [
			'ModuleComplianceService'           => [ModuleComplianceService::class],
			'ModuleEventProcessor'              => [ModuleEventProcessor::class],
			'ModuleRegistrationService'         => [ModuleRegistrationService::class],
			'ModuleVersionService'              => [ModuleVersionService::class],
			'OrganizationSyncService'           => [OrganizationSyncService::class],
			'PublicCatalogueRecords'            => [PublicCatalogueRecords::class],
			'ReferenceComponentCoverageService' => [ReferenceComponentCoverageService::class],
			'SbomImportService'                 => [SbomImportService::class],
			'SettingsObjectCounts'              => [SettingsObjectCounts::class],
			'ViewService'                       => [ViewService::class],
			'ArchiMateContext'                  => [ArchiMateContext::class],
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
