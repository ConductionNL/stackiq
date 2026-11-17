<?php

/**
 * UsageClassificationController
 *
 * The GEMMA suggestion for a usage's classification and the anonymous summary
 * of how organisations classify an application
 * (security-baseline-classification REQ-BCL-003, REQ-BCL-004).
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-004-municipalities-shall-see-how-organisations-classify-an-application-without-names-and-not-below-three
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use OCA\Stackiq\Service\ClassificationSummaryAccess;
use OCA\Stackiq\Service\UsageClassificationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Two read-only routes: the suggestion for a usage and the summary for an application.
 *
 * The suggestion reads the usage with the caller's own rights, so a usage the
 * caller may not read is not found. The summary answers Nextcloud admins,
 * members of `ambtenaar`, and users whose active organisation is a
 * municipality or a collaboration; everyone else gets 403 before anything is read.
 *
 * @spec openspec/changes/security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-004-municipalities-shall-see-how-organisations-classify-an-application-without-names-and-not-below-three
 */
class UsageClassificationController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                     $appName        The app name.
	 * @param IRequest                   $request        The request.
	 * @param UsageClassificationService  $classification The suggestion and the summary.
	 * @param ClassificationSummaryAccess $access         The caller, and whether they may read the summary.
	 * @param LoggerInterface             $logger         Failed reads.
	 *
	 * @spec openspec/changes/security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-004-municipalities-shall-see-how-organisations-classify-an-application-without-names-and-not-below-three
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly UsageClassificationService $classification,
		private readonly ClassificationSummaryAccess $access,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * `GET /api/gebruik/{usageId}/classification-suggestion`: the levels GEMMA suggests; saves nothing.
	 *
	 * @param string $usageId The usage.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-003-stackiq-shall-suggest-the-levels-from-gemmas-reference-components
	 */
	#[NoAdminRequired]
	public function suggestion(string $usageId): JSONResponse {
		if ($this->access->callerId() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$suggestion = $this->classification->suggestion(usageId: $usageId);
		} catch (Throwable $e) {
			$this->logger->error('Classification suggestion failed', ['error' => $e->getMessage()]);
			return new JSONResponse(['message' => 'The suggestion could not be made'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($suggestion === null) {
			return new JSONResponse(['message' => 'Usage not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($suggestion);
	}//end suggestion()

	/**
	 * `GET /api/modules/{moduleId}/classification-summary`: organisations per level, without names, not below three.
	 *
	 * @param string $moduleId The application.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-004-municipalities-shall-see-how-organisations-classify-an-application-without-names-and-not-below-three
	 */
	#[NoAdminRequired]
	public function summary(string $moduleId): JSONResponse {
		$userId = $this->access->callerId();
		if ($userId === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			if ($this->access->mayReadSummary(userId: $userId) === false) {
				return new JSONResponse(['message' => 'Only municipalities and collaborations see how others classify'], Http::STATUS_FORBIDDEN);
			}

			return new JSONResponse($this->classification->summary(moduleId: $moduleId));
		} catch (Throwable $e) {
			$this->logger->error('Classification summary failed', ['error' => $e->getMessage()]);
			return new JSONResponse(['message' => 'The summary could not be made'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end summary()
}//end class
