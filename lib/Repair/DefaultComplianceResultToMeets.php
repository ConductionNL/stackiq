<?php

/**
 * Repair step that gives every existing compliance claim the result "meets".
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Before `compliancy.result` existed, a claim could only say that an
 * application meets a standard or BIO measure. This step writes
 * `result = meets` on every claim without a result, so existing claims keep
 * showing as verified or claimed and "does not meet" stays an explicit,
 * recorded answer. It is idempotent (a claim with any result is skipped) and
 * fail-safe: a claim that cannot be saved is logged and the rest continue; no
 * other field is touched.
 *
 * OCP service calls use positional arguments (named arguments are fatal on
 * `occ upgrade`); OpenRegister ObjectService calls use the app's
 * named-argument convention, as BackfillContractApprovalState does.
 *
 * @category  Repair
 * @package   OCA\Stackiq\Repair
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/bio-measures-shipped-and-not-met/specs/bio-compliance-assessment/spec.md#requirement-a-compliance-claim-says-whether-the-application-meets-the-measure-req-bio-011
 */

declare(strict_types=1);

namespace OCA\Stackiq\Repair;

use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Defaults `compliancy.result` to "meets" on claims made before it existed.
 *
 * @spec openspec/changes/bio-measures-shipped-and-not-met/specs/bio-compliance-assessment/spec.md#requirement-a-compliance-claim-says-whether-the-application-meets-the-measure-req-bio-011
 */
class DefaultComplianceResultToMeets implements IRepairStep {

	/**
	 * The result an existing claim gets.
	 *
	 * @var string
	 */
	public const DEFAULT_RESULT = 'meets';

	/**
	 * Constructor.
	 *
	 * @param IAppManager                   $appManager      Tells whether OpenRegister is installed.
	 * @param RegisterConfigReaderInterface $settingsService Resolves the register, the schema and the ObjectService.
	 * @param LoggerInterface               $logger          The logger.
	 *
	 * @spec openspec/changes/bio-measures-shipped-and-not-met/specs/bio-compliance-assessment/spec.md#requirement-a-compliance-claim-says-whether-the-application-meets-the-measure-req-bio-011
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly RegisterConfigReaderInterface $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/bio-measures-shipped-and-not-met/specs/bio-compliance-assessment/spec.md#requirement-a-compliance-claim-says-whether-the-application-meets-the-measure-req-bio-011
	 */
	public function getName(): string {
		return 'Default the result of existing compliance claims to meets';
	}//end getName()

	/**
	 * Write result=meets on every claim without a result.
	 *
	 * @param IOutput $output The progress output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bio-measures-shipped-and-not-met/specs/bio-compliance-assessment/spec.md#requirement-a-compliance-claim-says-whether-the-application-meets-the-measure-req-bio-011
	 */
	public function run(IOutput $output): void {
		$output->startProgress(1);
		$claims = $this->claims(output: $output);
		if ($claims !== null) {
			$written = $this->backfill(claims: $claims['rows'], objectService: $claims['objectService']);
			$output->info(sprintf('Compliance result backfill: %d claim(s) defaulted to meets', $written));
		}

		$output->advance(1);
		$output->finishProgress();
	}//end run()

	/**
	 * The claims and the ObjectService, or null when the step cannot run.
	 *
	 * @param IOutput $output The progress output.
	 *
	 * @return array{rows: iterable<object>, objectService: object}|null
	 */
	private function claims(IOutput $output): ?array {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			$output->info('OpenRegister not installed — skipping the compliance result backfill');
			return null;
		}

		$registerId    = $this->settingsService->getVoorzieningenRegisterId();
		$schemaId      = $this->settingsService->getSchemaIdForObjectType('compliancy');
		$objectService = $this->settingsService->getObjectService();
		if ($registerId === null || $schemaId === null || $objectService === null) {
			$output->info('Compliancy register/schema not configured — skipping the compliance result backfill');
			return null;
		}

		try {
			$rows = $objectService->setRegister($registerId)
				->setSchema($schemaId)
				->findAll(config: [], _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			$output->warning(sprintf('Could not read compliance claims: %s', $e->getMessage()));
			$this->logger->error('[DefaultComplianceResultToMeets] Failed to read claims', ['error' => $e->getMessage()]);
			return null;
		}

		return ['rows' => $rows, 'objectService' => $objectService];
	}//end claims()

	/**
	 * Default the result on each claim that has none.
	 *
	 * @param iterable<object> $claims        The claims.
	 * @param object           $objectService OpenRegister's ObjectService.
	 *
	 * @return int How many claims were written.
	 */
	private function backfill(iterable $claims, object $objectService): int {
		$written = 0;
		foreach ($claims as $claim) {
			$data = (array)$claim->getObject();
			if (trim((string)($data['result'] ?? '')) !== '') {
				continue;
			}

			$data['result'] = self::DEFAULT_RESULT;
			try {
				$objectService->saveObject(
					object: $data,
					extend: [],
					register: $claim->getRegister(),
					schema: $claim->getSchema(),
					uuid: $claim->getUuid(),
					_rbac: false,
					_multitenancy: false
				);
				$written++;
			} catch (\Throwable $e) {
				$this->logger->error(
					'[DefaultComplianceResultToMeets] Failed to default a claim',
					['uuid' => $claim->getUuid(), 'error' => $e->getMessage()]
				);
			}
		}//end foreach

		return $written;
	}//end backfill()

}//end class
