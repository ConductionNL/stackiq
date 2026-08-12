<?php

/**
 * Static-analysis stubs for the decidesk decision-event contract.
 *
 * ANALYSIS-ONLY — referenced from phpstan.neon `scanFiles` and psalm.xml
 * `<stubs>`, and NEVER loaded at runtime or during PHPUnit.
 *
 * Why this lives in `tests/analysis-stubs/` and NOT in `tests/Stubs/`:
 * `tests/bootstrap.php` `require_once`s every file matching
 * `tests/Stubs/{,**\/}*.php` BEFORE Nextcloud's app bootstrap, deliberately
 * letting those stubs win over the real classes for mock generation. Doing
 * that to the decidesk event classes would shadow the REAL events that
 * ContractApprovalService dispatches through IEventDispatcher on any
 * instance where decidesk is installed. These declarations must therefore
 * stay out of that glob.
 *
 * The real classes live in the sibling `decidesk` app
 * (decidesk/lib/Event/DecisionConcludedEvent.php and
 * DecisionRequestedEvent.php). decidesk is a Nextcloud app, not a Composer
 * dependency, so it is genuinely absent from the CI analysis path. The
 * signatures below mirror the real ones exactly; they supply real type
 * information rather than silencing the diagnostic, which is what also
 * resolves the `Call to method X() on an unknown class` errors that a bare
 * ignore pattern cannot fix.
 *
 * @category Test
 * @package  OCA\Decidesk\Event
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Event;

use OCP\EventDispatcher\Event;

/**
 * Analysis-only mirror of decidesk's DecisionConcludedEvent.
 *
 * Dispatched by decidesk when a Decision reaches a terminal outcome; consumed
 * by SoftwareCatalog's DecisionConcludedListener.
 */
class DecisionConcludedEvent extends Event {

	/**
	 * Construct the conclusion event.
	 *
	 * @param string $decisionId The concluded Decision id.
	 * @param string $decisionType The Decision type.
	 * @param string $status Derived outcome status.
	 * @param string $outcome Raw decision outcome.
	 * @param bool $signed Whether a signature stage resolved.
	 * @param string|null $signingReference Signing reference, when signed.
	 * @param array<int, mixed> $signers Resolved signers list.
	 * @param string|null $decidedAt When the decision concluded.
	 * @param string $sourceApp Consumer app that raised the decision.
	 * @param string|null $subjectRegister OpenRegister register of the origin object.
	 * @param string|null $subjectSchema OpenRegister schema of the origin object.
	 * @param string|null $subjectId OpenRegister id of the origin object.
	 * @param string $externalReference Consumer's own reference.
	 * @param string $correlationId Correlation id from the request event.
	 */
	public function __construct(
		private readonly string $decisionId,
		private readonly string $decisionType,
		private readonly string $status,
		private readonly string $outcome,
		private readonly bool $signed,
		private readonly ?string $signingReference,
		private readonly array $signers,
		private readonly ?string $decidedAt,
		private readonly string $sourceApp,
		private readonly ?string $subjectRegister,
		private readonly ?string $subjectSchema,
		private readonly ?string $subjectId,
		private readonly string $externalReference = '',
		private readonly string $correlationId = '',
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Get the concluded Decision id.
	 *
	 * @return string
	 */
	public function getDecisionId(): string {
		return $this->decisionId;
	}//end getDecisionId()

	/**
	 * Get the Decision type.
	 *
	 * @return string
	 */
	public function getDecisionType(): string {
		return $this->decisionType;
	}//end getDecisionType()

	/**
	 * Get the derived outcome status.
	 *
	 * @return string
	 */
	public function getStatus(): string {
		return $this->status;
	}//end getStatus()

	/**
	 * Get the raw decision outcome.
	 *
	 * @return string
	 */
	public function getOutcome(): string {
		return $this->outcome;
	}//end getOutcome()

	/**
	 * Whether a signature stage resolved.
	 *
	 * @return bool
	 */
	public function isSigned(): bool {
		return $this->signed;
	}//end isSigned()

	/**
	 * Get the signing reference, when signed.
	 *
	 * @return string|null
	 */
	public function getSigningReference(): ?string {
		return $this->signingReference;
	}//end getSigningReference()

	/**
	 * Get the resolved signers list.
	 *
	 * @return array<int, mixed>
	 */
	public function getSigners(): array {
		return $this->signers;
	}//end getSigners()

	/**
	 * Get when the decision concluded.
	 *
	 * @return string|null
	 */
	public function getDecidedAt(): ?string {
		return $this->decidedAt;
	}//end getDecidedAt()

	/**
	 * Get the consumer app that raised the decision.
	 *
	 * @return string
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * Get the OpenRegister register of the originating object.
	 *
	 * @return string|null
	 */
	public function getSubjectRegister(): ?string {
		return $this->subjectRegister;
	}//end getSubjectRegister()

	/**
	 * Get the OpenRegister schema of the originating object.
	 *
	 * @return string|null
	 */
	public function getSubjectSchema(): ?string {
		return $this->subjectSchema;
	}//end getSubjectSchema()

	/**
	 * Get the OpenRegister id of the originating object.
	 *
	 * @return string|null
	 */
	public function getSubjectId(): ?string {
		return $this->subjectId;
	}//end getSubjectId()

	/**
	 * Get the consumer's own reference.
	 *
	 * @return string
	 */
	public function getExternalReference(): string {
		return $this->externalReference;
	}//end getExternalReference()

	/**
	 * Get the correlation id from the request event.
	 *
	 * @return string
	 */
	public function getCorrelationId(): string {
		return $this->correlationId;
	}//end getCorrelationId()

}//end class

/**
 * Analysis-only mirror of decidesk's DecisionRequestedEvent.
 *
 * Dispatched by SoftwareCatalog's ContractApprovalService to ask decidesk to
 * open a Decision; decidesk's listener fills the `decisionId` / `handled`
 * result slots before the dispatch call returns.
 */
class DecisionRequestedEvent extends Event {

	/**
	 * The id of the Decision decidesk created or matched (result slot).
	 *
	 * @var string|null
	 */
	private ?string $decisionId = null;

	/**
	 * Whether decidesk's listener handled this request (result slot).
	 *
	 * @var boolean
	 */
	private bool $handled = false;

	/**
	 * Construct the request event.
	 *
	 * @param string $sourceApp The consumer app raising the decision.
	 * @param string $subjectRegister OpenRegister register of the origin object.
	 * @param string $subjectSchema OpenRegister schema of the origin object.
	 * @param string $subjectId OpenRegister id of the origin object.
	 * @param string $subjectLabel Human display label for the subject.
	 * @param string $decisionType Decision type.
	 * @param string $actorId Nextcloud UID of the requesting user.
	 * @param array<string, mixed> $payload Additional decision body fields.
	 * @param string $externalReference Consumer's own reference.
	 * @param string $correlationId Correlation id echoed on the conclusion event.
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $subjectRegister,
		private readonly string $subjectSchema,
		private readonly string $subjectId,
		private readonly string $subjectLabel = '',
		private readonly string $decisionType = 'contract',
		private readonly string $actorId = '',
		private readonly array $payload = [],
		private readonly string $externalReference = '',
		private readonly string $correlationId = '',
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Get the consumer app that raised the decision.
	 *
	 * @return string
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * Get the OpenRegister register of the originating object.
	 *
	 * @return string
	 */
	public function getSubjectRegister(): string {
		return $this->subjectRegister;
	}//end getSubjectRegister()

	/**
	 * Get the OpenRegister schema of the originating object.
	 *
	 * @return string
	 */
	public function getSubjectSchema(): string {
		return $this->subjectSchema;
	}//end getSubjectSchema()

	/**
	 * Get the OpenRegister id of the originating object.
	 *
	 * @return string
	 */
	public function getSubjectId(): string {
		return $this->subjectId;
	}//end getSubjectId()

	/**
	 * Get the human display label for the subject.
	 *
	 * @return string
	 */
	public function getSubjectLabel(): string {
		return $this->subjectLabel;
	}//end getSubjectLabel()

	/**
	 * Get the decision type.
	 *
	 * @return string
	 */
	public function getDecisionType(): string {
		return $this->decisionType;
	}//end getDecisionType()

	/**
	 * Get the Nextcloud UID of the requesting user.
	 *
	 * @return string
	 */
	public function getActorId(): string {
		return $this->actorId;
	}//end getActorId()

	/**
	 * Get the additional decision body fields.
	 *
	 * @return array<string, mixed>
	 */
	public function getPayload(): array {
		return $this->payload;
	}//end getPayload()

	/**
	 * Get the consumer's own reference.
	 *
	 * @return string
	 */
	public function getExternalReference(): string {
		return $this->externalReference;
	}//end getExternalReference()

	/**
	 * Get the correlation id echoed on the conclusion event.
	 *
	 * @return string
	 */
	public function getCorrelationId(): string {
		return $this->correlationId;
	}//end getCorrelationId()

	/**
	 * Get the id of the Decision decidesk created or matched.
	 *
	 * @return string|null
	 */
	public function getDecisionId(): ?string {
		return $this->decisionId;
	}//end getDecisionId()

	/**
	 * Record the id of the Decision decidesk created or matched.
	 *
	 * @param string $decisionId The Decision id.
	 *
	 * @return void
	 */
	public function setDecisionId(string $decisionId): void {
		$this->decisionId = $decisionId;
	}//end setDecisionId()

	/**
	 * Whether decidesk's listener handled this request.
	 *
	 * @return bool
	 */
	public function isHandled(): bool {
		return $this->handled;
	}//end isHandled()

	/**
	 * Record whether decidesk's listener handled this request.
	 *
	 * @param bool $handled Whether the request was handled.
	 *
	 * @return void
	 */
	public function setHandled(bool $handled): void {
		$this->handled = $handled;
	}//end setHandled()

}//end class
