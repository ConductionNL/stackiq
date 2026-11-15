<?php

/**
 * StackIQ McpSbomGate
 *
 * The guards of the SBOM tools (mcp-full-action-surface tasks 4.1 and 5.5),
 * the same as `SbomController`'s. Reading the import status needs an
 * administrator or read access to the version's parent application, and a
 * refusal looks like an unknown version. Importing needs an administrator, or
 * a manage group member who can read the parent application.
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
 * @spec openspec/changes/mcp-full-action-surface/tasks.md#5.5
 */

declare(strict_types=1);

namespace OCA\Stackiq\Mcp;

use OCA\Stackiq\Kernel\Contracts\SbomImportServiceInterface;
use OCP\IGroupManager;

/**
 * Proves the caller may read or import the SBOM of one application version.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) McpArgumentValidator::error() is the one pure builder of the structured MCP error.
 * @spec openspec/changes/mcp-full-action-surface/tasks.md#5.5
 */
class McpSbomGate {

	/**
	 * The groups that may import an SBOM, as in SbomController.
	 *
	 * @var array<int, string>
	 */
	public const MANAGE_GROUPS = ['software-catalog-admins', 'aanbod-beheerder', 'functioneel-beheerder'];

	/**
	 * Constructor.
	 *
	 * @param McpAdminGate               $session      The caller.
	 * @param SbomImportServiceInterface $sbom         The parent lookup and read check.
	 * @param IGroupManager              $groupManager The caller's groups.
	 */
	public function __construct(
		private readonly McpAdminGate $session,
		private readonly SbomImportServiceInterface $sbom,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Refuse, or null when the caller may read the version's import status.
	 *
	 * @param string $moduleVersionUuid The application version.
	 *
	 * @return array<string, mixed>|null The refusal, or null.
	 *
	 * @spec openspec/changes/mcp-full-action-surface/tasks.md#4.1
	 */
	public function authoriseRead(string $moduleVersionUuid): ?array {
		$user = $this->session->currentUser();
		if ($user === null) {
			return McpArgumentValidator::error(code: 'unauthenticated', message: 'This tool needs a signed-in user.');
		}

		if ($this->groupManager->isAdmin($user->getUID()) === true || $this->canReadParent(moduleVersionUuid: $moduleVersionUuid) === true) {
			return null;
		}

		return McpArgumentValidator::error(code: 'not_found', message: 'Application version not found.');
	}//end authoriseRead()

	/**
	 * Refuse, or null when the caller may import an SBOM for the version.
	 *
	 * @param string $moduleVersionUuid The application version.
	 *
	 * @return array<string, mixed>|null The refusal, or null.
	 *
	 * @spec openspec/changes/mcp-full-action-surface/tasks.md#5.5
	 */
	public function authoriseImport(string $moduleVersionUuid): ?array {
		$user = $this->session->currentUser();
		if ($user === null) {
			return McpArgumentValidator::error(code: 'unauthenticated', message: 'This tool needs a signed-in user.');
		}

		$uid = $user->getUID();
		if ($this->groupManager->isAdmin($uid) === true) {
			return null;
		}

		$inManageGroup = false;
		foreach (self::MANAGE_GROUPS as $group) {
			if ($this->groupManager->isInGroup($uid, $group) === true) {
				$inManageGroup = true;
				break;
			}
		}

		if ($inManageGroup === false) {
			return McpArgumentValidator::error(code: 'forbidden', message: 'Admin privileges or a manage role are required to import an SBOM.');
		}

		if ($this->canReadParent(moduleVersionUuid: $moduleVersionUuid) === false) {
			return McpArgumentValidator::error(code: 'forbidden', message: 'You do not have manage access to this application.');
		}

		return null;
	}//end authoriseImport()

	/**
	 * Whether the caller can read the version's parent application.
	 *
	 * @param string $moduleVersionUuid The application version.
	 *
	 * @return bool
	 */
	private function canReadParent(string $moduleVersionUuid): bool {
		$moduleUuid = $this->sbom->resolveParentModuleUuid($moduleVersionUuid);
		return $moduleUuid !== null && $this->sbom->userCanReadModule($moduleUuid) === true;
	}//end canReadParent()
}//end class
