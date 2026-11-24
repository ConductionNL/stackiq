<?php

/**
 * Builds the legacy catalogue migration service with test doubles.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Support
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/archive/2026-11-24-landscape-migrate-from-previous-catalogue/tasks.md#task-2
 * @spec openspec/changes/archive/2026-11-24-landscape-migrate-from-previous-catalogue/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Support;

require_once __DIR__ . '/CmdbTestSupport.php';

use OCA\Stackiq\Kernel\Contracts\OpenRegisterLookupInterface;
use OCA\Stackiq\Service\LegacyCatalogue\LegacyCatalogueOpenRegister;
use OCA\Stackiq\Service\LegacyCatalogueMigrationService;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * For a TestCase: the service on schema ids LEGACY_SCHEMAS and register 7, a permission handler that allows create on
 * the usage schema only, and OpenRegister's pack classes (the test copies where OpenRegister is not checked out).
 */
trait BuildsLegacyCatalogueService {
	/**
	 * Schema ids by object type.
	 */
	public const LEGACY_SCHEMAS = ['organization' => 11, 'module' => 12, 'moduleVersion' => 13, 'usage' => 14];

	/**
	 * The service with the given caller, organisation, right, object service and reader.
	 *
	 * @param array<string, mixed> $options `user` (bool, default true), `organisation` (default org-9), `mayCreate`
	 *                                      (default true), `objectService` (default none) and `reader` (default none).
	 *
	 * @return LegacyCatalogueMigrationService
	 */
	protected function legacyService(array $options = []): LegacyCatalogueMigrationService {
		$user = ($options['user'] ?? true);
		$organisation = 'org-9';
		if (array_key_exists('organisation', $options) === true) {
			$organisation = $options['organisation'];
		}

		$mayCreate = ($options['mayCreate'] ?? true);
		$reader = ($options['reader'] ?? null);
		CmdbTestSupport::loadMigrationPack();
		$session = $this->createMock(originalClassName: IUserSession::class);
		$caller = null;
		if ($user === true) {
			$caller = $this->createMock(originalClassName: IUser::class);
		}

		$session->method('getUser')->willReturn($caller);
		$lookup = $this->createMock(originalClassName: OpenRegisterLookupInterface::class);
		$lookup->method('activeOrganisationUuid')->willReturn($organisation);
		$apps = $this->createMock(originalClassName: IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);

		$objectService = ($options['objectService'] ?? null);

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getSchemaIdForObjectType')->willReturnCallback(
			static fn (string $objectType): ?int => (self::LEGACY_SCHEMAS[$objectType] ?? null)
		);
		$settings->method('getVoorzieningenRegisterId')->willReturn(7);
		$settings->method('getObjectService')->willReturn($objectService);

		$openRegister = new LegacyCatalogueOpenRegister(
			container: $this->legacyContainer(mayCreate: $mayCreate),
			appManager: $apps,
			settingsService: $settings,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

		return new LegacyCatalogueMigrationService(openRegister: $openRegister, userSession: $session, lookup: $lookup, reader: $reader);
	}//end service()

	/**
	 * A container with the schema mapper, the permission handler and the translations.
	 *
	 * @param bool $mayCreate What the permission handler answers.
	 *
	 * @return ContainerInterface
	 */
	private function legacyContainer(bool $mayCreate): ContainerInterface {
		$base = CmdbTestSupport::emptyContainer(test: $this);
		$mapper = new class {
			/**
			 * The schema.
			 *
			 * @param int $id The id.
			 *
			 * @return object
			 */
			public function find(int $id): object {
				return (object)['id' => $id];
			}//end find()
		};
		$permissions = new class($mayCreate, self::LEGACY_SCHEMAS['usage']) {
			/**
			 * Constructor.
			 *
			 * @param bool $answer The answer.
			 * @param int $usageSchema The usage schema id.
			 */
			public function __construct(private readonly bool $answer, private readonly int $usageSchema) {
			}//end __construct()

			/**
			 * The answer, for the usage schema and create only.
			 *
			 * @param object $schema The schema.
			 * @param string $action The action.
			 *
			 * @return bool
			 */
			public function hasPermission(object $schema, string $action): bool {
				return $this->answer && $schema->id === $this->usageSchema && $action === 'create';
			}//end hasPermission()
		};
		$services = [
			LegacyCatalogueOpenRegister::SCHEMA_MAPPER => $mapper,
			LegacyCatalogueOpenRegister::PERMISSIONS => $permissions,
		];

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('has')->willReturnCallback(static fn (string $id): bool => isset($services[$id]) || $base->has($id));
		$container->method('get')->willReturnCallback(static fn (string $id): mixed => ($services[$id] ?? $base->get($id)));
		return $container;
	}//end container()
}//end trait
