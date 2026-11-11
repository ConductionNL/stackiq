<?php

/**
 * The service desk exchange set-up: all flows or none, and updates rather than duplicates.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\ConnectionReportService;
use OCA\Stackiq\Service\Itsm\ItsmFlowGateway;
use OCA\Stackiq\Service\ItsmExchangeService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Asserts what the set-up creates, refuses and reports.
 */
class ItsmExchangeServiceTest extends TestCase {

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
	 * Reports to integriq.
	 *
	 * @var ConnectionReportService&MockObject
	 */
	private ConnectionReportService&MockObject $reports;

	/**
	 * The service under test.
	 *
	 * @return ItsmExchangeService
	 */
	private function service(): ItsmExchangeService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => (string) ($this->settings[$key] ?? $default));
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->settings[$key] = $value;
			return true;
		});
		$config->method('setValueBool')->willReturnCallback(function (string $app, string $key, bool $value): bool {
			$this->settings[$key] = $value;
			return true;
		});
		$config->method('getValueBool')->willReturnCallback(fn (string $app, string $key, bool $default = false): bool => (bool) ($this->settings[$key] ?? $default));

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturn('https://cloud.example.nl/index.php/apps/stackiq');

		return new ItsmExchangeService(
			gateway: $this->gateway,
			appConfig: $config,
			urlGenerator: $urls,
			logger: $this->createMock(LoggerInterface::class),
			connectionReports: $this->reports
		);
	}//end service()

	/**
	 * A gateway with OpenRegister present, the organisation and the TOPdesk source found.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->gateway = $this->getMockBuilder(ItsmFlowGateway::class)
			->disableOriginalConstructor()
			->onlyMethods(['available', 'inspect', 'saveAndPublish', 'run', 'findObject'])
			->getMock();
		$this->gateway->method('available')->willReturn(true);
		$this->gateway->method('findObject')->willReturnCallback(
			static function (string $register, string $schema, string $id): ?array {
				if ($register === 'stackiq' && $schema === 'organization' && in_array($id, [self::ORG, 'gemeente-rotterdam'], true) === true) {
					return ['uuid' => self::ORG, 'name' => 'Gemeente Rotterdam'];
				}

				if ($register === 'integriq' && $schema === 'source' && $id === 'topdesk') {
					return ['uuid' => 'src-1', 'slug' => 'topdesk', 'location' => 'https://rotterdam.topdesk.net/tas/api'];
				}

				return null;
			}
		);
		$this->reports  = $this->createMock(ConnectionReportService::class);
		$this->settings = [];
	}//end setUp()

	/**
	 * Every flow passes preflight: all six are saved, published, stored, and the switch goes on.
	 *
	 * @return void
	 */
	public function testAValidSetUpCreatesEveryFlow(): void {
		$this->gateway->method('inspect')->willReturn(['blocking' => [], 'warnings' => []]);
		$saved = [];
		$this->gateway->expects($this->exactly(6))->method('saveAndPublish')->willReturnCallback(
			static function (array $flow, ?string $uuid) use (&$saved): string {
				$saved[] = [$flow, $uuid];
				return 'flow-' . count($saved);
			}
		);
		$this->reports->expects($this->once())->method('itsmSetUp')->with(true, $this->stringContains('TOPdesk'));

		$result = $this->service()->setUp(desk: 'topdesk', organisation: self::ORG, runAs: 'admin');

		$this->assertTrue($result['created']);
		$this->assertSame(['applications', 'relations', 'licences', 'contracts', 'outbound', 'file'], array_keys($result['flows']));
		$this->assertSame([null, null, null, null, null, null], array_column($saved, 1), 'a first set-up creates');
		$this->assertStringContainsString('https://rotterdam.topdesk.net/tas/secure', (string) json_encode($saved[4][0], JSON_UNESCAPED_SLASHES), 'the record link uses the source the admin set up');
		$this->assertStringContainsString(self::ORG, (string) json_encode($saved[0][0]), 'the import writes usages of the chosen organisation');
		$this->assertTrue($this->settings['itsm_exchange_enabled']);
		$stored = json_decode((string) $this->settings['itsm_exchange'], true);
		$this->assertSame('topdesk', $stored['desk']);
		$this->assertSame(self::ORG, $stored['organisation']);
		$this->assertSame('flow-1', $stored['flows']['applications']);
	}//end testAValidSetUpCreatesEveryFlow()

	/**
	 * Setting up again passes the stored uuids, so the flows are updated, not added.
	 *
	 * @return void
	 */
	public function testASecondSetUpUpdatesTheSameFlows(): void {
		$this->gateway->method('inspect')->willReturn(['blocking' => [], 'warnings' => []]);
		$uuids = [];
		$this->gateway->method('saveAndPublish')->willReturnCallback(
			static function (array $flow, ?string $uuid) use (&$uuids): string {
				$uuids[] = $uuid;
				return $uuid ?? ('flow-' . count($uuids));
			}
		);

		$service = $this->service();
		$first   = $service->setUp(desk: 'topdesk', organisation: self::ORG, runAs: 'admin');
		$uuids   = [];
		$second  = $service->setUp(desk: 'topdesk', organisation: self::ORG, runAs: 'admin');

		$this->assertSame(array_values($first['flows']), $uuids);
		$this->assertSame($first['flows'], $second['flows']);
	}//end testASecondSetUpUpdatesTheSameFlows()

	/**
	 * One flow blocked by preflight: nothing is saved, and the answer names the flow, the step and the reason.
	 *
	 * @return void
	 */
	public function testABlockedFlowCreatesNothing(): void {
		$this->gateway->method('inspect')->willReturnCallback(
			static function (array $flow): array {
				if (str_contains($flow['name'], 'relations') === true) {
					return ['blocking' => [['step' => 'map-all', 'reason' => 'node-config-rejected', 'detail' => 'no mapping itsm-topdesk-relation-inbound']], 'warnings' => []];
				}

				return ['blocking' => [], 'warnings' => []];
			}
		);
		$this->gateway->expects($this->never())->method('saveAndPublish');
		$this->reports->expects($this->once())->method('itsmSetUp')->with(false, $this->anything());

		$result = $this->service()->setUp(desk: 'topdesk', organisation: self::ORG, runAs: 'admin');

		$this->assertFalse($result['created']);
		$this->assertSame(['relations'], array_keys($result['blocking']));
		$this->assertStringContainsString('relations', $result['message']);
		$this->assertStringContainsString('map-all', $result['message']);
		$this->assertStringContainsString('node-config-rejected', $result['message']);
		$this->assertArrayNotHasKey('itsm_exchange_enabled', $this->settings);
	}//end testABlockedFlowCreatesNothing()

	/**
	 * An unknown desk, an unknown organisation or a missing integriq source is refused before any flow is built.
	 *
	 * @return void
	 */
	public function testWhatIsMissingIsNamed(): void {
		$this->gateway->expects($this->never())->method('inspect');
		$this->gateway->expects($this->never())->method('saveAndPublish');
		$service = $this->service();

		$this->assertStringContainsString('Unknown service desk', $service->setUp(desk: 'jira', organisation: self::ORG, runAs: 'admin')['message']);
		$this->assertStringContainsString('does not exist', $service->setUp(desk: 'topdesk', organisation: 'nope', runAs: 'admin')['message']);
		$this->assertStringContainsString('no source "servicenow"', $service->setUp(desk: 'servicenow', organisation: self::ORG, runAs: 'admin')['message']);
	}//end testWhatIsMissingIsNamed()

	/**
	 * An organisation given by slug is stored, and written into the flows, as its uuid.
	 *
	 * @return void
	 */
	public function testAnOrganisationGivenBySlugIsStoredAsItsUuid(): void {
		$this->gateway->method('inspect')->willReturn(['blocking' => [], 'warnings' => []]);
		$saved = [];
		$this->gateway->method('saveAndPublish')->willReturnCallback(
			static function (array $flow) use (&$saved): string {
				$saved[] = $flow;
				return 'flow-' . count($saved);
			}
		);

		$this->service()->setUp(desk: 'topdesk', organisation: 'gemeente-rotterdam', runAs: 'admin');

		$this->assertSame(self::ORG, json_decode((string) $this->settings['itsm_exchange'], true)['organisation']);
		$this->assertStringContainsString(self::ORG, (string) json_encode($saved[0]));
		$this->assertStringNotContainsString('gemeente-rotterdam', (string) json_encode($saved));
	}//end testAnOrganisationGivenBySlugIsStoredAsItsUuid()

	/**
	 * A preflight that throws is a refusal the page can show, reported to integriq, not an error.
	 *
	 * @return void
	 */
	public function testAPreflightThatThrowsIsARefusal(): void {
		$this->gateway->method('inspect')->willThrowException(new \RuntimeException('preflight unresolvable'));
		$this->gateway->expects($this->never())->method('saveAndPublish');
		$this->reports->expects($this->once())->method('itsmSetUp')->with(false, $this->stringContains('preflight unresolvable'));

		$result = $this->service()->setUp(desk: 'topdesk', organisation: self::ORG, runAs: 'admin');

		$this->assertFalse($result['created']);
		$this->assertStringContainsString('Nothing was created', $result['message']);
	}//end testAPreflightThatThrowsIsARefusal()
	/**
	 * Every flow a set-up saves, for both desks, matches its recording (RECORD_GOLDEN=1 records it): the filled
	 * templates with the organisation's uuid, the run-as user, the record links from the source's location and
	 * the asset template.
	 *
	 * @return void
	 */
	public function testTheSavedFlowsMatchTheGolden(): void {
		$this->gateway = $this->getMockBuilder(ItsmFlowGateway::class)
			->disableOriginalConstructor()
			->onlyMethods(['available', 'inspect', 'saveAndPublish', 'run', 'findObject'])
			->getMock();
		$this->gateway->method('available')->willReturn(true);
		$this->gateway->method('inspect')->willReturn(['blocking' => [], 'warnings' => []]);
		$this->gateway->method('findObject')->willReturnCallback(
			static function (string $register, string $schema, string $id): ?array {
				if ($schema === 'organization') {
					return ['uuid' => self::ORG];
				}

				return ['uuid' => 'src-' . $id, 'location' => 'https://' . $id . '.example.net:8443/tas/api?x=1'];
			}
		);
		$saved = [];
		$this->gateway->method('saveAndPublish')->willReturnCallback(
			static function (array $flow, ?string $uuid) use (&$saved): string {
				$saved[] = $flow;
				return 'flow-' . count($saved);
			}
		);

		$service = $this->service();
		$service->setUp(desk: 'topdesk', organisation: 'gemeente-rotterdam', runAs: 'beheerder', templateId: 'tpl-7');
		$service->setUp(desk: 'servicenow', organisation: self::ORG, runAs: 'admin');

		$json = (string) json_encode($saved, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		$file = __DIR__ . '/golden/itsmExchangeSavedFlows.json';
		if (getenv('RECORD_GOLDEN') !== false) {
			file_put_contents($file, $json . "\n");
		}

		$this->assertCount(12, $saved);
		$this->assertSame((string) file_get_contents($file), $json . "\n");
	}//end testTheSavedFlowsMatchTheGolden()
	/**
	 * A save that fails part-way keeps the flows already saved, next to what an earlier set-up stored, and is
	 * refused naming how far it got; the exchange is not switched on.
	 *
	 * @return void
	 */
	public function testAFailedSaveKeepsWhatWasSaved(): void {
		$this->settings['itsm_exchange'] = (string) json_encode(['desk' => 'topdesk', 'organisation' => self::ORG, 'flows' => ['outbound' => 'old-outbound']]);
		$this->gateway->method('inspect')->willReturn(['blocking' => [], 'warnings' => []]);
		$calls = 0;
		$this->gateway->method('saveAndPublish')->willReturnCallback(
			static function (array $flow, ?string $uuid) use (&$calls): string {
				$calls++;
				if ($calls === 3) {
					throw new \RuntimeException('store full');
				}

				return 'flow-' . $calls;
			}
		);
		$this->reports->expects($this->once())->method('itsmSetUp')->with(false, 'Saving the flows failed after 2 of 6: store full');

		$result = $this->service()->setUp(desk: 'topdesk', organisation: self::ORG, runAs: 'admin');

		$this->assertSame(['created' => false, 'message' => 'Saving the flows failed after 2 of 6: store full', 'blocking' => []], $result);
		$stored = json_decode((string) $this->settings['itsm_exchange'], true);
		$this->assertSame(['outbound' => 'old-outbound', 'applications' => 'flow-1', 'relations' => 'flow-2'], $stored['flows']);
		$this->assertArrayNotHasKey('itsm_exchange_enabled', $this->settings);
	}//end testAFailedSaveKeepsWhatWasSaved()
}//end class
