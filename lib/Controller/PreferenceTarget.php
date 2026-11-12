<?php

/**
 * Where one per-user preference is stored.
 *
 * @category Controller
 * @package  OCA\Stackiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/dashboard-views-api/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

/**
 * The user and the namespaced IConfig key of one preference, resolved once
 * the caller is known to be logged in and the key is known to be safe.
 *
 * @spec openspec/specs/dashboard-views-api/spec.md
 */
final class PreferenceTarget {
	/**
	 * Constructor.
	 *
	 * @param string $userId    The logged-in user's id.
	 * @param string $configKey The IConfig user-value key (`pref_` + the sanitised key).
	 */
	public function __construct(
		public readonly string $userId,
		public readonly string $configKey,
	) {
	}//end __construct()
}//end class
