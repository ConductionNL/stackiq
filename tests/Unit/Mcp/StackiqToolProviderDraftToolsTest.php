<?php

/**
 * The two draft tools on the stackiq MCP provider.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Mcp
 *
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/architecture-assistant-drafted-views/specs/architecture-assistant-views/spec.md#requirement-req-aav-001-stackiq-shall-offer-a-read-tool-that-finds-architecture-elements-for-a-draft
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Mcp;

use InvalidArgumentException;
use OCA\Stackiq\Exception\DraftRefusedException;
use OCA\Stackiq\Mcp\McpAdminGate;
use OCA\Stackiq\Mcp\McpArgumentValidator;
use OCA\Stackiq\Mcp\McpContractTools;
use OCA\Stackiq\Mcp\McpDraftTools;
use OCA\Stackiq\Mcp\McpReadTools;
use OCA\Stackiq\Mcp\McpWriteTools;
use OCA\Stackiq\Mcp\StackiqToolProvider;
use OCA\Stackiq\Service\ArchitectureViewDraftService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The draft tools declare their grant and hand their arguments to the service unchanged.
 */
final class StackiqToolProviderDraftToolsTest extends TestCase {

	/** @var ArchitectureViewDraftService&MockObject */
	private ArchitectureViewDraftService $drafts;

	/**
	 * The two descriptors carry the scope, reach and hints of design D1.
	 *
	 * @return void
	 */
	public function testTheDescriptorsCarryTheirGrant(): void {
		$tools = array_column(StackiqToolProvider::TOOL_DESCRIPTORS, null, 'id');

		$search = $tools['stackiq.searchArchitectureElements'];
		$this->assertSame(expected: ['read', 'user'], actual: [$search['scope'], $search['reach']]);
		$this->assertTrue(condition: $search['annotations']['readOnlyHint']);
		$this->assertSame(expected: ['query'], actual: $search['inputSchema']['required']);

		$draft = $tools['stackiq.draftView'];
		$this->assertSame(expected: ['create', 'instance'], actual: [$draft['scope'], $draft['reach']]);
		$this->assertSame(expected: ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false], actual: $draft['annotations']);
		$this->assertSame(expected: ['name', 'elements'], actual: $draft['inputSchema']['required']);
		$this->assertSame(expected: 60, actual: $draft['inputSchema']['properties']['elements']['maxItems']);
		$this->assertSame(expected: 120, actual: $draft['inputSchema']['properties']['relations']['maxItems']);
		$relationTypes = $draft['inputSchema']['properties']['relations']['items']['properties']['type']['enum'];
		$this->assertSame(expected: ArchitectureViewDraftService::RELATION_TYPES, actual: $relationTypes);
	}//end testTheDescriptorsCarryTheirGrant()

	/**
	 * A valid call reaches the service with its arguments unchanged.
	 *
	 * @return void
	 */
	public function testValidCallsReachTheServiceUnchanged(): void {
		$elements = [
			['key' => 'a', 'type' => 'ApplicationComponent', 'name' => 'Zaaksysteem'],
			['key' => 'b', 'uuid' => '11111111-1111-4111-8111-111111111111'],
		];
		$relations = [['source' => 'a', 'target' => 'b', 'type' => 'Flow']];
		$answer = ['uuid' => 'v1', 'url' => '/apps/stackiq/views/v1', 'created' => ['elements' => 1, 'relations' => 1]];

		$provider = $this->provider();
		$this->drafts->expects($this->once())->method('draftView')
			->with('Concept', 'Why', $elements, $relations)
			->willReturn($answer);
		$this->drafts->expects($this->once())->method('searchElements')
			->with('zaak', ['ApplicationComponent'], 7)
			->willReturn([['uuid' => 'u1']]);

		$drafted = $provider->invokeTool('stackiq.draftView', ['name' => 'Concept', 'description' => 'Why', 'elements' => $elements, 'relations' => $relations]);
		$this->assertSame(expected: $answer, actual: $drafted);
		$found = $provider->invokeTool('stackiq.searchArchitectureElements', ['query' => 'zaak', 'types' => ['ApplicationComponent'], 'limit' => 7]);
		$this->assertSame(expected: ['elements' => [['uuid' => 'u1']]], actual: $found);
	}//end testValidCallsReachTheServiceUnchanged()

	/**
	 * Malformed arguments are refused before the service is called.
	 *
	 * @return void
	 */
	public function testMalformedArgumentsNeverReachTheService(): void {
		$provider = $this->provider();
		$this->drafts->expects($this->never())->method('draftView');
		$this->drafts->expects($this->never())->method('searchElements');

		foreach ([
			['stackiq.draftView', ['elements' => [['key' => 'a']]]],
			['stackiq.draftView', ['name' => 'C', 'elements' => []]],
			['stackiq.draftView', ['name' => 'C', 'elements' => ['a' => ['key' => 'a']]]],
			['stackiq.draftView', ['name' => 'C', 'elements' => [['key' => 'a']], 'relations' => 'none']],
			['stackiq.searchArchitectureElements', []],
			['stackiq.searchArchitectureElements', ['query' => 'x', 'limit' => 51]],
			['stackiq.searchArchitectureElements', ['query' => 'x', 'types' => [7]]],
		] as [$tool, $args]) {
			$this->assertSame(expected: 'validation_error', actual: $provider->invokeTool($tool, $args)['error'], message: json_encode(value: $args));
		}
	}//end testMalformedArgumentsNeverReachTheService()

	/**
	 * Without a signed-in user neither tool runs.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallIsRefused(): void {
		$provider = $this->provider(signedIn: false);
		$this->drafts->expects($this->never())->method('draftView');

		$refused = $provider->invokeTool('stackiq.draftView', ['name' => 'C', 'elements' => [['key' => 'a']]]);
		$this->assertSame(expected: 'unauthenticated', actual: $refused['error']);
		$this->assertSame(expected: 'unauthenticated', actual: $provider->invokeTool('stackiq.searchArchitectureElements', ['query' => 'x'])['error']);
	}//end testAnAnonymousCallIsRefused()

	/**
	 * The service's refusals come back as the structured errors every stackiq tool answers.
	 *
	 * @return void
	 */
	public function testServiceRefusalsAreStructuredErrors(): void {
		$args = ['name' => 'C', 'elements' => [['key' => 'a']]];
		foreach ([
			'validation_error' => new InvalidArgumentException("Element 'a' needs a name."),
			'forbidden' => new DraftRefusedException('You may not create a view here.'),
			'unavailable' => new RuntimeException('OpenRegister is not available.'),
		] as $code => $error) {
			$provider = $this->provider();
			$this->drafts->method('draftView')->willThrowException($error);

			$expected = McpArgumentValidator::error(code: $code, message: $error->getMessage());
			$this->assertSame(expected: $expected, actual: $provider->invokeTool('stackiq.draftView', $args), message: $code);
		}
	}//end testServiceRefusalsAreStructuredErrors()

	/**
	 * The provider with real draft tools over a service double.
	 *
	 * @param bool $signedIn Whether a user is signed in.
	 *
	 * @return StackiqToolProvider
	 */
	private function provider(bool $signedIn = true): StackiqToolProvider {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(originalClassName: IUser::class);
		}

		$session->method('getUser')->willReturn($user);
		$this->drafts = $this->createMock(originalClassName: ArchitectureViewDraftService::class);

		return new StackiqToolProvider(
			$this->createMock(originalClassName: McpContractTools::class),
			$this->createMock(originalClassName: McpReadTools::class),
			$this->createMock(originalClassName: McpWriteTools::class),
			new McpDraftTools(new McpAdminGate($session, $this->createMock(originalClassName: IGroupManager::class)), new McpArgumentValidator(), $this->drafts)
		);
	}//end provider()
}//end class
