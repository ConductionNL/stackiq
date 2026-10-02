<?php

/**
 * Copies every module's publication onto its versions, once, on upgrade.
 *
 * Versions saved before publication-field-rules carry no mirrored publication,
 * so for anonymous readers they read as unpublished until their module is
 * saved again. That is the safe direction; this step makes the published ones
 * public again without waiting for an edit. It is idempotent: a version that
 * already holds its module's values is not written. It reads the modules page
 * by page, and after a pass in which nothing failed it records that in the app
 * config, so later upgrades skip it.
 *
 * @category  Repair
 * @package   OCA\Stackiq\Repair
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/publication-field-rules/specs/publication-field-rules/spec.md#requirement-req-pfr-002-a-module-version-is-public-only-while-its-application-is
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Repair;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\Stackiq\AppInfo\Application;
use OCA\Stackiq\Service\ModuleVersionPublicationService;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Throwable;

/**
 * Backfills the mirrored publication on module versions.
 *
 * @spec openspec/changes/publication-field-rules/specs/publication-field-rules/spec.md#requirement-req-pfr-002-a-module-version-is-public-only-while-its-application-is
 */
class BackfillModuleVersionPublication implements IRepairStep {

	/**
	 * App-config key set after a pass in which every module and version was handled.
	 *
	 * @var string
	 */
	public const DONE_CONFIG_KEY = 'module_version_publication_backfilled';

	/**
	 * How many modules one read returns.
	 *
	 * @var integer
	 */
	public const PAGE_SIZE = 200;

	/**
	 * Constructor.
	 *
	 * @param IAppManager                     $appManager      Tells whether OpenRegister is installed.
	 * @param SettingsService                 $settingsService Resolves the module schema and the object service.
	 * @param ModuleVersionPublicationService $publication     The mirror.
	 * @param IAppConfig                      $appConfig       Holds the done marker.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly SettingsService $settingsService,
		private readonly ModuleVersionPublicationService $publication,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name.
	 */
	public function getName(): string {
		return 'Copy each application\'s publication onto its versions';
	}//end getName()

	/**
	 * Run the backfill.
	 *
	 * @param IOutput $output The output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publication-field-rules/specs/publication-field-rules/spec.md#requirement-req-pfr-002-a-module-version-is-public-only-while-its-application-is
	 */
	public function run(IOutput $output): void {
		if ($this->appConfig->getValueBool(Application::APP_ID, self::DONE_CONFIG_KEY, false) === true) {
			return;
		}

		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			$output->info('OpenRegister not installed, so there are no versions to update.');
			return;
		}

		$register = $this->settingsService->getRegisterIdForObjectType('module');
		$schema   = $this->settingsService->getSchemaIdForObjectType('module');
		$objects  = $this->settingsService->getObjectService();
		if ($register === null || $schema === null || $objects === null) {
			$output->info('The module schema is not configured yet, so there are no versions to update.');
			return;
		}

		$written = 0;
		$failed  = 0;
		$offset  = 0;
		do {
			try {
				$modules = (array) $objects->setRegister($register)->setSchema($schema)->findAll(
					['limit' => self::PAGE_SIZE, 'offset' => $offset],
					false,
					false
				);
			} catch (Throwable $e) {
				$output->warning('Could not read the applications: ' . $e->getMessage());
				return;
			}

			foreach ($modules as $module) {
				if (($module instanceof ObjectEntityInterface) === true) {
					$result   = $this->publication->backfillModule(module: $module);
					$written += $result['written'];
					$failed  += $result['failed'];
				}
			}

			$offset += self::PAGE_SIZE;
		} while (count($modules) === self::PAGE_SIZE);

		$output->info($written . ' versions now follow their application\'s publication.');
		if ($failed > 0) {
			$output->warning($failed . ' versions or searches failed; the next upgrade tries again.');
			return;
		}

		$this->appConfig->setValueBool(Application::APP_ID, self::DONE_CONFIG_KEY, true);
	}//end run()
}//end class
