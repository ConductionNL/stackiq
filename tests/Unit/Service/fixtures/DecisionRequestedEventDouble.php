<?php

/**
 * Double of the decision app's request event, for ContractSubmitForApprovalTest.
 *
 * Declares OCA\Decidiq\Event\DecisionRequestedEvent, recording every
 * construction. Only required inside that test's separate processes: the
 * other contract tests require the class to be absent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 */

declare(strict_types=1);

namespace OCA\Decidiq\Event;

use OCP\EventDispatcher\Event;

/**
 * Double: records its arguments; the dispatcher double answers it.
 */
class DecisionRequestedEvent extends Event {

	/**
	 * The arguments of every construction, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	public static array $made = [];

	/**
	 * Whether a listener handled the request.
	 *
	 * @var bool
	 */
	public bool $handled = false;

	/**
	 * The decision id the listener answered.
	 *
	 * @var string|null
	 */
	public ?string $decisionId = null;

	/**
	 * Record the request.
	 *
	 * @param string $sourceApp         The requesting app.
	 * @param string $subjectRegister   The subject register.
	 * @param string $subjectSchema     The subject schema.
	 * @param string $subjectId         The subject id.
	 * @param string $title             The subject label.
	 * @param string $decisionType      The decision type.
	 * @param string $description       The description.
	 * @param array  $metadata          The metadata.
	 * @param string $externalReference The external reference.
	 * @param string $correlationId     The correlation id.
	 */
	public function __construct(
		string $sourceApp,
		string $subjectRegister,
		string $subjectSchema,
		string $subjectId,
		string $title,
		string $decisionType,
		string $description,
		array $metadata,
		string $externalReference,
		string $correlationId
	) {
		parent::__construct();
		self::$made[] = [$sourceApp, $subjectRegister, $subjectSchema, $subjectId, $title, $decisionType, $description, $metadata, $externalReference, $correlationId];
	}//end __construct()

	/**
	 * Whether a listener handled the request.
	 *
	 * @return bool
	 */
	public function isHandled(): bool {
		return $this->handled;
	}//end isHandled()

	/**
	 * The decision id the listener answered.
	 *
	 * @return string|null
	 */
	public function getDecisionId(): ?string {
		return $this->decisionId;
	}//end getDecisionId()
}//end class
