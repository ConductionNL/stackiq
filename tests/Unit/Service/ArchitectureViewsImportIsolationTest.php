<?php

/**
 * The GEMMA import marks what it writes as imported and addresses only the
 * identifiers in the file, so a drawn view stays as it is through a re-import.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Service\OrganisationService;
use OCA\Stackiq\Service\ArchiMate\ArchiMateImportScope;
use OCA\Stackiq\Service\ArchiMateImportService;
use OCA\Stackiq\Service\ProgressTracker;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The import's object head carries `origin: imported` on views, elements and
 * relations, and keeps the file's identifier as the object id.
 *
 * @covers \OCA\Stackiq\Service\ArchiMateImportService
 * @uses   \OCA\Stackiq\Service\ArchiMate\ArchiMateImportScope
 *
 * @spec openspec/changes/archive/2026-11-26-architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-002-imported-gemma-views-shall-open-read-only-and-shall-be-copied-before-editing
 */
class ArchitectureViewsImportIsolationTest extends TestCase {

	/**
	 * Views, elements and relations the import writes say `imported`.
	 *
	 * @return void
	 */
	public function testViewsElementsAndRelationsAreMarkedImported(): void {
		$service = $this->service();

		foreach (['view', 'element', 'relationship'] as $section) {
			$object = $this->importObject(service: $service, section: $section, identifier: 'id-gemma-1');
			$this->assertSame('imported', $object['origin'] ?? null, $section);
		}
	}//end testViewsElementsAndRelationsAreMarkedImported()

	/**
	 * Sections whose schema has no origin get none.
	 *
	 * @return void
	 */
	public function testOtherSectionsCarryNoOrigin(): void {
		$service = $this->service();

		foreach (['organization', 'property_definition', 'model'] as $section) {
			$this->assertArrayNotHasKey('origin', $this->importObject(service: $service, section: $section, identifier: 'id-gemma-1'), $section);
		}
	}//end testOtherSectionsCarryNoOrigin()

	/**
	 * The object id is the identifier from the file, so a re-import updates
	 * that GEMMA view and cannot reach a drawn copy, whose identifier and uuid
	 * the editor made fresh.
	 *
	 * @return void
	 */
	public function testTheObjectIdIsTheIdentifierFromTheFile(): void {
		$object = $this->importObject(service: $this->service(), section: 'view', identifier: 'id-e0f57689-gemma');

		$this->assertSame('id-e0f57689-gemma', $object['@self']['id']);
		$this->assertSame('id-e0f57689-gemma', $object['identifier']);
		$this->assertSame(7, $object['@self']['register']);
		$this->assertSame(3, $object['@self']['schema']);
		$this->assertSame('admin', $object['@self']['owner']);
		$this->assertSame('model-1', $object['model_identifier']);
		$this->assertSame('view', $object['section']);
	}//end testTheObjectIdIsTheIdentifierFromTheFile()

	/**
	 * The import object for one identifier in a section.
	 *
	 * @param ArchiMateImportService $service    The service.
	 * @param string                 $section    The section.
	 * @param string                 $identifier The ArchiMate identifier.
	 *
	 * @return array<string, mixed>
	 */
	private function importObject(ArchiMateImportService $service, string $section, string $identifier): array {
		$method = new ReflectionMethod(ArchiMateImportService::class, 'importObject');
		$scope  = (new ArchiMateImportScope(modelIdentifier: 'model-1', propDefMap: []))->withSection(section: $section);

		return $method->invoke($service, ['register' => 7, 'schema' => 3, 'id' => $identifier], $scope);
	}//end importObject()

	/**
	 * The service with its import config cached, as an import run leaves it.
	 *
	 * @return ArchiMateImportService
	 */
	private function service(): ArchiMateImportService {
		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('ensureDefaultOrganisation')->willThrowException(new \RuntimeException('no organisation'));

		$service = new ArchiMateImportService(
			config: $this->createMock(IAppConfig::class),
			userSession: $this->createMock(IUserSession::class),
			appManager: $this->createMock(IAppManager::class),
			container: $this->createMock(ContainerInterface::class),
			logger: new NullLogger(),
			settingsService: $this->createMock(ObjectTypeLocatorInterface::class),
			organisationService: $organisations,
			dbConnection: $this->createMock(IDBConnection::class),
			progressTracker: $this->createMock(ProgressTracker::class)
		);
		(new ReflectionProperty(ArchiMateImportService::class, 'cachedConfig'))->setValue($service, ['userId' => 'admin', 'organisation' => 'org-1', 'registerId' => 7]);

		return $service;
	}//end service()
}//end class
