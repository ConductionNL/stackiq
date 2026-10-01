<?php

/**
 * The service desk exchange flow templates, filled the way the set-up fills them.
 *
 * Preflight against the live node registry runs on the instance (the set-up
 * refuses a flow it blocks). What this test holds is what the registry cannot
 * see: that every field a write names exists on its schema, that the
 * placeholders are all filled, and that the two hashes cover the field sets
 * D5 of the design says they cover, which is what keeps writes from echoing.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-002-the-import-creates-and-updates-stackiq-records-and-never-duplicates-them
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use OCA\Stackiq\Service\Itsm\ItsmFlowGateway;
use OCA\Stackiq\Service\ItsmExchangeService;
use OCA\Stackiq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Asserts the shape, the field names and the hash inputs of every filled flow.
 */
class ItsmFlowTemplatesTest extends TestCase {

	/**
	 * Node types OpenRegister registers (lib/Listener/FlowNodeRegistrationListener.php)
	 * and integriq registers (lib/Flow/FlowNodeListener.php), on development 2026-10-01.
	 *
	 * @var list<string>
	 */
	private const KNOWN_TYPES = [
		'openregister.trigger-object',
		'openregister.trigger-schedule',
		'openregister.trigger-manual',
		'openregister.object-read',
		'openregister.object-write',
		'openregister.filter',
		'openregister.explode',
		'openregister.set-fields',
		'openregister.end',
		'openconnector.source-paginate',
		'openconnector.apply-mapping',
		'openconnector.contract',
		'openconnector.contract-commit',
		'openconnector.source-call',
	];

	/**
	 * The stackiq-owned fields of an application in use (design D2).
	 *
	 * @var list<string>
	 */
	private const STACKIQ_OWNED = ['bbnLevel', 'timeClassification', 'publicationDate', 'licencesBought', 'licencesInUse', 'licenceMetric', 'contractNumber', 'contractEndDate'];

	/**
	 * The service-desk-owned fields of an application in use (design D2).
	 *
	 * @var list<string>
	 */
	private const SOURCE_OWNED = ['recordId', 'recordUrl', 'name', 'supplierName', 'installedVersion', 'status', 'description'];

	/**
	 * Every flow, filled for one desk.
	 *
	 * @param string $desk The desk.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function flows(string $desk): array {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturn('https://cloud.example.nl/index.php/apps/stackiq');

		$service = new ItsmExchangeService(
			gateway: $this->createMock(ItsmFlowGateway::class),
			appConfig: $this->createMock(IAppConfig::class),
			urlGenerator: $urls,
			logger: $this->createMock(LoggerInterface::class)
		);

		return $service->buildFlows(desk: $desk, organisation: '0f6c3a8e-1111-4c2b-9d6a-2a1b3c4d5e6f', runAs: 'admin', location: 'https://desk.example.nl/tas/api', templateId: 'tpl-application');
	}//end flows()

	/**
	 * The merged register's schemas.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function schemas(): array {
		$dir    = __DIR__ . '/../../../lib/Settings';
		$merged = json_decode((string) file_get_contents($dir . '/softwarecatalogus_register.json'), true);
		$merge  = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$files  = glob($dir . '/register.d/*.json');
		sort($files);
		foreach ($files as $file) {
			$merged = $merge->invoke(null, $merged, json_decode((string) file_get_contents($file), true));
		}

		return $merged['components']['schemas'];
	}//end schemas()

	/**
	 * Nodes by id.
	 *
	 * @param array<string, mixed> $flow The flow.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function nodes(array $flow): array {
		$byId = [];
		foreach ($flow['nodes'] as $node) {
			$byId[$node['id']] = $node;
		}

		return $byId;
	}//end nodes()

	/**
	 * Every flow is filled, uses known node types, and its edges join nodes that exist.
	 *
	 * @return void
	 */
	public function testEveryFlowIsFilledAndWellFormed(): void {
		foreach (['topdesk', 'servicenow'] as $desk) {
			$flows = $this->flows(desk: $desk);
			$this->assertSame(['applications', 'relations', 'licences', 'contracts', 'outbound', 'file'], array_keys($flows));
			foreach ($flows as $key => $flow) {
				$label = $desk . '/' . $key;
				$this->assertDoesNotMatchRegularExpression('/%[A-Z_]+%/', (string) json_encode($flow), $label . ' has an unfilled placeholder');
				$this->assertSame('stackiq', $flow['app']);
				$nodes = $this->nodes(flow: $flow);
				$this->assertCount(count($flow['nodes']), $nodes, $label . ' has duplicate node ids');

				$incoming = [];
				foreach ($flow['edges'] as $edge) {
					$this->assertArrayNotHasKey('type', $edge, $label . ': a step lives on a node, never on an edge');
					$this->assertArrayHasKey($edge['from'], $nodes, $label . ' edge from');
					$this->assertArrayHasKey($edge['to'], $nodes, $label . ' edge to');
					$incoming[$edge['to']] = true;
				}

				$ends = 0;
				foreach ($nodes as $id => $node) {
					$this->assertContains($node['type'], self::KNOWN_TYPES, $label . ' node ' . $id);
					if (str_starts_with($node['type'], 'openregister.trigger-') === false) {
						$this->assertArrayHasKey($id, $incoming, $label . ' node ' . $id . ' is unreachable');
					}

					if ($node['type'] === 'openregister.end') {
						$ends++;
					}
				}

				$this->assertGreaterThanOrEqual(1, $ends, $label . ' has an end');
			}//end foreach
		}//end foreach
	}//end testEveryFlowIsFilledAndWellFormed()

	/**
	 * Every field a write sets and every filter a read uses exists on the schema it names.
	 *
	 * @return void
	 */
	public function testEveryWrittenFieldExistsOnItsSchema(): void {
		$schemas = $this->schemas();
		$writes  = 0;
		foreach ($this->flows(desk: 'topdesk') as $key => $flow) {
			foreach ($flow['nodes'] as $node) {
				$config = $node['config'];
				if (in_array($node['type'], ['openregister.object-write', 'openregister.object-read'], true) === false) {
					continue;
				}

				$this->assertSame('stackiq', $config['register'], $key . '/' . $node['id']);
				$this->assertArrayHasKey($config['schema'], $schemas, $key . '/' . $node['id']);
				$props = $schemas[$config['schema']]['properties'];
				$named = array_keys($config['fields'] ?? []);
				foreach (($config['match'] ?? []) as $pair) {
					$named[] = $pair['property'];
				}

				$named = array_merge($named, array_keys($config['filters'] ?? []));
				foreach ($named as $field) {
					if ($field === '@self' || str_starts_with($field, '@self.') === true) {
						continue;
					}

					$this->assertArrayHasKey($field, $props, $key . '/' . $node['id'] . ' names ' . $config['schema'] . '.' . $field);
				}

				$writes++;
			}//end foreach
		}//end foreach

		$this->assertGreaterThanOrEqual(15, $writes);
	}//end testEveryWrittenFieldExistsOnItsSchema()

	/**
	 * The import hashes only what the service desk owns, so an export's change to a stackiq field never comes back as a write.
	 *
	 * @return void
	 */
	public function testTheImportHashesTheOwnershipFilteredRecord(): void {
		foreach ($this->flows(desk: 'topdesk') as $key => $flow) {
			if ($key === 'outbound') {
				continue;
			}

			$nodes  = $this->nodes(flow: $flow);
			$decide = $nodes['decide']['config'];
			$owned  = $nodes['map-owned']['config'];
			$this->assertSame('owned', $owned['output'], $key);
			$this->assertSame('inbound', $owned['ownership'], $key);
			$this->assertSame('record.recordId', $owned['exists'], $key . ': exists is always set, so the projection is always the source-owned one');
			$this->assertSame($nodes['map-all']['config']['mapping'], $owned['mapping'], $key . ': both maps use the same preset');
			$this->assertSame('owned', $decide['hashPosition'], $key);
			$this->assertSame('owned', $nodes['commit']['config']['targetHashPosition'], $key);
		}

		$applications = $this->nodes(flow: $this->flows(desk: 'topdesk')['applications']);
		foreach ($applications['usage-update']['config']['fields'] as $field => $value) {
			$this->assertDoesNotMatchRegularExpression('/record\./', (string) $value, 'an update writes ' . $field . ' from the owned projection only');
		}
	}//end testTheImportHashesTheOwnershipFilteredRecord()

	/**
	 * The export hashes only what stackiq owns, so an import's write never makes a call.
	 *
	 * @return void
	 */
	public function testTheExportHashesOnlyStackiqOwnedFields(): void {
		$nodes = $this->nodes(flow: $this->flows(desk: 'servicenow')['outbound']);
		$this->assertSame('stackiqOwned', $nodes['decide']['config']['hashPosition']);
		$this->assertSame('usage.uuid', $nodes['decide']['config']['idPosition']);

		$hashed = [];
		foreach (array_keys($nodes['owned']['config']['set']) as $path) {
			$this->assertStringStartsWith('stackiqOwned.', $path);
			$hashed[] = substr($path, strlen('stackiqOwned.'));
		}

		$this->assertSame(self::STACKIQ_OWNED, $hashed);
		$this->assertSame([], array_intersect($hashed, self::SOURCE_OWNED));

		$this->assertSame('outbound', $nodes['map']['config']['ownership']);
		$this->assertSame('usage.recordId', $nodes['map']['config']['exists']);
		$this->assertSame('/api/now/table/cmdb_ci_appl', $nodes['call-create']['config']['endpoint']);
		$this->assertSame('{{ response.body.result.sys_id }}', $nodes['link-back']['config']['fields']['serviceDeskRecordId']);
		$this->assertStringStartsWith('https://desk.example.nl/nav_to.do', $nodes['link-back']['config']['fields']['serviceDeskUrl']);
		$this->assertSame(['sysparm_input_display_value' => 'true'], $nodes['call-create']['config']['query'], 'a list placeholder is filled with the list');
		$this->assertSame('send', $nodes['call-create']['config']['bodyFrom']);

		$topdesk = $this->nodes(flow: $this->flows(desk: 'topdesk')['outbound']);
		$this->assertSame('tpl-application', $topdesk['build']['config']['set']['usage._desk.templateId']);
		$this->assertSame('POST', $topdesk['call-update']['config']['method'], 'TOPdesk updates an asset with POST');
		$this->assertSame('{{ response.body.data.id }}', $topdesk['link-back']['config']['fields']['serviceDeskRecordId']);
	}//end testTheExportHashesOnlyStackiqOwnedFields()

	/**
	 * The imports run on a schedule, the export on usage changes, the file import by hand.
	 *
	 * @return void
	 */
	public function testEachFlowStartsTheWayTheDesignSays(): void {
		$flows = $this->flows(desk: 'topdesk');
		foreach (['applications', 'relations', 'licences', 'contracts'] as $key) {
			$this->assertSame('schedule', $flows[$key]['trigger'], $key);
			$this->assertSame(ItsmExchangeService::CRON, $flows[$key]['cron'], $key);
			$start = $this->nodes(flow: $flows[$key])['start'];
			$this->assertSame('openregister.trigger-schedule', $start['type']);
			$this->assertSame('admin', $start['config']['runAs']);
			$this->assertSame('itsm-topdesk-' . $key, $this->nodes(flow: $flows[$key])['decide']['config']['synchronization']);
		}

		$relations = $this->nodes(flow: $flows['relations']);
		$this->assertArrayNotHasKey('pages', $relations, 'TOPdesk has no list of all links, so relations are read per application');
		$this->assertSame('/assetmgmt/assetLinks', $relations['links']['config']['endpoint']);
		$this->assertSame(['sourceId' => '{{ serviceDeskRecordId }}'], $relations['links']['config']['query']);
		$this->assertSame(['serviceDeskSystem' => 'topdesk'], $relations['usages']['config']['filters']);
		$this->assertSame('itsm-servicenow-relations', $this->nodes(flow: $this->flows(desk: 'servicenow')['relations'])['pages']['config']['synchronization']);
		foreach (['applications', 'licences', 'contracts', 'file'] as $key) {
			$this->assertSame('https://desk.example.nl', $this->nodes(flow: $flows[$key])['desk']['config']['set']['source._desk.baseUrl'], $key . ' hands the preset the tenant base');
		}

		$this->assertSame('Licence', $this->nodes(flow: $flows['licences'])['flags']['config']['compute']['contractType']['or'][1]);
		$this->assertSame('itsm-topdesk-licence-inbound', $this->nodes(flow: $flows['licences'])['map-all']['config']['mapping']);

		$events = [];
		foreach ($flows['outbound']['nodes'] as $node) {
			if ($node['type'] === 'openregister.trigger-object') {
				$events[] = $node['config']['event'];
				$this->assertSame('usage', $node['config']['schema']);
			}
		}

		$this->assertSame(['object.created', 'object.updated'], $events);
		$this->assertSame('openregister.trigger-manual', $this->nodes(flow: $flows['file'])['start']['type']);
		$this->assertSame('itsm-file-application-inbound', $this->nodes(flow: $flows['file'])['map-all']['config']['mapping']);
		$this->assertSame('file', $this->nodes(flow: $flows['file'])['usage-create']['config']['fields']['serviceDeskSystem']);
	}//end testEachFlowStartsTheWayTheDesignSays()
}//end class
