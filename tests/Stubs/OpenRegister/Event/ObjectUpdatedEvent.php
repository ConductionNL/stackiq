<?php

/**
 * Test stub of OpenRegister's ObjectUpdatedEvent.
 *
 * A copy of `lib/Event/ObjectUpdatedEvent.php` in ConductionNL/openregister at
 * development cabd4106 (2 Oct 2026): the same constructor and the same
 * accessors, so a listener test that calls a method the real event lacks fails
 * here as it would in production.
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

/**
 * Dispatched after an object is updated.
 */
class ObjectUpdatedEvent extends Event {

	/**
	 * The object after the update.
	 *
	 * @var ObjectEntity
	 */
	private ObjectEntity $newObject;

	/**
	 * The object before the update, when known.
	 *
	 * @var ObjectEntity|null
	 */
	private ?ObjectEntity $oldObject;

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity      $newObject The object after the update.
	 * @param ObjectEntity|null $oldObject The object before the update, when known.
	 */
	public function __construct(ObjectEntity $newObject, ?ObjectEntity $oldObject = null) {
		parent::__construct();
		$this->newObject = $newObject;
		$this->oldObject = $oldObject;
	}//end __construct()

	/**
	 * The object after the update.
	 *
	 * @return ObjectEntity The object.
	 */
	public function getObject(): ObjectEntity {
		return $this->newObject;
	}//end getObject()

	/**
	 * The object after the update.
	 *
	 * @return ObjectEntity The object.
	 */
	public function getNewObject(): ObjectEntity {
		return $this->newObject;
	}//end getNewObject()

	/**
	 * The object before the update.
	 *
	 * @return ObjectEntity|null The object, or null when it is not known.
	 */
	public function getOldObject(): ?ObjectEntity {
		return $this->oldObject;
	}//end getOldObject()
}//end class
