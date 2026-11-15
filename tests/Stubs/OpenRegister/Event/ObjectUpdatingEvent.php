<?php

/**
 * Test stub of OpenRegister's ObjectUpdatingEvent.
 *
 * A copy of `lib/Event/ObjectUpdatingEvent.php` in ConductionNL/openregister at
 * development 05806b135b (4 Nov 2026): the same constructor and accessors.
 *
 * @category  Tests
 * @package   OCA\OpenRegister\Event
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;
use Psr\EventDispatcher\StoppableEventInterface;
class ObjectUpdatingEvent extends Event implements StoppableEventInterface {
	private ObjectEntity $newObject;
	private ?ObjectEntity $oldObject;
	private bool $propagationStopped = false;
	private array $errors = [];
	private array $modifiedData = [];
	public function __construct(ObjectEntity $newObject, ?ObjectEntity $oldObject = null) {
		parent::__construct();
		$this->newObject = $newObject;
		$this->oldObject = $oldObject;
	}//end __construct()
	public function getNewObject(): ObjectEntity {
		return $this->newObject;
	}//end getNewObject()
	public function getOldObject(): ?ObjectEntity {
		return $this->oldObject;
	}//end getOldObject()
	public function isPropagationStopped(): bool {
		return $this->propagationStopped;
	}//end isPropagationStopped()
	public function stopPropagation(): void {
		$this->propagationStopped = true;
	}//end stopPropagation()
	public function setErrors(array $errors): void {
		$this->errors = $errors;
	}//end setErrors()
	public function getErrors(): array {
		return $this->errors;
	}//end getErrors()
	public function setModifiedData(array $data): void {
		$this->modifiedData = $data;
	}//end setModifiedData()
	public function getModifiedData(): array {
		return $this->modifiedData;
	}//end getModifiedData()
}//end class
