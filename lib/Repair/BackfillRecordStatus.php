<?php

/**
 * Backfill the record status of applications and services.
 *
 * The Applications and Services lists leave out records whose recordStatus is
 * Merged. A database comparison with Merged also drops a row that has no
 * status at all, so every existing row gets Active once; new rows get it from
 * the property's default.
 *
 * @category  Repair
 * @package   OCA\Stackiq\Repair
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/record-reconciliation/spec.md#requirement-req-rrc-004-merged-applications-and-services-shall-leave-the-lists-and-point-readers-to-the-survivor
 */

declare(strict_types=1);

namespace OCA\Stackiq\Repair;

use OCA\Stackiq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Sets recordStatus Active on every module and catalogService without one.
 */
class BackfillRecordStatus implements IRepairStep {

	/**
	 * The schemas that carry a record status.
	 */
	private const SCHEMAS = ['module', 'catalogService'];

	/**
	 * Rows read per schema.
	 */
	private const LIMIT = 10000;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Register, schema and object service resolution.
	 * @param LoggerInterface $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name.
	 */
	public function getName(): string {
		return 'Backfill recordStatus=Active on existing applications and services';
	}//end getName()

	/**
	 * Run the backfill.
	 *
	 * @param IOutput $output The output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/record-reconciliation/spec.md#requirement-req-rrc-004-merged-applications-and-services-shall-leave-the-lists-and-point-readers-to-the-survivor
	 */
	public function run(IOutput $output): void {
		$objectService = null;
		try {
			$objectService = $this->settingsService->getObjectService();
		} catch (\Throwable $e) {
			$this->logger->warning('[BackfillRecordStatus] ObjectService unavailable', ['error' => $e->getMessage()]);
		}

		if ($objectService === null) {
			$output->info('OpenRegister not available, skipping the record status backfill');
			return;
		}

		$filled = 0;
		foreach (self::SCHEMAS as $type) {
			$register = $this->settingsService->getRegisterIdForObjectType($type);
			$schema   = $this->settingsService->getSchemaIdForObjectType($type);
			if ($register === null || $schema === null) {
				continue;
			}

			try {
				$rows = (array) $objectService->searchObjects(
					query: ['register' => $register, 'schema' => $schema, '_limit' => self::LIMIT],
					_rbac: false,
					_multitenancy: false
				);
			} catch (\Throwable $e) {
				$this->logger->error('[BackfillRecordStatus] could not read ' . $type, ['error' => $e->getMessage()]);
				continue;
			}

			foreach ($rows as $row) {
				$data = $row->getObject();
				if (trim((string) ($data['recordStatus'] ?? '')) !== '') {
					continue;
				}

				$data['recordStatus'] = 'Active';
				try {
					$objectService->saveObject(
						object: $data,
						extend: [],
						register: $row->getRegister(),
						schema: $row->getSchema(),
						uuid: $row->getUuid(),
						_rbac: false,
						_multitenancy: false
					);
					$filled++;
				} catch (\Throwable $e) {
					$this->logger->error('[BackfillRecordStatus] could not save', ['uuid' => $row->getUuid(), 'error' => $e->getMessage()]);
				}
			}//end foreach
		}//end foreach

		$output->info(sprintf('Record status backfill: %d row(s) set to Active', $filled));
	}//end run()
}//end class
