<?php

/**
 * Contract Status Service.
 *
 * Maintains the lifecycle status of catalog contracts: an `Actief` contract
 * whose `eindDatum` has passed is transitioned to `Verlopen`. The transition
 * is intentionally one-directional and narrow (see shouldExpire()) so a
 * scheduled run can never clobber `In onderhandeling` contracts, contracts
 * without an end date, or any manually-set status in another direction.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/contract-administration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Kernel\Contracts\ContractStatusServiceInterface;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Server-side maintenance of contract status (Actief → Verlopen).
 */
class ContractStatusService implements ContractStatusServiceInterface {
	/**
	 * The active status from which a contract may expire.
	 */
	public const STATUS_ACTIVE = 'Active';

	/**
	 * The expired status a passed-end-date active contract transitions to.
	 */
	public const STATUS_EXPIRED = 'Expired';

	/**
	 * The status of an active contract inside its notice window (REQ-CEO-001).
	 */
	public const STATUS_EXPIRING = 'Expiring';

	/**
	 * The notice window in days when the setting is empty or not positive.
	 */
	public const DEFAULT_WINDOW_DAYS = 90;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (lazy OR lookup).
	 * @param ObjectTypeLocatorInterface $settingsService Resolves register/schema ids.
	 * @param LoggerInterface $logger Logger.
	 * @param IAppConfig|null $appConfig Reads `contract_expiry_window_days`; without it the window is 90 days.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly ObjectTypeLocatorInterface $settingsService,
		private readonly LoggerInterface $logger,
		private readonly ?IAppConfig $appConfig = null,
	) {
	}//end __construct()

	/**
	 * Pure decision: should this contract transition Actief → Verlopen?
	 *
	 * Returns true ONLY when the status is exactly `Actief` AND a parseable
	 * `eindDatum` is strictly in the past relative to $now. Every other case
	 * (other status, missing/blank/unparseable end date, future end date)
	 * returns false — the transition is never applied.
	 *
	 * @param array $contractData The contract object data bag.
	 * @param DateTimeImmutable $now Logical "now".
	 *
	 * @return bool True when the contract must be expired.
	 *
	 * @spec openspec/specs/contract-administration/spec.md
	 */
	public function shouldExpire(array $contractData, DateTimeImmutable $now): bool {
		// REQ-CEO-002: an Expiring contract expires too once its end date has passed.
		$status = $contractData['status'] ?? null;
		if ($status !== self::STATUS_ACTIVE && $status !== self::STATUS_EXPIRING) {
			return false;
		}

		$endDate = $contractData['endDate'] ?? null;
		if (is_string($endDate) === false || trim($endDate) === '') {
			return false;
		}

		try {
			$end = new DateTimeImmutable($endDate);
		} catch (\Exception $e) {
			// Fail-closed: an unparseable end date never triggers a transition.
			$this->logger->debug(
				'ContractStatusService: unparseable eindDatum, skipping',
				['endDate' => $endDate, 'error' => $e->getMessage()]
			);
			return false;
		}

		// REQ-CEO-002: a contract expires AFTER its end date, so the end date
		// itself still counts as running (compared against the start of today).
		return $end < $now->setTime(0, 0);
	}//end shouldExpire()

	/**
	 * Pure decision: should this Active contract become Expiring? True when its
	 * end date is today or later and at most the window away (REQ-CEO-001).
	 *
	 * @param array             $contractData The contract object data bag.
	 * @param DateTimeImmutable $now          Logical "now".
	 * @param int               $windowDays   The notice window in days.
	 *
	 * @return bool True when the contract enters its notice window.
	 *
	 * @spec openspec/changes/contracts-expiry-and-owner/specs/contract-expiry-and-owner/spec.md#requirement-req-ceo-001-the-contract-status-must-include-expiring-set-by-the-daily-contract-job-inside-the-notice-window
	 */
	public function shouldStartExpiring(array $contractData, DateTimeImmutable $now, int $windowDays): bool {
		if (($contractData['status'] ?? null) !== self::STATUS_ACTIVE) {
			return false;
		}

		$end = $this->endDateOf(contractData: $contractData);
		if ($end === null) {
			return false;
		}

		$today = $now->setTime(0, 0);

		return $end >= $today && $end <= $today->modify('+' . $windowDays . ' days');
	}//end shouldStartExpiring()

	/**
	 * Pure decision: should this Expiring contract return to Active? True when
	 * its end date has moved more than the window away, because the contract
	 * was extended (REQ-CEO-002).
	 *
	 * @param array             $contractData The contract object data bag.
	 * @param DateTimeImmutable $now          Logical "now".
	 * @param int               $windowDays   The notice window in days.
	 *
	 * @return bool True when the contract leaves its notice window.
	 *
	 * @spec openspec/changes/contracts-expiry-and-owner/specs/contract-expiry-and-owner/spec.md#requirement-req-ceo-002-the-daily-job-shall-expire-an-expiring-contract-after-its-end-date-and-shall-return-it-to-active-when-its-end-date-moves-past-the-window
	 */
	public function shouldReturnToActive(array $contractData, DateTimeImmutable $now, int $windowDays): bool {
		if (($contractData['status'] ?? null) !== self::STATUS_EXPIRING) {
			return false;
		}

		$end = $this->endDateOf(contractData: $contractData);
		if ($end === null) {
			return false;
		}

		return $end > $now->setTime(0, 0)->modify('+' . $windowDays . ' days');
	}//end shouldReturnToActive()

	/**
	 * The notice window: `contract_expiry_window_days`, or 90 when it is empty or not positive.
	 *
	 * @return int The window in days.
	 *
	 * @spec openspec/changes/contracts-expiry-and-owner/specs/contract-expiry-and-owner/spec.md#requirement-req-ceo-001-the-contract-status-must-include-expiring-set-by-the-daily-contract-job-inside-the-notice-window
	 */
	public function windowDays(): int {
		if ($this->appConfig === null) {
			return self::DEFAULT_WINDOW_DAYS;
		}

		try {
			$days = $this->appConfig->getValueInt('stackiq', 'contract_expiry_window_days', self::DEFAULT_WINDOW_DAYS);
		} catch (\Throwable $e) {
			return self::DEFAULT_WINDOW_DAYS;
		}

		if ($days <= 0) {
			return self::DEFAULT_WINDOW_DAYS;
		}

		return $days;
	}//end windowDays()

	/**
	 * The daily pass over Active and Expiring contracts (REQ-CEO-001, REQ-CEO-002):
	 * Active to Expiring inside the window, Active or Expiring to Expired after
	 * the end date, Expiring to Active when the end date moved past the window.
	 * In negotiation and Expired contracts are never fetched, so never saved.
	 *
	 * @param DateTimeImmutable|null $now Logical "now" (defaults to the current time).
	 *
	 * @return array{expiring: int, expired: int, active: int} How many contracts moved to each status.
	 *
	 * @spec openspec/changes/contracts-expiry-and-owner/specs/contract-expiry-and-owner/spec.md#requirement-req-ceo-002-the-daily-job-shall-expire-an-expiring-contract-after-its-end-date-and-shall-return-it-to-active-when-its-end-date-moves-past-the-window
	 */
	public function updateContractStatuses(?DateTimeImmutable $now = null): array {
		$now = ($now ?? new DateTimeImmutable());
		$counts = ['expiring' => 0, 'expired' => 0, 'active' => 0];
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			$this->logger->info('ContractStatusService: ObjectService unavailable, skipping run');
			return $counts;
		}

		$registerId = $this->settingsService->getRegisterIdForObjectType('catalogContract');
		$schemaId = $this->settingsService->getSchemaIdForObjectType('catalogContract');
		if ($registerId === null || $schemaId === null) {
			$this->logger->info('ContractStatusService: contract register/schema not configured, skipping run');
			return $counts;
		}

		$window = $this->windowDays();
		foreach ([self::STATUS_ACTIVE, self::STATUS_EXPIRING] as $status) {
			$query = ['register' => $registerId, 'schema' => $schemaId, 'status' => $status, '_limit' => 5000];
			try {
				$contracts = $objectService->searchObjects(query: $query, _rbac: false, _multitenancy: false);
			} catch (\Throwable $e) {
				$this->logger->error('ContractStatusService: failed to query contracts', ['status' => $status, 'error' => $e->getMessage()]);
				continue;
			}

			foreach ($contracts as $contract) {
				$next = $this->nextStatus(data: $contract->getObject(), now: $now, window: $window);
				if ($next !== null && $this->moveTo(objectService: $objectService, contract: $contract, status: $next) === true) {
					$counts[strtolower($next)]++;
				}
			}
		}

		return $counts;
	}//end updateContractStatuses()

	/**
	 * The status a contract moves to in the daily pass, or null to leave it.
	 *
	 * @param array             $data   The contract data.
	 * @param DateTimeImmutable $now    Logical "now".
	 * @param int               $window The notice window in days.
	 *
	 * @return string|null Expired, Expiring, Active or null.
	 */
	private function nextStatus(array $data, DateTimeImmutable $now, int $window): ?string {
		if ($this->shouldExpire(contractData: $data, now: $now) === true) {
			return self::STATUS_EXPIRED;
		}

		if ($this->shouldStartExpiring(contractData: $data, now: $now, windowDays: $window) === true) {
			return self::STATUS_EXPIRING;
		}

		if ($this->shouldReturnToActive(contractData: $data, now: $now, windowDays: $window) === true) {
			return self::STATUS_ACTIVE;
		}

		return null;
	}//end nextStatus()

	/**
	 * Save a contract with a new status; a failed save is logged and not counted.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service.
	 * @param object                 $contract      The contract entity.
	 * @param string                 $status        The new status.
	 *
	 * @return bool True when saved.
	 */
	private function moveTo(ObjectServiceInterface $objectService, object $contract, string $status): bool {
		$data = $contract->getObject();
		$data['status'] = $status;
		try {
			$objectService->saveObject(
				object: $data,
				extend: [],
				register: $contract->getRegister(),
				schema: $contract->getSchema(),
				uuid: $contract->getUuid()
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ContractStatusService: failed to move contract',
				['uuid' => $contract->getUuid(), 'status' => $status, 'error' => $e->getMessage()]
			);
			return false;
		}

		$this->logger->info(
			'ContractStatusService: contract moved',
			['uuid' => $contract->getUuid(), 'status' => $status, 'endDate' => $data['endDate'] ?? null]
		);

		return true;
	}//end moveTo()

	/**
	 * A contract's parseable end date, or null.
	 *
	 * @param array $contractData The contract data.
	 *
	 * @return DateTimeImmutable|null The end date.
	 */
	private function endDateOf(array $contractData): ?DateTimeImmutable {
		$endDate = $contractData['endDate'] ?? null;
		if (is_string($endDate) === false || trim($endDate) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($endDate);
		} catch (\Exception $e) {
			return null;
		}
	}//end endDateOf()

	/**
	 * Scan all contracts and expire the eligible ones.
	 *
	 * Returns the number of contracts transitioned. Degrades to 0 (no error)
	 * when OpenRegister is unavailable or the contract schema is not configured.
	 *
	 * @param DateTimeImmutable|null $now Logical "now" (defaults to current time).
	 *
	 * @return int The number of contracts transitioned to Verlopen.
	 *
	 * @spec openspec/specs/contract-administration/spec.md
	 */
	public function expirePastContracts(?DateTimeImmutable $now = null): int {
		$now = ($now ?? new DateTimeImmutable());

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			$this->logger->info('ContractStatusService: ObjectService unavailable, skipping run');
			return 0;
		}

		$registerId = $this->settingsService->getRegisterIdForObjectType('catalogContract');
		$schemaId = $this->settingsService->getSchemaIdForObjectType('catalogContract');
		if ($registerId === null || $schemaId === null) {
			$this->logger->info('ContractStatusService: contract register/schema not configured, skipping run');
			return 0;
		}

		// This is a cron-driven scan that structurally needs "all active
		// contracts" (to find every one eligible for expiry) — bounded at a
		// documented safe ceiling rather than left unbounded.
		$query = [
			'register' => $registerId,
			'schema' => $schemaId,
			'status' => self::STATUS_ACTIVE,
			'_limit' => 5000,
		];

		try {
			$contracts = $objectService->searchObjects(query: $query, _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ContractStatusService: failed to query active contracts',
				['error' => $e->getMessage()]
			);
			return 0;
		}

		$transitioned = 0;
		foreach ($contracts as $contract) {
			$data = $contract->getObject();
			if ($this->shouldExpire(contractData: $data, now: $now) === false) {
				continue;
			}

			$data['status'] = self::STATUS_EXPIRED;
			try {
				$objectService->saveObject(
					object: $data,
					extend: [],
					register: $contract->getRegister(),
					schema: $contract->getSchema(),
					uuid: $contract->getUuid()
				);
				$transitioned++;
				$this->logger->info(
					'ContractStatusService: contract expired',
					['uuid' => $contract->getUuid(), 'endDate' => $data['endDate'] ?? null]
				);
			} catch (\Throwable $e) {
				$this->logger->error(
					'ContractStatusService: failed to expire contract',
					['uuid' => $contract->getUuid(), 'error' => $e->getMessage()]
				);
			}
		}//end foreach

		return $transitioned;
	}//end expirePastContracts()

	/**
	 * Lazily resolve the OpenRegister ObjectService.
	 *
	 * @return ObjectServiceInterface|null The service, or null when OpenRegister is absent.
	 *
	 * @spec openspec/specs/contract-administration/spec.md
	 */
	private function getObjectService(): ?ObjectServiceInterface {
		try {
			// Ask for the CONTRACT and narrow on the CONTRACT (ADR-084) — see
			// ContractApprovalService::getObjectService() for why gating on the
			// concrete class is a silent fail-closed.
			$service = $this->container->get(ObjectServiceInterface::class);
			if ($service instanceof ObjectServiceInterface) {
				return $service;
			}
		} catch (\Throwable $e) {
			$this->logger->debug('ContractStatusService: ObjectService not resolvable', ['error' => $e->getMessage()]);
		}

		return null;
	}//end getObjectService()
}//end class
