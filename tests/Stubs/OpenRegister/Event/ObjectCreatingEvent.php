<?php

/**
 * Test stub of OpenRegister's ObjectCreatingEvent.
 *
 * A copy of `lib/Event/ObjectCreatingEvent.php` in ConductionNL/openregister at
 * development 609cdd1cbb (23 Oct 2026): the same constructor and accessors, so a
 * listener test that calls a method the real event lacks fails here as it
 * would in production.
 *
 * @category  Tests
 * @package   OCA\OpenRegister\Event
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Dispatched before an object is created; listeners may change its data.
 */
class ObjectCreatingEvent extends Event implements StoppableEventInterface {
	private bool $propagationStopped = false;

	/** @var array<string, mixed> */
	private array $errors = [];

	/** @var array<string, mixed> */
	private array $modifiedData = [];

	public function __construct(private ObjectEntity $object) {
		parent::__construct();
	}

	public function getObject(): ObjectEntity {
		return $this->object;
	}

	public function isPropagationStopped(): bool {
		return $this->propagationStopped;
	}

	public function stopPropagation(): void {
		$this->propagationStopped = true;
	}

	/** @param array<string, mixed> $errors */
	public function setErrors(array $errors): void {
		$this->errors = $errors;
	}

	/** @return array<string, mixed> */
	public function getErrors(): array {
		return $this->errors;
	}

	/** @param array<string, mixed> $data */
	public function setModifiedData(array $data): void {
		$this->modifiedData = $data;
	}

	/** @return array<string, mixed> */
	public function getModifiedData(): array {
		return $this->modifiedData;
	}
}
