<?php

/**
 * Test stub of OpenRegister's ObjectDeletedEvent.
 *
 * A copy of `lib/Event/ObjectDeletedEvent.php` in ConductionNL/openregister at
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
 * Dispatched after an object is deleted.
 */
class ObjectDeletedEvent extends Event {

	/**
	 * The deleted object.
	 *
	 * @var ObjectEntity
	 */
	private ObjectEntity $object;

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $object The deleted object.
	 */
	public function __construct(ObjectEntity $object) {
		parent::__construct();
		$this->object = $object;
	}//end __construct()

	/**
	 * The deleted object.
	 *
	 * @return ObjectEntity The object.
	 */
	public function getObject(): ObjectEntity {
		return $this->object;
	}//end getObject()
}//end class
