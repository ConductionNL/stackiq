<?php

/**
 * Sets `recordStatus` Active on every existing application and service, once,
 * on upgrade.
 *
 * The change operations-record-reconciliation adds `recordStatus` (Active or Merged) to
 * `module` and `catalogService`; OpenRegister's merge writes Merged on the
 * merged record. Rows stored before the property existed carry none, so this
 * step gives them Active and no row is left empty. It is idempotent: a row
 * that already has a status is not written. After a pass in which nothing
 * failed it records that in the app config, so later upgrades skip it.
 *
 * @category  Repair
 * @package   OCA\Stackiq\Repair
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/operations-record-reconciliation/tasks.md#task-1-declare-the-duplicate-and-merge-rules-and-the-record-status
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
 * Backfill the record status of applications and services.
 *
 * @spec openspec/changes/operations-record-reconciliation/tasks.md#task-1-declare-the-duplicate-and-merge-rules-and-the-record-status
 */
class BackfillRecordStatus implements IRepairStep {

	/**
	 * App config key recording that the backfill completed.
	 *
	 * @var string
	 */
	public const DONE_CONFIG_KEY = 'record_status_backfilled';

	/**
	 * The schemas that carry a record status.
	 *
	 * @var array<int, string>
	 */
	public const SCHEMAS = ['module', 'catalogService'];

	/**
	 * Objects read per page.
	 *
	 * @var int
	 */
	public const PAGE_SIZE = 200;

	/**
	 * Constructor.
	 *
	 * @param IAppManager                   $appManager Whether OpenRegister is installed.
	 * @param RegisterConfigReaderInterface $settings   The register and schema ids, and the object service.
	 * @param IAppConfig                    $appConfig  The done marker.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly RegisterConfigReaderInterface $settings,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The step's name in the upgrade output.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Mark every existing application and service as an active record';
	}//end getName()

	/**
	 * Run the backfill once.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/operations-record-reconciliation/tasks.md#task-1-declare-the-duplicate-and-merge-rules-and-the-record-status
	 */
	public function run(IOutput $output): void {
		if ($this->appConfig->getValueBool(AppIdentity::APP_ID, self::DONE_CONFIG_KEY, false) === true) {
			return;
		}

		$objects = $this->objectService(output: $output);
		if ($objects === null) {
			return;
		}

		$total = ['written' => 0, 'failed' => 0];
		foreach (self::SCHEMAS as $schema) {
			$result = $this->backfillSchema(objects: $objects, schema: $schema);
			$total['written'] += $result['written'];
			$total['failed']  += $result['failed'];
		}

		$this->report(output: $output, total: $total);
	}//end run()

	/**
	 * OpenRegister's object service, or null when there is nothing to backfill.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return ObjectServiceInterface|null
	 */
	private function objectService(IOutput $output): ?ObjectServiceInterface {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			$output->info('OpenRegister not installed, so there are no records to mark.');
			return null;
		}

		return $this->settings->getObjectService();
	}//end objectService()

	/**
	 * Mark the rows of one schema that have no status yet.
	 *
	 * @param ObjectServiceInterface $objects The object service.
	 * @param string                 $schema  The schema.
	 *
	 * @return array{written: int, failed: int}
	 */
	private function backfillSchema(ObjectServiceInterface $objects, string $schema): array {
		$register = $this->settings->getRegisterIdForObjectType(objectType: $schema);
		$schemaId = $this->settings->getSchemaIdForObjectType(objectType: $schema);
		$result = ['written' => 0, 'failed' => 0];
		if ($register === null || $schemaId === null) {
			return $result;
		}

		$offset = 0;
		do {
			$page = $this->page(objects: $objects, ids: ['register' => $register, 'schema' => $schemaId], offset: $offset);
			$result = $this->markPage(objects: $objects, ids: ['register' => $register, 'schema' => $schemaId], page: $page, result: $result);
			$offset += self::PAGE_SIZE;
		} while (count($page) === self::PAGE_SIZE);

		return $result;
	}//end backfillSchema()

	/**
	 * One page of a schema's rows; none when the read fails.
	 *
	 * @param ObjectServiceInterface              $objects The object service.
	 * @param array{register: int, schema: int}   $ids     The register and schema.
	 * @param int                                 $offset  The first row.
	 *
	 * @return array<int, mixed>
	 */
	private function page(ObjectServiceInterface $objects, array $ids, int $offset): array {
		try {
			return (array)$objects->setRegister($ids['register'])->setSchema($ids['schema'])->findAll(
				config: ['limit' => self::PAGE_SIZE, 'offset' => $offset],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable) {
			return [];
		}
	}//end page()

	/**
	 * Mark each row of a page that has no status.
	 *
	 * @param ObjectServiceInterface            $objects The object service.
	 * @param array{register: int, schema: int} $ids     The register and schema.
	 * @param array<int, mixed>                 $page    The rows.
	 * @param array{written: int, failed: int}  $result  The running totals.
	 *
	 * @return array{written: int, failed: int}
	 */
	private function markPage(ObjectServiceInterface $objects, array $ids, array $page, array $result): array {
		foreach ($page as $row) {
			$data = self::marked(row: $row);
			if ($data === null) {
				continue;
			}

			try {
				$objects->saveObject(
					object: $data,
					extend: [],
					register: $ids['register'],
					schema: $ids['schema'],
					uuid: $row->getUuid(),
					_rbac: false,
					_multitenancy: false,
					silent: true
				);
				$result['written']++;
			} catch (Throwable) {
				$result['failed']++;
			}
		}

		return $result;
	}//end markPage()

	/**
	 * A row's data with `recordStatus` Active, or null when it already has a
	 * status or is not an object.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/operations-record-reconciliation/tasks.md#task-1-declare-the-duplicate-and-merge-rules-and-the-record-status
	 */
	public static function marked(mixed $row): ?array {
		if (($row instanceof ObjectEntityInterface) === false) {
			return null;
		}

		$data = $row->getObject();
		if (is_string(($data['recordStatus'] ?? null)) === true && $data['recordStatus'] !== '') {
			return null;
		}

		$data['recordStatus'] = 'Active';
		return $data;
	}//end marked()

	/**
	 * Say what was done, and record completion when nothing failed.
	 *
	 * @param IOutput                          $output The upgrade output.
	 * @param array{written: int, failed: int} $total  The totals.
	 *
	 * @return void
	 */
	private function report(IOutput $output, array $total): void {
		$output->info($total['written'] . ' applications and services marked as active records.');
		if ($total['failed'] > 0) {
			$output->warning($total['failed'] . ' records could not be marked; the next upgrade tries again.');
			return;
		}

		$this->appConfig->setValueBool(AppIdentity::APP_ID, self::DONE_CONFIG_KEY, true);
	}//end report()
}//end class
