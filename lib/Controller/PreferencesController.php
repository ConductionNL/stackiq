<?php

/**
 * Stackiq PreferencesController.
 *
 * Generic per-user key/value preferences, backed by Nextcloud IConfig
 * user values. Used by shared @conduction/nextcloud-vue widgets (e.g.
 * CnSupportDialog's "seen" flag) that need to persist a small per-user
 * UI flag cross-device without a bespoke endpoint per feature.
 *
 * @category Controller
 * @package  OCA\Stackiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use OCA\Stackiq\AppInfo\AppIdentity;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Per-user preferences controller.
 */
class PreferencesController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IConfig $config The Nextcloud config (user values).
	 * @param IUserSession $userSession The user session.
	 */
	public function __construct(
		IRequest $request,
		private readonly IConfig $config,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Read a per-user preference value.
	 *
	 * @param string $key The preference key (kebab/alphanumeric).
	 *
	 * @return JSONResponse `{value: string|null}`.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @spec            openspec/specs/dashboard-views-api/spec.md
	 */
	public function getPreference(string $key): JSONResponse {
		$target = $this->preferenceTarget(key: $key);
		if ($target instanceof JSONResponse) {
			return $target;
		}

		return new JSONResponse(data: ['value' => $this->storedPreference(target: $target)]);
	}//end getPreference()

	/**
	 * Write a per-user preference value. An empty value clears it.
	 *
	 * @param string $key The preference key (kebab/alphanumeric).
	 * @param string $value The value to store (empty string clears it).
	 *
	 * @return JSONResponse `{value: string|null}`.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @spec            openspec/specs/dashboard-views-api/spec.md
	 */
	public function setPreference(string $key, string $value = ''): JSONResponse {
		$target = $this->preferenceTarget(key: $key);
		if ($target instanceof JSONResponse) {
			return $target;
		}

		if ($value === '') {
			$this->clearPreference(target: $target);
			return new JSONResponse(data: ['value' => null]);
		}

		$this->writePreference(target: $target, value: $value);

		return new JSONResponse(data: ['value' => $value]);
	}//end setPreference()

	/**
	 * Resolve where the preference lives, or the refusal: 401 when nobody is
	 * logged in, 400 when the key sanitises to nothing.
	 *
	 * @param string $key The raw preference key.
	 *
	 * @return PreferenceTarget|JSONResponse
	 */
	private function preferenceTarget(string $key): PreferenceTarget|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$safeKey = $this->sanitizeKey(key: $key);
		if ($safeKey === '') {
			return new JSONResponse(data: ['message' => 'Invalid key'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new PreferenceTarget(userId: $user->getUID(), configKey: 'pref_' . $safeKey);
	}//end preferenceTarget()

	/**
	 * The stored value of a preference, or null when none is stored.
	 *
	 * @param PreferenceTarget $target Where the preference lives.
	 *
	 * @return string|null
	 */
	private function storedPreference(PreferenceTarget $target): ?string {
		$value = $this->config->getUserValue(
			userId: $target->userId,
			appName: AppIdentity::APP_ID,
			key: $target->configKey,
			default: ''
		);

		if ($value === '') {
			return null;
		}

		return $value;
	}//end storedPreference()

	/**
	 * Delete a stored preference.
	 *
	 * @param PreferenceTarget $target Where the preference lives.
	 *
	 * @return void
	 */
	private function clearPreference(PreferenceTarget $target): void {
		$this->config->deleteUserValue(
			userId: $target->userId,
			appName: AppIdentity::APP_ID,
			key: $target->configKey
		);
	}//end clearPreference()

	/**
	 * Store a preference value.
	 *
	 * @param PreferenceTarget $target Where the preference lives.
	 * @param string           $value  The value to store.
	 *
	 * @return void
	 */
	private function writePreference(PreferenceTarget $target, string $value): void {
		$this->config->setUserValue(
			userId: $target->userId,
			appName: AppIdentity::APP_ID,
			key: $target->configKey,
			value: $value
		);
	}//end writePreference()

	/**
	 * Restrict keys to a safe charset so callers cannot reach arbitrary
	 * IConfig user values outside the `pref_` namespace.
	 *
	 * @param string $key The raw key.
	 *
	 * @return string The sanitised key, or '' when nothing safe remains.
	 */
	private function sanitizeKey(string $key): string {
		$safe = preg_replace(pattern: '/[^a-z0-9-]/', replacement: '', subject: strtolower($key));
		return substr((string)$safe, offset: 0, length: 64);
	}//end sanitizeKey()
}//end class
