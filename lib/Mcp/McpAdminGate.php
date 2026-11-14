<?php

/**
 * StackIQ McpAdminGate
 *
 * The signed-in caller and the administrator check for the MCP tools whose
 * REST twins are admin-only (moderation, merge, EOL sync, membership). A
 * failing check denies; nothing here turns an error into an allow
 * (mcp-full-action-surface task 3.3).
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
 * @spec openspec/changes/mcp-full-action-surface/specs/mcp-tool-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Mcp;

use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use Throwable;

/**
 * Caller and administrator checks for MCP tools.
 *
 * @spec openspec/changes/mcp-full-action-surface/specs/mcp-tool-surface/spec.md
 */
class McpAdminGate {

	/**
	 * Constructor.
	 *
	 * @param IUserSession  $userSession  The session the tool call runs in.
	 * @param IGroupManager $groupManager The administrator check.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * The signed-in caller, or null.
	 *
	 * @return IUser|null The user.
	 *
	 * @spec openspec/changes/mcp-full-action-surface/specs/mcp-tool-surface/spec.md
	 */
	public function currentUser(): ?IUser {
		return $this->userSession->getUser();
	}//end currentUser()

	/**
	 * Refuse unless a user is signed in.
	 *
	 * @return array<string, mixed>|null The refusal, or null.
	 *
	 * @spec openspec/changes/mcp-full-action-surface/specs/mcp-tool-surface/spec.md
	 */
	public function requireUser(): ?array {
		if ($this->currentUser() === null) {
			return McpArgumentValidator::error(code: 'unauthenticated', message: 'This tool needs a signed-in user.');
		}

		return null;
	}//end requireUser()

	/**
	 * Refuse unless the signed-in caller is an administrator.
	 *
	 * @return array<string, mixed>|null The refusal, or null for an administrator.
	 *
	 * @spec openspec/changes/mcp-full-action-surface/specs/mcp-tool-surface/spec.md
	 */
	public function requireAdmin(): ?array {
		$user = $this->currentUser();
		if ($user === null) {
			return McpArgumentValidator::error(code: 'unauthenticated', message: 'This tool needs a signed-in user.');
		}

		try {
			$isAdmin = ($this->groupManager->isAdmin($user->getUID()) === true);
		} catch (Throwable) {
			$isAdmin = false;
		}

		if ($isAdmin === false) {
			return McpArgumentValidator::error(code: 'forbidden', message: 'Only an administrator may use this tool.');
		}

		return null;
	}//end requireAdmin()
}//end class
