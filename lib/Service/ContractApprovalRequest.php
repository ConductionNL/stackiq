<?php

/**
 * Contract approval request
 *
 * A contract submitted for approval and the decision it asks for, built and
 * read only by ContractApprovalService.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/contract-decision-delegation/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

/**
 * One submission: the contract, its data and the decision type asked for.
 *
 * @category Service
 * @package  OCA\Stackiq\Service
 *
 * @spec openspec/specs/contract-decision-delegation/spec.md
 */
final class ContractApprovalRequest {

	/**
	 * The contract uuid.
	 *
	 * @var string
	 */
	public string $contractUuid = '';

	/**
	 * The contract entity.
	 *
	 * @var object
	 */
	public object $contract;

	/**
	 * The contract data.
	 *
	 * @var array<string, mixed>
	 */
	public array $data = [];

	/**
	 * The decision type raised: contract or contract-renewal.
	 *
	 * @var string
	 */
	public string $decisionType = '';
}//end class
