<?php

/**
 * The views API lists the imported GEMMA views and returns one as imported.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Controller
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/view-enrichment-api/spec.md#requirement-the-views-api-lists-the-imported-gemma-views-and-returns-each-as-imported-req-clr-021
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\ViewController;
use OCA\Stackiq\Service\ViewService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers ViewController's list and plain read (REQ-CLR-021).
 *
 * @covers \OCA\Stackiq\Controller\ViewController
 */
final class ViewControllerTest extends TestCase {

	/**
	 * A controller for a signed-in client sending no query parameters.
	 *
	 * @param ViewService $views The view service.
	 *
	 * @return ViewController
	 */
	private function controller(ViewService $views): ViewController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default=null) => $default);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));

		return new ViewController('stackiq', $request, $views, $this->createMock(LoggerInterface::class), $session);
	}//end controller()

	public function testASignedInClientGetsTheImportedViewsWithTheirIdAndName(): void {
		$views = $this->createMock(ViewService::class);
		$views->expects($this->once())->method('getAllViews')->willReturn([
			'success' => true,
			'views' => [['id' => 'id-gemma-1', 'name' => 'GEMMA Gemeentelijke processen'], ['id' => 'id-gemma-2', 'name' => 'GEMMA Referentiecomponenten']],
			'count' => 2,
		]);

		$response = $this->controller(views: $views)->getAllViews();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			[['id' => 'id-gemma-1', 'name' => 'GEMMA Gemeentelijke processen'], ['id' => 'id-gemma-2', 'name' => 'GEMMA Referentiecomponenten']],
			array_map(static fn (array $view): array => ['id' => $view['id'], 'name' => $view['name']], $response->getData()['views'])
		);
	}

	public function testAViewWithoutEnrichmentParametersIsAskedWithNoneAndReturnedAsImported(): void {
		$imported = [
			'success' => true,
			'view' => [
				'id' => 'id-gemma-2',
				'name' => 'GEMMA Referentiecomponenten',
				'nodes' => [['identifier' => 'n1', 'elementRef' => 'e1'], ['identifier' => 'n2', 'elementRef' => 'e2']],
				'relationships' => [['identifier' => 'r1', 'source' => 'n1', 'target' => 'n2']],
			],
			'enrichments_applied' => [],
		];
		$views = $this->createMock(ViewService::class);
		$views->expects($this->once())->method('getView')->with('id-gemma-2', [])->willReturn($imported);

		$response = $this->controller(views: $views)->getView(viewId: 'id-gemma-2');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($imported, $response->getData());
	}
}
