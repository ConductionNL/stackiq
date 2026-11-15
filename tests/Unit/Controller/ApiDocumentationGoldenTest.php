<?php

/**
 * Golden test for the static API documentation endpoints.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/aangeboden-gebruik-api/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\AangebodenGebruikController;
use OCA\Stackiq\Controller\ViewController;
use OCA\Stackiq\Service\AangebodenGebruikService;
use OCA\Stackiq\Service\ViewService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the documentation bodies of aangebodenGebruik#getApiDocumentation and
 * view#getApiDocumentation.
 *
 * @spec openspec/specs/aangeboden-gebruik-api/spec.md
 */
class ApiDocumentationGoldenTest extends TestCase {
	/**
	 * Both documentation responses: status and body.
	 *
	 * @return void
	 */
	public function testDocumentationBodies(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));

		$gebruik = new AangebodenGebruikController(
			'stackiq',
			$this->createMock(IRequest::class),
			$session,
			$this->createMock(AangebodenGebruikService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IGroupManager::class)
		);
		$view    = new ViewController(
			'stackiq',
			$this->createMock(IRequest::class),
			$this->createMock(ViewService::class),
			$this->createMock(LoggerInterface::class),
			$session
		);

		$out = [];
		foreach (['aangeboden-gebruik' => $gebruik, 'views' => $view] as $name => $controller) {
			$response   = $controller->getApiDocumentation();
			$out[$name] = [$response->getStatus(), $response->getData()];
		}

		$path = dirname(__DIR__, 2) . '/fixtures/api-documentation/documentation.json';
		$this->assertSame(json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR), $out);
	}
}
