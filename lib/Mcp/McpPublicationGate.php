<?php

/**
 * StackIQ McpPublicationGate
 *
 * The per-object guard of the publish and depublish tools
 * (mcp-full-action-surface task 3.2). It runs the steps
 * `PublicationController::authorizeEntry()` runs, in the same order, on the
 * same services: a signed-in caller, a publishable type, an entry that
 * resolves, not peer-sourced, and then an administrator, or an
 * `aanbod-beheerder` whose active organisation owns the entry.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Mcp
 * @package  OCA\Stackiq\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/mcp-full-action-surface/tasks.md#3.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Mcp;

use OCA\Stackiq\Kernel\Contracts\PublicationServiceInterface;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;

/**
 * Proves the caller may publish or depublish one catalogue entry.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) McpArgumentValidator::error() is the one pure builder of the structured MCP error.
 * @spec openspec/changes/mcp-full-action-surface/tasks.md#3.2
 */
class McpPublicationGate {

	/**
	 * Constructor.
	 *
	 * @param McpAdminGate                $session      The caller.
	 * @param PublicationServiceInterface $publications The entry lookup.
	 * @param IGroupManager               $groupManager The caller's groups.
	 * @param IConfig                     $config       The caller's active organisation.
	 */
	public function __construct(
		private readonly McpAdminGate $session,
		private readonly PublicationServiceInterface $publications,
		private readonly IGroupManager $groupManager,
		private readonly IConfig $config,
	) {
	}//end __construct()

	/**
	 * Refuse, or null when the caller may change the entry's publication.
	 *
	 * @param string $objectType The catalogue type.
	 * @param string $uuid       The entry.
	 *
	 * @return array<string, mixed>|null The refusal, or null.
	 *
	 * @spec openspec/changes/mcp-full-action-surface/tasks.md#3.2
	 */
	public function authorise(string $objectType, string $uuid): ?array {
		$user = $this->session->currentUser();
		if ($user === null) {
			return McpArgumentValidator::error(code: 'unauthenticated', message: 'This tool needs a signed-in user.');
		}

		if ($this->publications->isPublishableType($objectType) === false) {
			return McpArgumentValidator::error(code: 'validation_error', message: 'Object type is not publishable.');
		}

		$entry = $this->publications->resolveEntry($objectType, $uuid);
		if ($entry === null) {
			return McpArgumentValidator::error(code: 'not_found', message: 'Entry not found.');
		}

		$data = ($entry['data'] ?? []);
		if (is_array($data) === false) {
			$data = [];
		}

		if (self::isPeerSourced(data: $data) === true) {
			return McpArgumentValidator::error(code: 'forbidden', message: 'Peer-sourced entries cannot be published locally.');
		}

		$groups = $this->groupNamesOf(user: $user);
		if (in_array('admin', $groups, true) === true) {
			return null;
		}

		if (in_array('aanbod-beheerder', $groups, true) === false || $this->ownsEntry(user: $user, data: $data) === false) {
			return McpArgumentValidator::error(code: 'forbidden', message: 'You do not have permission to publish this entry.');
		}

		return null;
	}//end authorise()

	/**
	 * Whether the entry came from a federation peer.
	 *
	 * @param array<string, mixed> $data The entry data.
	 *
	 * @return bool
	 */
	private static function isPeerSourced(array $data): bool {
		$source = ($data['_source'] ?? null);
		return is_array($source) === true && trim((string)($source['instance'] ?? '')) !== '';
	}//end isPeerSourced()

	/**
	 * Whether the caller's active organisation owns the entry.
	 *
	 * @param IUser                $user The caller.
	 * @param array<string, mixed> $data The entry data.
	 *
	 * @return bool
	 */
	private function ownsEntry(IUser $user, array $data): bool {
		$orgUuid = (string)$this->config->getUserValue($user->getUID(), 'core', 'organisation');
		$ownerOrg = (string)($data['_organisation'] ?? $data['provider'] ?? '');
		return $orgUuid !== '' && $ownerOrg !== '' && $orgUuid === $ownerOrg;
	}//end ownsEntry()

	/**
	 * The caller's group ids.
	 *
	 * @param IUser $user The caller.
	 *
	 * @return array<int, string>
	 */
	private function groupNamesOf(IUser $user): array {
		return array_values(
			array_map(
				static fn (IGroup $group): string => $group->getGID(),
				$this->groupManager->getUserGroups($user)
			)
		);
	}//end groupNamesOf()
}//end class
