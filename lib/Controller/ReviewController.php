<?php
/**
 * Review (beoordeeling) submission + approved-only read/aggregate controller.
 *
 * `submit` requires an authenticated Nextcloud session (`#[NoAdminRequired]`,
 * normal CSRF protection — unlike `IntakeController`, this is never
 * anonymous, so no `#[PublicPage]`/`#[NoCSRFRequired]`/rate-limit). The
 * author identity is always taken from the session inside `ReviewService`,
 * never from the request body.
 *
 * `aggregate` is `#[PublicPage]` (read-only, approved-only reviews are
 * intentionally public per the fail-closed schema RBAC) so it works on an
 * anonymous module/dienst detail page view, mirroring `FacetController`.
 *
 * @category  Controller
 * @package   OCA\SoftwareCatalog\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/catalog-ratings/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Controller;

use OCA\SoftwareCatalog\AppInfo\Application;
use OCA\SoftwareCatalog\Service\ReviewAggregateService;
use OCA\SoftwareCatalog\Service\ReviewService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Authenticated review submission + public approved-only aggregate.
 *
 * @spec openspec/specs/catalog-ratings/spec.md
 */
class ReviewController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest               $request   The request.
     * @param ReviewService          $reviews   The review submission service.
     * @param ReviewAggregateService $aggregate The review aggregate/read service.
     */
    public function __construct(
        IRequest $request,
        private readonly ReviewService $reviews,
        private readonly ReviewAggregateService $aggregate,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Submit an authenticated review into the moderation queue.
     *
     * @param array<string,mixed> $review      The review payload (naam, waardering, beschrijvingKort/Lang).
     * @param string              $subjectType 'module' or 'dienst'.
     * @param string              $subjectId   The uuid of the module/dienst being reviewed.
     *
     * @return JSONResponse `{ok, uuid, status}` (202 Accepted) or a 400/401.
     *
     * @NoAdminRequired
     * @spec            openspec/specs/catalog-ratings/spec.md#requirement-the-submitting-users-identity-must-be-bound-server-side-and-must-not-be-accepted-from-client-input
     */
    #[NoAdminRequired]
    public function submit(array $review=[], string $subjectType='', string $subjectId=''): JSONResponse
    {
        $result = $this->reviews->submit(payload: $review, subjectType: $subjectType, subjectId: $subjectId);
        if ($result['ok'] === false) {
            $statusCode = Http::STATUS_BAD_REQUEST;
            if ($result['reason'] === 'not authenticated') {
                $statusCode = Http::STATUS_UNAUTHORIZED;
            }

            return new JSONResponse(data: ['message' => $result['reason']], statusCode: $statusCode);
        }

        return new JSONResponse(
            data: [
                'ok'      => true,
                'uuid'    => $result['uuid'],
                'status'  => $result['status'],
                'message' => 'Review received and queued for moderation',
            ],
            statusCode: Http::STATUS_ACCEPTED
        );
    }//end submit()

    /**
     * The approved-only aggregate (average + count) and a bounded list of
     * approved reviews for a module or dienst.
     *
     * @param string $subjectType 'module' or 'dienst'.
     * @param string $subjectId   The uuid of the module/dienst.
     *
     * @return JSONResponse `{average, count, items}` or a 400.
     *
     * @PublicPage
     * @NoCSRFRequired
     * @spec           openspec/specs/catalog-ratings/spec.md#requirement-module-and-dienst-detail-pages-must-display-an-aggregate-rating-computed-only-from-approved-reviews
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function aggregate(string $subjectType='', string $subjectId=''): JSONResponse
    {
        $result = $this->aggregate->getAggregate(subjectType: $subjectType, subjectId: $subjectId);
        if ($result['ok'] === false) {
            return new JSONResponse(data: ['message' => $result['reason']], statusCode: Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse(
            data: [
                'average' => $result['average'],
                'count'   => $result['count'],
                'items'   => $result['items'],
            ]
        );
    }//end aggregate()
}//end class
