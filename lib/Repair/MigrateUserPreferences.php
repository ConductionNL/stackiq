<?php

/**
 * Repair step that carries `oc_preferences` rows across the app-id rename.
 *
 * Nextcloud namespaces `oc_preferences` BY APP ID exactly as it does
 * `oc_appconfig`, so every per-user preference this app stored under
 * `stackiq` became unreachable when the id moved to `stackiq`. The
 * app's preference reads all pass a default, so nothing errors — a user's saved
 * view mode, column choice and filter simply revert, which reads as "the app
 * forgot my settings" rather than as a failure.
 *
 * The user enumeration deliberately does NOT use
 * `IConfig::getUsersForUserValue()`. That method matches on a VALUE, and this
 * app's `pref_*` keys hold arbitrary user-chosen state — over an open value set
 * it matches nothing, migrates nothing, and reports success.
 *
 * Non-destructive (old rows are kept) and idempotent (a value already present
 * under the new id is left alone).
 *
 * All OCP service calls use POSITIONAL arguments (named args are FATAL on
 * `occ upgrade`).
 *
 * @category  Repair
 * @package   OCA\Stackiq\Repair
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/rename-app-id-to-stackiq/specs/app-id-rename/spec.md#requirement-stored-user-preferences-survive-the-rename
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Repair;

use Closure;
use OCA\Stackiq\AppInfo\Application;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copies per-user preferences from the legacy `stackiq` app id to `stackiq`.
 *
 * @spec openspec/changes/rename-app-id-to-stackiq/specs/app-id-rename/spec.md#requirement-stored-user-preferences-survive-the-rename
 */
class MigrateUserPreferences implements IRepairStep {
	/**
	 * The app id every stored preference was written under before the rename.
	 */
	public const LEGACY_APP_ID = 'softwarecatalog';

	/**
	 * Number of preference values copied during the current run.
	 */
	private int $copied = 0;

	/**
	 * Number of users that carried at least one legacy preference.
	 */
	private int $users = 0;

	/**
	 * Constructor.
	 *
	 * @param IConfig $config The user config service.
	 * @param IUserManager $userManager The user manager.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly IConfig $config,
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Returns the name of this repair step.
	 *
	 * @return string The repair step name.
	 *
	 * @spec openspec/changes/rename-app-id-to-stackiq/specs/app-id-rename/spec.md#requirement-stored-user-preferences-survive-the-rename
	 */
	public function getName(): string {
		return 'Migrate user preferences from the stackiq app id to stackiq';
	}//end getName()

	/**
	 * Copy every legacy preference for every seen user.
	 *
	 * Both the READS and the WRITES sit inside the try. This step runs under
	 * `<install>` — the only hook that fires on the fresh install an app-id
	 * rename performs — so an escaping throw aborts the install and the app
	 * never enables at all.
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rename-app-id-to-stackiq/specs/app-id-rename/spec.md#requirement-stored-user-preferences-survive-the-rename
	 */
	public function run(IOutput $output): void {
		$this->copied = 0;
		$this->users = 0;

		try {
			$this->userManager->callForSeenUsers(
				Closure::fromCallable([$this, 'migrateUser'])
			);

			$output->info(
				sprintf(
					'Stackiq: migrated %d user preference value(s) for %d user(s) from "%s"',
					$this->copied,
					$this->users,
					self::LEGACY_APP_ID
				)
			);
		} catch (Throwable $e) {
			// Swallowed deliberately — see the docblock.
			$this->logger->error(
				'Stackiq: failed to migrate user preferences from the legacy app id: ' . $e->getMessage(),
				[
					'app' => Application::APP_ID,
					'exception' => $e,
				]
			);
			$output->warning('Stackiq: user preference migration failed; see the log. Users may see default view settings.');
		}//end try
	}//end run()

	/**
	 * Copy one user's legacy preferences into the new namespace.
	 *
	 * @param IUser $user The user to migrate.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rename-app-id-to-stackiq/specs/app-id-rename/spec.md#requirement-stored-user-preferences-survive-the-rename
	 */
	protected function migrateUser(IUser $user): void {
		$userId = $user->getUID();

		// Exhaustive per-user enumeration. getUserKeys() lists the keys this
		// user actually holds under the old app id — it needs no value to match,
		// which is exactly why getUsersForUserValue() is unusable here.
		$keys = $this->config->getUserKeys($userId, self::LEGACY_APP_ID);
		if ($keys === []) {
			return;
		}

		$touched = false;

		foreach ($keys as $key) {
			$legacy = $this->config->getUserValue($userId, self::LEGACY_APP_ID, $key, '');
			if ($legacy === '') {
				continue;
			}

			// Never clobber a value the new namespace already holds.
			$current = $this->config->getUserValue($userId, Application::APP_ID, $key, '');
			if ($current !== '') {
				continue;
			}

			$this->config->setUserValue($userId, Application::APP_ID, $key, $legacy);
			$this->copied++;
			$touched = true;
		}

		if ($touched === true) {
			$this->users++;
		}
	}//end migrateUser()
}//end class
