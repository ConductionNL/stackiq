<?php

/**
 * Organisation merge audit
 *
 * The audit entries an organisation merge writes, built and used only by
 * MergeOrganisatieService.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/organisation-merge/spec.md#requirement-every-dry-run-and-execute-call-must-produce-an-audit-log-entry
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCP\EventDispatcher\IEventDispatcher;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use Psr\Log\LoggerInterface;

/**
 * Writes the audit entry of each dry run and execution step of a merge.
 *
 * @category Service
 * @package  OCA\Stackiq\Service
 *
 * @spec openspec/specs/organisation-merge/spec.md#requirement-every-dry-run-and-execute-call-must-produce-an-audit-log-entry
 */
final class MergeAudit {

	/**
	 * Build the audit writer.
	 *
	 * @param LoggerInterface  $logger          Logger (structured audit entries).
	 * @param IEventDispatcher $eventDispatcher Event dispatcher (NC's `CriticalActionPerformedEvent` audit mechanism).
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly IEventDispatcher $eventDispatcher,
	) {
	}//end __construct()

	/**
	 * Write an audit log entry for a dry-run or execute call: a structured
	 * logger entry (queryable/testable) plus Nextcloud's own
	 * `CriticalActionPerformedEvent` (the existing admin_audit mechanism —
	 * no app-local notification/audit dispatch, per ADR-031 precedent).
	 *
	 * @param string $action  The audit action identifier.
	 * @param array  $context Structured context (actor, source/target uuids, counts, ...).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-every-dry-run-and-execute-call-must-produce-an-audit-log-entry
	 */
	public function log(string $action, array $context): void {
		$this->logger->info('OrganisationMerge audit: ' . $action, $context + ['audit' => true]);

		$this->eventDispatcher->dispatchTyped(
			new CriticalActionPerformedEvent(
				'OrganisationMerge: %s (source: %s, target: %s)',
				[
					$action,
					$context['sourceUuid'] ?? 'unknown',
					$context['targetUuid'] ?? 'unknown',
				]
			)
		);
	}//end log()

	/**
	 * Audit a blocked execution.
	 *
	 * @param MergeRun                                         $run      The run.
	 * @param array<int, array{type: string, message: string}> $blockers The blockers found.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-every-dry-run-and-execute-call-must-produce-an-audit-log-entry
	 */
	public function refused(MergeRun $run, array $blockers): void {
		$this->log(
			action: 'organisation-merge.execute.blocked',
			context: [
				'sourceUuid' => $run->sourceUuid,
				'targetUuid' => $run->targetUuid,
				'blockers' => $blockers,
				'actor' => $run->actorUid,
			]
		);
	}//end refused()

	/**
	 * Audit the start of an execution.
	 *
	 * @param MergeRun $run The run, with its progress operation.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-every-dry-run-and-execute-call-must-produce-an-audit-log-entry
	 */
	public function started(MergeRun $run): void {
		$this->log(
			action: 'organisation-merge.execute.start',
			context: ['sourceUuid' => $run->sourceUuid, 'targetUuid' => $run->targetUuid, 'actor' => $run->actorUid, 'operationId' => $run->operationId]
		);
	}//end started()

	/**
	 * Audit a finished execution.
	 *
	 * @param MergeRun $run    The run, with its operation and counts.
	 * @param string   $status The execution status.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-every-dry-run-and-execute-call-must-produce-an-audit-log-entry
	 */
	public function completed(MergeRun $run, string $status): void {
		$this->log(
			action: 'organisation-merge.execute.omschrijving',
			context: [
				'sourceUuid' => $run->sourceUuid,
				'targetUuid' => $run->targetUuid,
				'actor' => $run->actorUid,
				'operationId' => $run->operationId,
				'status' => $status,
				'counts' => $run->counts,
			]
		);
	}//end completed()
}//end class
