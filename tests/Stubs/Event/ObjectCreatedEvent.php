<?php

/**
 * Test stub of OpenRegister's ObjectCreatedEvent.
 *
 * A copy of `lib/Event/ObjectCreatedEvent.php` in ConductionNL/openregister at
 * development 4abd8343 (29 Sep 2026): the same constructor and the same
 * accessor, so a listener test that calls a method the real event lacks fails
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
 * Dispatched after an object is created.
 */
class ObjectCreatedEvent extends Event {

	/**
	 * The created object.
	 *
	 * @var ObjectEntity
	 */
	private ObjectEntity $object;

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $object The created object.
	 */
	public function __construct(ObjectEntity $object) {
		parent::__construct();
		$this->object = $object;
	}//end __construct()

	/**
	 * The created object.
	 *
	 * @return ObjectEntity The object.
	 */
	public function getObject(): ObjectEntity {
		return $this->object;
	}//end getObject()
}//end class
