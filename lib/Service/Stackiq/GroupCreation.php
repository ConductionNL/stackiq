<?php

/**
 * Creates a Nextcloud group when it does not exist yet.
 *
 * @category  Handler
 * @package   OCA\Stackiq\Service\Stackiq
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/sc-handlers/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Stackiq;

use OCP\IGroup;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

/**
 * Returns a named group, creating it when it is missing.
 *
 * @category  Handler
 * @package   OCA\Stackiq\Service\Stackiq
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/sc-handlers/spec.md
 */
final class GroupCreation {
	/**
	 * Constructor.
	 *
	 * @param IGroupManager   $groupManager Holds and creates the groups.
	 * @param LoggerInterface $logger       Logs a created group and a failed creation.
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Creates a group if it doesn't exist
	 *
	 * @param string $groupName The group name to create
	 *
	 * @return IGroup|null The created or existing group
	 * @spec   openspec/specs/sc-handlers/spec.md
	 */
	public function createIfNotExists(string $groupName): ?IGroup {
		$group = $this->groupManager->get($groupName);

		if ($group === null) {
			try {
				$group = $this->groupManager->createGroup($groupName);
				$this->logger->info(
					'Created new group',
					[
						'groupName' => $groupName,
					]
				);
			} catch (\Exception $e) {
				$this->logger->error(
					'Failed to create group: ' . $e->getMessage(),
					[
						'groupName' => $groupName,
						'exception' => $e,
					]
				);
				return null;
			}
		}

		return $group;
	}//end createIfNotExists()
}//end class
