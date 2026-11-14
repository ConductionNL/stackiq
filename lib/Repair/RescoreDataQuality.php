<?php

/**
 * Give every application and usage that has no data quality score one.
 *
 * OpenRegister scores an entry on save, from the rules its schema declares. An
 * entry saved before the rules existed has no score until it is saved again, so
 * this step saves each unscored module and usage once through the object
 * service, logs how many it scored, and records that it is done. Entries that
 * already carry a score are left alone, so a second run writes nothing.
 *
 * @category  Repair
 * @package   OCA\Stackiq\Repair
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-001-every-application-and-usage-carries-a-data-quality-score-from-a-declared-rule-set
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Repair;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Kernel\AppIdentity;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Throwable;

/**
 * Saves each unscored module and usage once so OpenRegister scores it.
 *
 * @category Repair
 * @package  OCA\Stackiq\Repair
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-001-every-application-and-usage-carries-a-data-quality-score-from-a-declared-rule-set
 */
class RescoreDataQuality implements IRepairStep {
	/**
	 * The app config flag set once every entry has a score.
	 */
	public const DONE_CONFIG_KEY = 'data_quality_rescored';

	/**
	 * Entries read per page.
	 */
	public const PAGE_SIZE = 200;

	/**
	 * The object types whose schemas declare data quality rules.
	 */
	public const OBJECT_TYPES = ['module', 'usage'];

	/**
	 * Constructor.
	 *
	 * @param IAppManager                   $appManager      Whether OpenRegister is installed.
	 * @param RegisterConfigReaderInterface $settingsService The configured register, schemas and ObjectService.
	 * @param IAppConfig                    $appConfig       Holds the done flag.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly RegisterConfigReaderInterface $settingsService,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The step's name in the upgrade output.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-001-every-application-and-usage-carries-a-data-quality-score-from-a-declared-rule-set
	 */
	public function getName(): string {
		return 'Give every application and usage a data quality score';
	}//end getName()

	/**
	 * Score every unscored module and usage, once.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-001-every-application-and-usage-carries-a-data-quality-score-from-a-declared-rule-set
	 */
	public function run(IOutput $output): void {
		if ($this->appConfig->getValueBool(AppIdentity::APP_ID, self::DONE_CONFIG_KEY, false) === true) {
			return;
		}

		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			$output->info('OpenRegister not installed, so there is nothing to score.');
			return;
		}

		$objects = $this->settingsService->getObjectService();
		if ($objects === null) {
			$output->info('The object service is not available, so there is nothing to score.');
			return;
		}

		$total = ['scored' => 0, 'failed' => 0, 'skipped' => 0];
		foreach (self::OBJECT_TYPES as $objectType) {
			$result = $this->rescoreType(objects: $objects, objectType: $objectType);
			foreach ($total as $key => $count) {
				$total[$key] = ($count + $result[$key]);
			}
		}

		$this->report(output: $output, total: $total);
	}//end run()

	/**
	 * Score the unscored entries of one object type.
	 *
	 * @param ObjectServiceInterface $objects    OpenRegister's ObjectService.
	 * @param string                 $objectType module or usage.
	 *
	 * @return array{scored: int, failed: int, skipped: int} The counts; an unconfigured type counts as skipped.
	 */
	private function rescoreType(ObjectServiceInterface $objects, string $objectType): array {
		$counts   = ['scored' => 0, 'failed' => 0, 'skipped' => 0];
		$register = $this->settingsService->getRegisterIdForObjectType($objectType);
		$schema   = $this->settingsService->getSchemaIdForObjectType($objectType);
		if ($register === null || $schema === null) {
			$counts['skipped'] = 1;
			return $counts;
		}

		$offset = 0;
		do {
			try {
				$page = (array) $objects->setRegister($register)->setSchema($schema)->findAll(
					config: ['limit' => self::PAGE_SIZE, 'offset' => $offset],
					_rbac: false,
					_multitenancy: false
				);
			} catch (Throwable) {
				$counts['failed']++;
				return $counts;
			}

			foreach ($page as $entry) {
				$outcome = $this->rescore(objects: $objects, entry: $entry, register: $register, schema: $schema);
				if ($outcome !== null) {
					$counts[$outcome]++;
				}
			}

			$offset  += self::PAGE_SIZE;
			$pageSize = count($page);
		} while ($pageSize === self::PAGE_SIZE);

		return $counts;
	}//end rescoreType()

	/**
	 * Save one entry so OpenRegister scores it, unless it has a score already.
	 *
	 * @param ObjectServiceInterface $objects  OpenRegister's ObjectService.
	 * @param mixed                  $entry    The entry from the page.
	 * @param int                    $register The register id.
	 * @param int                    $schema   The schema id.
	 *
	 * @return string|null scored or failed, or null when there was nothing to do.
	 */
	private function rescore(ObjectServiceInterface $objects, mixed $entry, int $register, int $schema): ?string {
		if (($entry instanceof ObjectEntityInterface) === false) {
			return null;
		}

		$data = $entry->getObject();
		if (isset($data['qualityScore']) === true) {
			return null;
		}

		unset($data['@self'], $data['id']);
		try {
			$objects->saveObject(
				object: $data,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: $entry->getUuid(),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable) {
			return 'failed';
		}

		return 'scored';
	}//end rescore()

	/**
	 * Log the counts, and record that the step is done when nothing failed.
	 *
	 * @param IOutput                                          $output The upgrade output.
	 * @param array{scored: int, failed: int, skipped: int} $total  The counts.
	 *
	 * @return void
	 */
	private function report(IOutput $output, array $total): void {
		$output->info($total['scored'] . ' applications and usages now have a data quality score.');
		if ($total['failed'] > 0) {
			$output->warning($total['failed'] . ' entries or pages failed; the next upgrade tries again.');
			return;
		}

		if ($total['skipped'] > 0) {
			$output->info('A schema is not configured yet; the next upgrade scores it.');
			return;
		}

		$this->appConfig->setValueBool(AppIdentity::APP_ID, self::DONE_CONFIG_KEY, true);
	}//end report()
}//end class
