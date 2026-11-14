<?php

/**
 * The lifecycle threshold of the service desk exchange: an application goes out only from the chosen state.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-entries-go-to-outside-systems-only-from-a-chosen-lifecycle-state-req-plat-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Stackiq\Service\ConnectionReportService;
use OCA\Stackiq\Service\Itsm\ItsmFlowGateway;
use OCA\Stackiq\Service\ItsmExchangeService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Asserts that the chosen threshold reaches the outbound flow's filter, is stored, and keeps earlier states home.
 */
class ItsmExchangeLifecycleThresholdTest extends TestCase {

	/**
	 * The organisation whose applications are exchanged.
	 *
	 * @var string
	 */
	private const ORG = '0f6c3a8e-1111-4c2b-9d6a-2a1b3c4d5e6f';

	/**
	 * OpenRegister's flow store.
	 *
	 * @var ItsmFlowGateway&MockObject
	 */
	private ItsmFlowGateway&MockObject $gateway;

	/**
	 * App settings kept in memory.
	 *
	 * @var array<string, mixed>
	 */
	private array $settings = [];

	/**
	 * The flows saved, by name.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * A gateway with OpenRegister present, the organisation and the TOPdesk source found, and every flow passing preflight.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settings = [];
		$this->saved    = [];
		$this->gateway  = $this->getMockBuilder(ItsmFlowGateway::class)
			->disableOriginalConstructor()
			->onlyMethods(['available', 'inspect', 'saveAndPublish', 'run', 'findObject'])
			->getMock();
		$this->gateway->method('available')->willReturn(true);
		$this->gateway->method('inspect')->willReturn(['blocking' => [], 'warnings' => []]);
		$this->gateway->method('saveAndPublish')->willReturnCallback(
			function (array $flow, ?string $uuid): string {
				$this->saved[(string) $flow['name']] = $flow;
				return $uuid ?? ('flow-' . count($this->saved));
			}
		);
		$this->gateway->method('findObject')->willReturnCallback(
			static function (string $register, string $schema, string $id): ?array {
				if ($register === 'stackiq' && $schema === 'organization' && $id === self::ORG) {
					return ['uuid' => self::ORG, 'name' => 'Gemeente Rotterdam'];
				}

				if ($register === 'integriq' && $schema === 'source' && $id === 'topdesk') {
					return ['uuid' => 'src-1', 'slug' => 'topdesk', 'location' => 'https://rotterdam.topdesk.net/tas/api'];
				}

				return null;
			}
		);
	}//end setUp()

	/**
	 * The service under test, over the in-memory settings.
	 *
	 * @return ItsmExchangeService
	 */
	private function service(): ItsmExchangeService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => (string) ($this->settings[$key] ?? $default));
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->settings[$key] = $value;
				return true;
			}
		);
		$config->method('setValueBool')->willReturnCallback(
			function (string $app, string $key, bool $value): bool {
				$this->settings[$key] = $value;
				return true;
			}
		);
		$config->method('getValueBool')->willReturnCallback(fn (string $app, string $key, bool $default = false): bool => (bool) ($this->settings[$key] ?? $default));

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturn('https://cloud.example.nl/index.php/apps/stackiq');

		return new ItsmExchangeService(
			gateway: $this->gateway,
			appConfig: $config,
			urlGenerator: $urls,
			logger: $this->createMock(LoggerInterface::class),
			connectionReports: $this->createMock(ConnectionReportService::class)
		);
	}//end service()

	/**
	 * The condition of the outbound flow's `ours` filter node.
	 *
	 * @param array<string, mixed> $flow The outbound flow.
	 *
	 * @return array<string, mixed> The condition.
	 */
	private static function oursCondition(array $flow): array {
		foreach ($flow['nodes'] as $node) {
			if ($node['id'] === 'ours') {
				return $node['config']['condition'];
			}
		}

		self::fail('The outbound flow has no `ours` filter node.');
	}//end oursCondition()

	/**
	 * Evaluate the JsonLogic subset the `ours` filter uses (and, ==, !!, in, var) against a trigger payload.
	 *
	 * @param mixed                $rule    The rule.
	 * @param array<string, mixed> $payload The trigger payload.
	 *
	 * @return mixed The value.
	 */
	private static function evaluate(mixed $rule, array $payload): mixed {
		if (is_array($rule) === false || array_is_list($rule) === true) {
			return $rule;
		}

		$operator = (string) array_key_first($rule);
		$args     = $rule[$operator];
		switch ($operator) {
			case 'var':
				$value = $payload;
				foreach (explode('.', (string) $args) as $part) {
					$value = (is_array($value) === true ? ($value[$part] ?? null) : null);
				}
				return $value;
			case 'and':
				foreach ($args as $arg) {
					if ((bool) self::evaluate(rule: $arg, payload: $payload) === false) {
						return false;
					}
				}
				return true;
			case '==':
				return self::evaluate(rule: $args[0], payload: $payload) == self::evaluate(rule: $args[1], payload: $payload);
			case '!!':
				return (bool) self::evaluate(rule: $args, payload: $payload);
			case 'in':
				return in_array(self::evaluate(rule: $args[0], payload: $payload), (array) self::evaluate(rule: $args[1], payload: $payload), true);
		}

		self::fail('Unexpected operator ' . $operator . ' in the outbound filter.');
	}//end evaluate()

	/**
	 * Whether the outbound flow lets a usage with a status through its `ours` filter.
	 *
	 * @param array<string, mixed> $flow   The outbound flow.
	 * @param string|null          $status The usage's lifecycle state.
	 *
	 * @return bool
	 */
	private static function sends(array $flow, ?string $status): bool {
		$usage = ['consumer' => self::ORG, 'module' => 'mod-1', 'status' => $status];

		return (bool) self::evaluate(rule: self::oursCondition(flow: $flow), payload: ['json' => $usage]);
	}//end sends()

	/**
	 * The states sent are the threshold and every later one, in lifecycle order.
	 *
	 * @return void
	 */
	public function testTheSentStatesStartAtTheThreshold(): void {
		$this->assertSame(['In production', 'To be phased out', 'Phased out'], ItsmExchangeService::sentStates(threshold: 'In production'));
		$this->assertSame(ItsmExchangeService::LIFECYCLE_STATES, ItsmExchangeService::sentStates(threshold: 'Acquisition'));
		$this->assertSame(['Phased out'], ItsmExchangeService::sentStates(threshold: 'Phased out'));
	}//end testTheSentStatesStartAtTheThreshold()

	/**
	 * A threshold that is no lifecycle state is refused by name.
	 *
	 * @return void
	 */
	public function testAnUnknownThresholdIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown lifecycle state "In gebruik"');

		ItsmExchangeService::sentStates(threshold: 'In gebruik');
	}//end testAnUnknownThresholdIsRefused()

	/**
	 * Without a choice the outbound filter sends from In production: a planned application stays home.
	 *
	 * @return void
	 */
	public function testTheDefaultThresholdKeepsAPlannedApplicationHome(): void {
		$flows = $this->service()->buildFlows(desk: 'topdesk', organisation: self::ORG, runAs: 'admin', location: 'https://rotterdam.topdesk.net/tas/api');

		$this->assertContains(
			['in' => [['var' => 'json.status'], ['In production', 'To be phased out', 'Phased out']]],
			self::oursCondition(flow: $flows['outbound'])['and']
		);
		$this->assertFalse(self::sends(flow: $flows['outbound'], status: 'Planned'));
		$this->assertFalse(self::sends(flow: $flows['outbound'], status: 'Acquisition'));
		$this->assertFalse(self::sends(flow: $flows['outbound'], status: null));
		$this->assertTrue(self::sends(flow: $flows['outbound'], status: 'In production'));
		$this->assertTrue(self::sends(flow: $flows['outbound'], status: 'To be phased out'));
	}//end testTheDefaultThresholdKeepsAPlannedApplicationHome()

	/**
	 * The threshold reaches only the outbound flow; the imports are filled as before.
	 *
	 * @return void
	 */
	public function testOnlyTheOutboundFlowCarriesTheThreshold(): void {
		$flows = $this->service()->buildFlows(desk: 'servicenow', organisation: self::ORG, runAs: 'admin', location: 'https://x.service-now.com', threshold: 'Planned');

		foreach ($flows as $key => $flow) {
			$this->assertStringNotContainsString('%SENT_STATES%', (string) json_encode($flow), $key . ' keeps a placeholder');
		}

		$this->assertTrue(self::sends(flow: $flows['outbound'], status: 'Planned'));
		$this->assertFalse(self::sends(flow: $flows['outbound'], status: 'Acquisition'));
		$this->assertStringNotContainsString('"Planned","In production"', (string) json_encode($flows['applications']));
	}//end testOnlyTheOutboundFlowCarriesTheThreshold()

	/**
	 * A chosen threshold is written into the published outbound flow and kept in the stored set-up.
	 *
	 * @return void
	 */
	public function testAChosenThresholdIsPublishedAndStored(): void {
		$service = $this->service();
		$result  = $service->setUp(desk: 'topdesk', organisation: self::ORG, runAs: 'admin', threshold: 'Acquisition');

		$this->assertTrue($result['created']);
		$this->assertSame('Acquisition', $result['threshold']);
		$outbound = $this->saved['Service desk export: applications in use (TOPdesk)'];
		$this->assertTrue(self::sends(flow: $outbound, status: 'Acquisition'));
		$this->assertSame('Acquisition', json_decode((string) $this->settings['itsm_exchange'], true)['threshold']);
		$this->assertSame('Acquisition', $service->status()['threshold']);
		$this->assertSame(ItsmExchangeService::LIFECYCLE_STATES, $service->status()['thresholds']);
	}//end testAChosenThresholdIsPublishedAndStored()

	/**
	 * Setting up again without a threshold keeps the stored one; a set-up from before the threshold reads the default.
	 *
	 * @return void
	 */
	public function testSettingUpAgainKeepsTheStoredThreshold(): void {
		$this->settings['itsm_exchange'] = json_encode(['desk' => 'topdesk', 'organisation' => self::ORG, 'flows' => []]);
		$service = $this->service();
		$this->assertSame('In production', $service->status()['threshold']);

		$service->setUp(desk: 'topdesk', organisation: self::ORG, runAs: 'admin', threshold: 'To be phased out');
		$again = $service->setUp(desk: 'topdesk', organisation: self::ORG, runAs: 'admin');

		$this->assertSame('To be phased out', $again['threshold']);
		$outbound = $this->saved['Service desk export: applications in use (TOPdesk)'];
		$this->assertFalse(self::sends(flow: $outbound, status: 'In production'));
		$this->assertTrue(self::sends(flow: $outbound, status: 'Phased out'));
	}//end testSettingUpAgainKeepsTheStoredThreshold()

	/**
	 * A set-up with an unknown threshold creates nothing and names the states on offer.
	 *
	 * @return void
	 */
	public function testASetUpWithAnUnknownThresholdCreatesNothing(): void {
		$this->gateway->expects($this->never())->method('saveAndPublish');

		$result = $this->service()->setUp(desk: 'topdesk', organisation: self::ORG, runAs: 'admin', threshold: 'Concept');

		$this->assertFalse($result['created']);
		$this->assertStringContainsString('"Concept" is no lifecycle state', $result['message']);
		$this->assertStringContainsString('Acquisition, Planned, In production', $result['message']);
		$this->assertArrayNotHasKey('itsm_exchange', $this->settings);
	}//end testASetUpWithAnUnknownThresholdCreatesNothing()
}//end class
