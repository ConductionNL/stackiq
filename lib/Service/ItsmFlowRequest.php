<?php

/**
 * What one exchange set-up fills the flow templates with.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

/**
 * The desk, the organisation, the run-as user, the desk source's location and the asset template of one set-up.
 *
 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
 */
final class ItsmFlowRequest {
	/**
	 * Constructor.
	 *
	 * @param string $desk         The desk key (topdesk, servicenow).
	 * @param string $organisation The organisation whose applications are exchanged: as given, or its uuid once resolved.
	 * @param string $runAs        The user the scheduled imports run as.
	 * @param string $location     The desk source's location; its scheme and host make the record links. '' until resolved.
	 * @param string $templateId   The desk's asset template for a new record (TOPdesk needs one), or empty.
	 */
	public function __construct(
		public readonly string $desk,
		public readonly string $organisation,
		public readonly string $runAs,
		public readonly string $location,
		public readonly string $templateId,
	) {
	}//end __construct()

	/**
	 * The same set-up for the organisation's uuid and the desk source's location.
	 *
	 * @param string $organisation The organisation's uuid.
	 * @param string $location     The desk source's location.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
	 */
	public function withSource(string $organisation, string $location): self {
		return new self(desk: $this->desk, organisation: $organisation, runAs: $this->runAs, location: $location, templateId: $this->templateId);
	}//end withSource()
}//end class
