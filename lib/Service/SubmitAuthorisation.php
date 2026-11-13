<?php

/**
 * Contract submit authorisation
 *
 * Who asks to submit which contract for approval, as the ownership guard of
 * ContractApprovalService decides it.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/contract-approval-ownership-guard/specs/contract-decision-delegation/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

/**
 * One submit request: the contract, the caller's groups and the caller's active organisation.
 *
 * @category Service
 * @package  OCA\Stackiq\Service
 *
 * @spec openspec/changes/contract-approval-ownership-guard/specs/contract-decision-delegation/spec.md
 */
final class SubmitAuthorisation {

	/**
	 * The contract OR object uuid.
	 *
	 * @var string
	 */
	public string $contractUuid = '';

	/**
	 * The caller's NC group ids.
	 *
	 * @var array<int, string>
	 */
	public array $groupNames = [];

	/**
	 * The caller's active organisation uuid (may be empty).
	 *
	 * @var string
	 */
	public string $activeOrgUuid = '';
}//end class
