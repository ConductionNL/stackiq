<?php

/**
 * Regression tests for the HTTP status code AangebodenGebruikController
 * returns when the service layer reports an error.
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/vendor-visibility-rbac/spec.md#requirement-the-offered-usage-afnemer-endpoint-must-require-authentication-explicitly-not-implicitly-req-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Controller;

use OCA\SoftwareCatalog\Controller\AangebodenGebruikController;
use OCA\SoftwareCatalog\Service\AangebodenGebruikService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * THE DEFECT UNDER TEST.
 *
 * Both endpoints below documented their intent in a comment — "Determine
 * HTTP status code based on whether there's an error" — and then shipped
 *
 *     $statusCode = 200;
 *     if (isset($result['error']) === true) {
 *     }
 *
 * an EMPTY if body. Commit 651a055f ("refactor: Replace else clauses with
 * early returns") rewrote `if (err) { $s = 500; } else { $s = 200; }` by
 * hoisting the else-body out and deleting the if-body along with the
 * `else` keyword. The condition survived; the only statement it guarded
 * did not.
 *
 * The consequence is not cosmetic: a service-level failure was returned to
 * the caller as **HTTP 200** with an `error` key in the body. Every client
 * that branches on `response.ok` — which is what this app's own Pinia
 * stores do — read a failed request as a successful one with zero results.
 * A "no results" screen and a "the backend blew up" screen became
 * indistinguishable over the wire.
 *
 * These tests assert the STATUS CODE, not the envelope, because the
 * envelope was always right and is exactly what made the defect invisible.
 */
final class AangebodenGebruikControllerStatusCodeTest extends TestCase
{

    /**
     * The service double the controller under test delegates to.
     *
     * @var AangebodenGebruikService|MockObject
     */
    private AangebodenGebruikService|MockObject $gebruikSvc;

    /**
     * The session double, always populated with an authenticated user so
     * the controller's own auth guard is not what these tests measure.
     *
     * @var IUserSession|MockObject
     */
    private IUserSession|MockObject $userSession;


    /**
     * Build the controller with an authenticated caller in session.
     *
     * @return AangebodenGebruikController The controller under test.
     */
    private function makeController(): AangebodenGebruikController
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParams')->willReturn([]);
        $request->method('getParam')->willReturn(null);

        $this->gebruikSvc  = $this->createMock(AangebodenGebruikService::class);
        $this->userSession = $this->createMock(IUserSession::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('caller-uid');
        $this->userSession->method('getUser')->willReturn($user);

        return new AangebodenGebruikController(
            'softwarecatalog',
            $request,
            $this->userSession,
            $this->gebruikSvc,
            $this->createMock(LoggerInterface::class),
            $this->createMock(IGroupManager::class)
        );

    }//end makeController()


    /**
     * The error envelope produced by the service layer on failure.
     *
     * @param string|null $error The error message, or null for the success shape.
     *
     * @return array The service return value.
     */
    private function envelope(?string $error): array
    {
        $envelope = [
            'results' => [],
            'total'   => 0,
            'page'    => 1,
            'pages'   => 0,
            'limit'   => 20,
            'offset'  => 0,
        ];

        if ($error !== null) {
            $envelope['error'] = $error;
        }

        return $envelope;

    }//end envelope()


    /**
     * A service-reported error on the afnemer listing MUST surface as 500,
     * not as a 200 carrying an `error` key.
     *
     * @return void
     */
    public function testAfnemerListingReturns500WhenTheServiceReportsAnError(): void
    {
        $controller = $this->makeController();

        $this->gebruikSvc->method('getGebruiksWhereAfnemer')
            ->willReturn($this->envelope('Voorzieningen configuration not found'));

        $response = $controller->getGebruiksWhereAfnemer();

        $this->assertSame(
            500,
            $response->getStatus(),
            'A service error must be reported as HTTP 500. Returning 200 makes a '
            .'backend failure indistinguishable from an empty result set for every '
            .'client that branches on response.ok.'
        );

    }//end testAfnemerListingReturns500WhenTheServiceReportsAnError()


    /**
     * The success path must stay 200 — the fix must not turn every
     * response into a 500. Without this arm the test above would also pass
     * against a hardcoded `$statusCode = 500`.
     *
     * @return void
     */
    public function testAfnemerListingReturns200OnSuccess(): void
    {
        $controller = $this->makeController();

        $this->gebruikSvc->method('getGebruiksWhereAfnemer')
            ->willReturn($this->envelope(null));

        $response = $controller->getGebruiksWhereAfnemer();

        $this->assertSame(200, $response->getStatus());

    }//end testAfnemerListingReturns200OnSuccess()


    /**
     * Same defect, second site: the koppelingen-by-UUID endpoint.
     *
     * @return void
     */
    public function testKoppelingenByUuidReturns500WhenTheServiceReportsAnError(): void
    {
        $controller = $this->makeController();

        $this->gebruikSvc->method('getKoppelingenGebruikByUuid')
            ->willReturn($this->envelope('Voorzieningen configuration not found'));

        $response = $controller->getKoppelingenGebruikByUuid('some-uuid');

        $this->assertSame(
            500,
            $response->getStatus(),
            'A service error must be reported as HTTP 500 on the koppelingen-by-UUID endpoint too.'
        );

    }//end testKoppelingenByUuidReturns500WhenTheServiceReportsAnError()


    /**
     * And its success arm.
     *
     * @return void
     */
    public function testKoppelingenByUuidReturns200OnSuccess(): void
    {
        $controller = $this->makeController();

        $this->gebruikSvc->method('getKoppelingenGebruikByUuid')
            ->willReturn($this->envelope(null));

        $response = $controller->getKoppelingenGebruikByUuid('some-uuid');

        $this->assertSame(200, $response->getStatus());

    }//end testKoppelingenByUuidReturns200OnSuccess()


}//end class
