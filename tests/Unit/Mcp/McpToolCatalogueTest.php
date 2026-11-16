<?php

/**
 * Unit tests for the StackIQ MCP tool catalogue.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/mcp-full-action-surface/tasks.md#6.1
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Mcp;

use OCA\Stackiq\Mcp\McpContractTools;
use OCA\Stackiq\Mcp\McpReadTools;
use OCA\Stackiq\Mcp\McpWriteTools;
use OCA\Stackiq\Mcp\StackiqToolProvider;
use PHPUnit\Framework\TestCase;

/**
 * The descriptor table is the grant matrix of design.md §5, and every
 * descriptor dispatches to its own handler.
 *
 * @covers \OCA\Stackiq\Mcp\StackiqToolProvider
 * @uses   \OCA\Stackiq\Mcp\McpArgumentValidator
 */
class McpToolCatalogueTest extends TestCase {

	/**
	 * The curated read tools.
	 *
	 * @var list<string>
	 */
	private const READS = [
		'getContractApprovalConfig',
		'getMyContactProfile',
		'listOffers',
		'listOfferedUsages',
		'getPortfolioReport',
		'listPendingModerations',
		'getReviewAggregate',
		'getSbomImportStatus',
		'listViews',
		'getView',
		'previewOrganisationMerge',
		'getEolSyncStatus',
	];

	/**
	 * The curated write tools with their scope and reach (design.md §5).
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const WRITES = [
		'submitContractApproval' => ['update', 'instance'],
		'submitContractRenewal'  => ['update', 'instance'],
		'publishObject'          => ['update', 'external'],
		'depublishObject'        => ['update', 'external'],
		'approveRegistration'    => ['update', 'instance'],
		'rejectRegistration'     => ['update', 'instance'],
		'submitReview'           => ['create', 'instance'],
		'registerOrganisation'   => ['create', 'instance'],
		'acceptOffer'            => ['update', 'instance'],
		'declineOffer'           => ['delete', 'instance'],
		'claimUsage'             => ['update', 'instance'],
		'declineUsage'           => ['delete', 'instance'],
		'mergeOrganisations'     => ['update', 'instance'],
		'importSbom'             => ['create', 'instance'],
		'triggerEolSync'         => ['update', 'external'],
	];

	/**
	 * The write tools whose effect removes or tombstones a record.
	 *
	 * @var list<string>
	 */
	private const DESTRUCTIVE = ['declineOffer', 'declineUsage', 'mergeOrganisations'];

	/**
	 * The descriptors by short tool name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function byName(): array {
		$tools = [];
		foreach (StackiqToolProvider::TOOL_DESCRIPTORS as $tool) {
			$tools[substr($tool['id'], strlen('stackiq.'))] = $tool;
		}

		return $tools;
	}//end byName()

	/**
	 * The catalogue holds exactly the curated reads and writes.
	 *
	 * @return void
	 */
	public function testTheCatalogueIsTheGrantMatrix(): void {
		$tools = self::byName();
		$this->assertCount(count(StackiqToolProvider::TOOL_DESCRIPTORS), $tools, 'ids are unique');

		$expected = array_merge(self::READS, array_keys(self::WRITES));
		sort($expected);
		$names = array_keys($tools);
		sort($names);
		$this->assertSame($expected, $names);

		foreach (self::READS as $name) {
			$this->assertSame('read', $tools[$name]['scope'], $name);
			$this->assertSame('user', $tools[$name]['reach'], $name);
			$this->assertTrue($tools[$name]['annotations']['readOnlyHint'], $name);
		}

		foreach (self::WRITES as $name => [$scope, $reach]) {
			$this->assertSame($scope, $tools[$name]['scope'], $name);
			$this->assertSame($reach, $tools[$name]['reach'], $name);
			$this->assertFalse($tools[$name]['annotations']['readOnlyHint'], $name);
			$this->assertSame(in_array($name, self::DESTRUCTIVE, true), $tools[$name]['annotations']['destructiveHint'], $name);
		}
	}//end testTheCatalogueIsTheGrantMatrix()

	/**
	 * Every descriptor has a name, a description and an object input schema whose required arguments are declared.
	 *
	 * @return void
	 */
	public function testEveryDescriptorIsComplete(): void {
		foreach (StackiqToolProvider::TOOL_DESCRIPTORS as $tool) {
			$this->assertNotSame('', $tool['name'], $tool['id']);
			$this->assertGreaterThan(30, strlen($tool['description']), $tool['id']);
			$this->assertSame('object', $tool['inputSchema']['type'], $tool['id']);
			foreach ($tool['inputSchema']['required'] as $required) {
				$this->assertArrayHasKey($required, $tool['inputSchema']['properties'], $tool['id'].' '.$required);
			}
		}
	}//end testEveryDescriptorIsComplete()

	/**
	 * Each tool id reaches the handler method of the same name, with the arguments unchanged.
	 *
	 * @return void
	 */
	public function testEveryToolDispatchesToItsHandler(): void {
		$contract = $this->createMock(McpContractTools::class);
		$read     = $this->createMock(McpReadTools::class);
		$write    = $this->createMock(McpWriteTools::class);
		$args     = ['probe' => 'x'];
		$handlers = [
			'getContractApprovalConfig' => $contract,
			'submitContractApproval'    => $contract,
			'submitContractRenewal'     => $contract,
		];
		foreach (self::READS as $name) {
			$handlers[$name] = ($handlers[$name] ?? $read);
		}

		foreach (array_keys(self::WRITES) as $name) {
			$handlers[$name] = ($handlers[$name] ?? $write);
		}

		foreach ($handlers as $name => $handler) {
			$handler->expects($this->once())->method($name)->with($args)->willReturn(['tool' => $name]);
		}

		$provider = new StackiqToolProvider($contract, $read, $write);
		foreach (array_keys($handlers) as $name) {
			$this->assertSame(['tool' => $name], $provider->invokeTool('stackiq.'.$name, $args), $name);
		}

		$this->assertSame('unknown_tool', $provider->invokeTool('stackiq.grantOrganisationMembership', [])['error']);
	}//end testEveryToolDispatchesToItsHandler()
}//end class
