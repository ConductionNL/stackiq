<?php

/**
 * The service desk exchange sends an application only from a chosen lifecycle state on.
 *
 * The outbound flow's filter keeps the usages whose status is the chosen state
 * or a later one; the choice is stored with the set-up and read back, and an
 * unknown state creates nothing.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
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
use OCA\Stackiq\Service\Itsm\ItsmFlowGateway;
use OCA\Stackiq\Service\Itsm\ItsmSendFrom;
use OCA\Stackiq\Service\ItsmExchangeService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The lifecycle threshold of the outbound flow.
 *
 * @covers \OCA\Stackiq\Service\ItsmExchangeService
 * @covers \OCA\Stackiq\Service\Itsm\ItsmSendFrom
 */
class ItsmExchangeLifecycleThresholdTest extends TestCase {

	/**
	 * The organisation whose applications are exchanged.
	 *
	 * @var string
	 */
	private const ORG = '0f6c3a8e-1111-4c2b-9d6a-2a1b3c4d5e6f';

	/**
	 * App settings kept in memory.
	 *
	 * @var array<string, mixed>
	 */
	private array $settings = [];

	/**
	 * OpenRegister's flow store.
	 *
	 * @var ItsmFlowGateway&MockObject
	 */
	private ItsmFlowGateway&MockObject $gateway;

	/**
	 * The flows saved, by name.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * A gateway with OpenRegister, the organisation and the TOPdesk source present.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settings = [];
		$this->saved    = [];
		$this->gateway  = $this->getMockBuilder(className: ItsmFlowGateway::class)
			->disableOriginalConstructor()
			->onlyMethods(methods: ['available', 'inspect', 'saveAndPublish', 'run', 'findObject'])
			->getMock();
		$this->gateway->method('available')->willReturn(true);
		$this->gateway->method('inspect')->willReturn(['blocking' => [], 'warnings' => []]);
		$this->gateway->method('saveAndPublish')->willReturnCallback(
			function (array $flow, ?string $uuid): string {
				$this->saved[(string) $flow['name']] = $flow;
				return 'flow-' . count($this->saved);
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
	 * The service under test.
	 *
	 * @return ItsmExchangeService
	 */
	private function service(): ItsmExchangeService {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => (string) ($this->settings[$key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->settings[$key] = $value;
				return true;
			}
		);
		$config->method('setValueBool')->willReturn(true);
		$config->method('getValueBool')->willReturn(false);

		$urls = $this->createMock(originalClassName: IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturn('https://cloud.example.nl/index.php/apps/stackiq');

		return new ItsmExchangeService(
			gateway: $this->gateway,
			appConfig: $config,
			urlGenerator: $urls,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end service()

	/**
	 * The lifecycle condition of the outbound flow's filter.
	 *
	 * @param array<string, mixed> $flow The outbound flow.
	 *
	 * @return array<int, mixed> The `in` operands: the status variable and the states sent.
	 */
	private static function sendCondition(array $flow): array {
		foreach ($flow['nodes'] as $node) {
			if ($node['id'] !== 'ours') {
				continue;
			}

			foreach ($node['config']['condition']['and'] as $part) {
				if (isset($part['in']) === true) {
					return $part['in'];
				}
			}
		}

		return [];
	}//end sendCondition()

	/**
	 * The states from a state on follow the usage lifecycle, and an unknown state is refused.
	 *
	 * @return void
	 */
	public function testTheStatesFromAStateFollowTheLifecycle(): void {
		$this->assertSame(
			expected: ['In production', 'To be phased out', 'Phased out'],
			actual: ItsmSendFrom::statesFrom(state: 'In production')
		);
		$this->assertSame(expected: ItsmSendFrom::STATES, actual: ItsmSendFrom::statesFrom(state: 'Acquisition'));
		$this->assertTrue(condition: ItsmSendFrom::isState(state: 'Planned'));
		$this->assertFalse(condition: ItsmSendFrom::isState(state: 'In gebruik'));

		$this->expectException(exception: InvalidArgumentException::class);
		$this->expectExceptionMessage(message: 'Unknown lifecycle state "In gebruik"');
		ItsmSendFrom::statesFrom(state: 'In gebruik');
	}//end testTheStatesFromAStateFollowTheLifecycle()

	/**
	 * By default the outbound filter sends from "In production", so a planned application stays in stackiq.
	 *
	 * @return void
	 */
	public function testByDefaultAPlannedApplicationIsNotSent(): void {
		$flows = $this->service()->buildFlows(desk: 'topdesk', organisation: self::ORG, runAs: 'admin', location: 'https://rotterdam.topdesk.net');

		$condition = self::sendCondition(flow: $flows['outbound']);
		$this->assertSame(expected: ['var' => 'json.status'], actual: $condition[0]);
		$this->assertSame(expected: ['In production', 'To be phased out', 'Phased out'], actual: $condition[1]);
		$this->assertNotContains(needle: 'Planned', haystack: $condition[1]);
	}//end testByDefaultAPlannedApplicationIsNotSent()

	/**
	 * A chosen state goes into the outbound filter; the inbound flows carry no such condition.
	 *
	 * @return void
	 */
	public function testAChosenStateGoesIntoTheOutboundFilterOnly(): void {
		$service = $this->service();
		$this->assertNull(actual: $service->chooseSendFrom(state: 'Planned'));

		$flows = $service->buildFlows(desk: 'servicenow', organisation: self::ORG, runAs: 'admin', location: 'https://dev.service-now.com');

		$this->assertSame(
			expected: ['Planned', 'In production', 'To be phased out', 'Phased out'],
			actual: self::sendCondition(flow: $flows['outbound'])[1]
		);
		$this->assertStringNotContainsString(needle: '%SEND_STATES%', haystack: (string) json_encode($flows));
		foreach (array_diff_key($flows, ['outbound' => true]) as $key => $flow) {
			$this->assertSame(expected: [], actual: self::sendCondition(flow: $flow), message: $key);
		}
	}//end testAChosenStateGoesIntoTheOutboundFilterOnly()

	/**
	 * Setting up after a choice publishes the outbound flow with it; the status reads it back.
	 *
	 * @return void
	 */
	public function testTheSetUpUsesTheChosenStateAndTheStatusReadsIt(): void {
		$service = $this->service();
		$this->assertSame(expected: 'In production', actual: $service->status()['sendFrom']);
		$this->assertSame(expected: ItsmSendFrom::STATES, actual: $service->status()['lifecycleStates']);

		$this->assertNull(actual: $service->chooseSendFrom(state: 'To be phased out'));
		$result = $service->setUp(desk: 'topdesk', organisation: self::ORG, runAs: 'admin');

		$this->assertTrue(condition: $result['created']);
		$outbound = array_values(array_filter($this->saved, static fn (array $flow): bool => self::sendCondition(flow: $flow) !== []));
		$this->assertCount(expectedCount: 1, haystack: $outbound);
		$this->assertSame(expected: ['To be phased out', 'Phased out'], actual: self::sendCondition(flow: $outbound[0])[1]);
		$this->assertSame(expected: 'To be phased out', actual: $this->settings[ItsmExchangeService::SEND_FROM_KEY]);
		$this->assertSame(expected: 'To be phased out', actual: $service->status()['sendFrom']);
	}//end testTheSetUpUsesTheChosenStateAndTheStatusReadsIt()

	/**
	 * An empty choice stores the default; an unknown state is refused and changes nothing;
	 * a stored value that is no longer a state reads as the default.
	 *
	 * @return void
	 */
	public function testAnEmptyChoiceIsTheDefaultAndAnUnknownStateIsRefused(): void {
		$service = $this->service();

		$this->assertNull(actual: $service->chooseSendFrom(state: ''));
		$this->assertSame(expected: 'In production', actual: $this->settings[ItsmExchangeService::SEND_FROM_KEY]);

		$refusal = $service->chooseSendFrom(state: 'Concept');
		$this->assertSame(expected: 'Nothing was created. ' . ItsmSendFrom::unknown(state: 'Concept'), actual: $refusal);
		$this->assertSame(expected: 'In production', actual: $this->settings[ItsmExchangeService::SEND_FROM_KEY]);

		$this->settings[ItsmExchangeService::SEND_FROM_KEY] = 'Gepland';
		$this->assertSame(expected: 'In production', actual: $service->status()['sendFrom']);
	}//end testAnEmptyChoiceIsTheDefaultAndAnUnknownStateIsRefused()
}//end class
