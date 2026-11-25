<?php

/**
 * Stackiq's health answer while OpenRegister is not available.
 *
 * @category Controller
 * @package  OCA\Stackiq\Controller
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/adopt-apphost/tasks.md#3-verification
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use Throwable;

/**
 * Answers `/api/health` with 503 when OpenRegister is disabled or missing.
 *
 * The health route is served by OpenRegister's AppHost health controller. With
 * OpenRegister gone that class cannot load, and the probe got a 500 error page
 * instead of the ADR-006 answer. The manifest marks the OpenRegister check
 * critical, so the answer the generic would give is `error` with 503; this
 * controller gives that answer, in the same shape, without OpenRegister.
 */
class OpenRegisterUnavailableHealthController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string        $appName    The app id
	 * @param IRequest      $request    The request
	 * @param IAppManager   $appManager Reads the installed version
	 * @param IDBConnection $db         Checked with a trivial query
	 *
	 * @spec openspec/changes/adopt-apphost/tasks.md#3-verification
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IAppManager $appManager,
		private readonly IDBConnection $db,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The ADR-006 health body with the OpenRegister check failed, as 503.
	 *
	 * @return JSONResponse `{status: error, app, version, checks: {database, openregister}}`
	 *
	 * @spec openspec/changes/adopt-apphost/tasks.md#3-verification
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function index(): JSONResponse {
		return new JSONResponse(
			[
				'status'  => 'error',
				'app'     => $this->appName,
				'version' => $this->appManager->getAppVersion($this->appName),
				'checks'  => [
					'database'     => $this->databaseCheck(),
					'openregister' => 'failed: openregister unavailable',
				],
			],
			Http::STATUS_SERVICE_UNAVAILABLE
		);
	}//end index()

	/**
	 * The database check, worded as the AppHost executor words it.
	 *
	 * @return string `ok` or `failed`
	 *
	 * @spec openspec/changes/adopt-apphost/tasks.md#3-verification
	 */
	private function databaseCheck(): string {
		try {
			$this->db->executeQuery('SELECT 1')->closeCursor();
		} catch (Throwable) {
			return 'failed';
		}

		return 'ok';
	}//end databaseCheck()
}//end class
