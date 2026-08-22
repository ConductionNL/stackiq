<?php

/**
 * Repair step that carries `oc_appconfig` rows across the app-id rename.
 *
 * Nextcloud namespaces `oc_appconfig` BY APP ID and offers no in-place app-id
 * upgrade, so the moment `softwarecatalog` became `stackiq` every row this app
 * had ever written became unreachable. Nothing errors: every reader in this
 * codebase supplies a default, so an operator's federation URL, sync interval
 * and group configuration simply revert to their defaults and the instance
 * looks freshly installed.
 *
 * This step copies them. It is non-destructive (the old rows are never
 * deleted, so a rollback still finds its data) and idempotent (a key already
 * present under the new id is left alone, so re-running never clobbers a value
 * an admin has since changed).
 *
 * It is declared FIRST in both `<install>` and `<post-migration>` and MUST stay
 * there. `InitializeSettings` writes app config itself; if it ran first, every
 * key it touched would look "already present" here and the operator's real
 * value would stay stranded in the old namespace forever.
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
 * @spec openspec/changes/rename-app-id-to-stackiq/specs/app-id-rename/spec.md#requirement-stored-app-config-survives-the-rename
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Repair;

use OCA\Stackiq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copies app config from the legacy `softwarecatalog` app id to `stackiq`.
 *
 * @spec openspec/changes/rename-app-id-to-stackiq/specs/app-id-rename/spec.md#requirement-stored-app-config-survives-the-rename
 */
class MigrateAppConfigKeys implements IRepairStep {
	/**
	 * The app id every stored row was written under before the rename.
	 */
	public const LEGACY_APP_ID = 'softwarecatalog';

	/**
	 * Keys Nextcloud owns in every app's namespace. These MUST NOT be copied.
	 *
	 * `enabled` is the dangerous one. `AppManager::enableApp()` writes it as
	 * type MIXED; copying it with `setValueString()` stores it as STRING, and
	 * the next `occ app:enable` then fails permanently with
	 * `AppConfigTypeConflictException` — a conflict that is hit BEFORE the app
	 * can run anything that would repair it. `installed_version` and `types`
	 * are Nextcloud's own bookkeeping for the new id and are already correct.
	 */
	private const RESERVED_KEYS = [
		'enabled',
		'installed_version',
		'types',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The typed app config service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Returns the name of this repair step.
	 *
	 * @return string The repair step name.
	 *
	 * @spec openspec/changes/rename-app-id-to-stackiq/specs/app-id-rename/spec.md#requirement-stored-app-config-survives-the-rename
	 */
	public function getName(): string {
		return 'Migrate app config from the softwarecatalog app id to stackiq';
	}//end getName()

	/**
	 * Copy every non-reserved app config key from the legacy app id.
	 *
	 * Both the READS and the WRITE sit inside the try. This step runs under
	 * `<install>` — the only hook that fires on the fresh install an app-id
	 * rename performs — so an escaping throw aborts the install and the app
	 * never enables at all. A config key that fails to copy is a reverted
	 * setting; a failed install is no app.
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rename-app-id-to-stackiq/specs/app-id-rename/spec.md#requirement-stored-app-config-survives-the-rename
	 */
	public function run(IOutput $output): void {
		try {
			// Exhaustive enumeration. getKeys() lists every key stored under the
			// old app id regardless of value — the alternative shapes
			// (searchValues, getUsersForUserValue) all match on a VALUE, so over
			// an open value set they migrate nothing and report success.
			$keys = $this->appConfig->getKeys(self::LEGACY_APP_ID);

			// Values come from getAllValues() rather than the typed getters
			// because it returns each row in its STORED type without asserting
			// one. getValueString() on a key stored as MIXED or INT raises
			// AppConfigTypeConflictException, which would abort the whole copy
			// on the first typed key it met.
			$values = $this->appConfig->getAllValues(self::LEGACY_APP_ID);

			$copied = 0;
			$skipped = 0;

			foreach ($keys as $key) {
				if (in_array($key, self::RESERVED_KEYS, true) === true) {
					$skipped++;
					continue;
				}

				// Never clobber a value the new namespace already holds — either
				// a previous run copied it, or an admin has since changed it.
				if ($this->appConfig->hasKey(Application::APP_ID, $key) === true) {
					$skipped++;
					continue;
				}

				if (array_key_exists($key, $values) === false) {
					$skipped++;
					continue;
				}

				if ($this->copyValue($key, $values[$key]) === true) {
					$copied++;
				} else {
					$skipped++;
				}
			}

			$output->info(
				sprintf(
					'Stackiq: migrated %d app config key(s) from "%s" (skipped %d)',
					$copied,
					self::LEGACY_APP_ID,
					$skipped
				)
			);
		} catch (Throwable $e) {
			// Swallowed deliberately — see the docblock. Logged at error level so
			// the reverted-settings symptom has a cause to find.
			$this->logger->error(
				'Stackiq: failed to migrate app config from the legacy app id: ' . $e->getMessage(),
				[
					'app' => Application::APP_ID,
					'exception' => $e,
				]
			);
			$output->warning('Stackiq: app config migration failed; see the log. Settings may have reverted to defaults.');
		}//end try
	}//end run()

	/**
	 * Write one legacy value into the new namespace in its own type.
	 *
	 * Empty strings and empty arrays are treated as "nothing stored" and are
	 * not copied — an empty value is indistinguishable from the default every
	 * reader already supplies, so copying it adds a row and changes nothing.
	 * Scalars are copied as-is: `false` and `0` are real, chosen values.
	 *
	 * @param string $key The config key.
	 * @param mixed $value The value as stored under the legacy app id.
	 *
	 * @return bool True when a value was written.
	 *
	 * @spec openspec/changes/rename-app-id-to-stackiq/specs/app-id-rename/spec.md#requirement-stored-app-config-survives-the-rename
	 */
	protected function copyValue(string $key, mixed $value): bool {
		if (is_bool($value) === true) {
			$this->appConfig->setValueBool(Application::APP_ID, $key, $value);
			return true;
		}

		if (is_int($value) === true) {
			$this->appConfig->setValueInt(Application::APP_ID, $key, $value);
			return true;
		}

		if (is_float($value) === true) {
			$this->appConfig->setValueFloat(Application::APP_ID, $key, $value);
			return true;
		}

		if (is_array($value) === true) {
			if ($value === []) {
				return false;
			}

			$this->appConfig->setValueArray(Application::APP_ID, $key, $value);
			return true;
		}

		$string = (string)$value;
		if ($string === '') {
			return false;
		}

		$this->appConfig->setValueString(Application::APP_ID, $key, $string);
		return true;
	}//end copyValue()
}//end class
