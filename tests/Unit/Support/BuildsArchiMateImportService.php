<?php

/**
 * Builds an ArchiMate import service over test doubles.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Support
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Support;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\Stackiq\Service\ArchiMateImportService;
use OCA\Stackiq\Service\ProgressTracker;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * An import service whose AMEF register is 7 with schemas 10 to 15, over a given object service.
 */
trait BuildsArchiMateImportService {

	/**
	 * The AMEF configuration the service reads.
	 *
	 * @var array<string, string>
	 */
	private static array $amefConfig = [
		'register'                   => '7',
		'model_schema'               => '10',
		'element_schema'             => '11',
		'relation_schema'            => '12',
		'view_schema'                => '13',
		'organization_schema'        => '14',
		'property_definition_schema' => '15',
	];

	/**
	 * The import service over the given object service.
	 *
	 * @param ObjectServiceInterface $objectService The object service the import saves through.
	 *
	 * @return ArchiMateImportService
	 */
	private function importService(ObjectServiceInterface $objectService): ArchiMateImportService {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				if ($key === 'amef_config') {
					return (string) json_encode(value: self::$amefConfig);
				}

				return $default;
			}
		);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('importer');
		$userSession = $this->createMock(originalClassName: IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objectService): ?ObjectServiceInterface {
				if ($id === ObjectService::class) {
					return $objectService;
				}

				return null;
			}
		);

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getSchemaIdForObjectType')->willReturnCallback(
			function (string $type): ?int {
				$ids = ['model' => 10, 'element' => 11, 'relationship' => 12, 'view' => 13, 'organization' => 14, 'property_definition' => 15];
				return $ids[$type] ?? null;
			}
		);

		$organisation = new Organisation();
		$organisation->setUuid('org-default');
		$organisations = $this->createMock(originalClassName: OrganisationService::class);
		$organisations->method('ensureDefaultOrganisation')->willReturn($organisation);

		$result = $this->createMock(originalClassName: IResult::class);
		$result->method('fetch')->willReturn(false);
		$database = $this->createMock(originalClassName: IDBConnection::class);
		$database->method('executeQuery')->willReturn($result);
		$database->method('executeStatement')->willReturn(0);

		return new ArchiMateImportService(
			config: $config,
			userSession: $userSession,
			appManager: $appManager,
			container: $container,
			logger: new NullLogger(),
			settingsService: $settings,
			organisationService: $organisations,
			dbConnection: $database,
			progressTracker: $this->createMock(originalClassName: ProgressTracker::class)
		);
	}//end importService()
}//end trait
