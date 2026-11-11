<?php

/**
 * Sets up the exchange between stackiq and the organisation's service desk.
 *
 * Stackiq ships flow templates (lib/Settings/flows/itsm-*.json). This service
 * fills them with what the administrator chose (the service desk, the
 * organisation whose applications are exchanged) and with the integriq slugs
 * that belong to that desk (its source, its synchronizations, its mapping
 * presets), checks every flow with OpenRegister's preflight, and only when
 * all pass saves, publishes and switches them on. It holds no credential and
 * calls no service desk: every outside call is an integriq node inside a flow.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use InvalidArgumentException;
use OCA\Stackiq\AppInfo\AppIdentity;
use OCA\Stackiq\Service\Itsm\ItsmFlowGateway;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fills, checks and creates the service desk exchange flows.
 *
 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
 */
class ItsmExchangeService {

	/**
	 * App setting that holds the desk, the organisation and the flow uuids.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'itsm_exchange';

	/**
	 * App setting the Integrations page reads as the exchange's switch.
	 *
	 * @var string
	 */
	public const ENABLED_KEY = 'itsm_exchange_enabled';

	/**
	 * The register every stackiq flow writes to.
	 *
	 * @var string
	 */
	public const REGISTER = 'stackiq';

	/**
	 * The nightly import time, as a five-field cron expression.
	 *
	 * @var string
	 */
	public const CRON = '0 2 * * *';

	/**
	 * One profile per service desk: integriq's slugs and the outbound endpoints.
	 *
	 * The slugs, endpoints and answer paths are the ones integriq's change
	 * connectors-service-desk-templates ships and its mocks answer. Endpoints
	 * are relative to the source's location; `%BASE%` becomes the tenant's
	 * scheme and host, for record links. TOPdesk lists asset links one asset
	 * at a time, so its relations use the per-application template.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public const DESKS = [
		'topdesk'    => [
			'label'             => 'TOPdesk',
			'source'            => 'topdesk',
			'relationsTemplate' => 'itsm-inbound-relations-per-application.json',
			'createEndpoint'    => '/assetmgmt/assets',
			'createMethod'      => 'POST',
			'updateEndpoint'    => '/assetmgmt/assets/{{ usage.recordId }}',
			'updateMethod'      => 'POST',
			'callQuery'         => [],
			'responseId'        => 'data.id',
			'recordUrl'         => '%BASE%/tas/secure/assetmgmt/card.html?unid={{ response.body.data.id }}',
		],
		'servicenow' => [
			'label'             => 'ServiceNow',
			'source'            => 'servicenow',
			'relationsTemplate' => 'itsm-inbound-relations.json',
			'createEndpoint'    => '/api/now/table/cmdb_ci_appl',
			'createMethod'      => 'POST',
			'updateEndpoint'    => '/api/now/table/cmdb_ci_appl/{{ usage.recordId }}',
			'updateMethod'      => 'PATCH',
			'callQuery'         => ['sysparm_input_display_value' => 'true'],
			'responseId'        => 'result.sys_id',
			'recordUrl'         => '%BASE%/nav_to.do?uri=cmdb_ci_appl.do?sys_id={{ response.body.result.sys_id }}',
		],
	];

	/**
	 * The flows the set-up creates: key => template file, feed and preset suffix.
	 *
	 * @var array<string, array{template: string, feed: string, preset: string, contractType?: string, feedLabel?: string}>
	 */
	public const FLOWS = [
		'applications' => ['template' => 'itsm-inbound-applications.json', 'feed' => 'applications', 'preset' => 'application-inbound'],
		'relations'    => ['template' => 'itsm-inbound-relations.json', 'feed' => 'relations', 'preset' => 'relation-inbound'],
		'licences'     => [
			'template'     => 'itsm-inbound-contracts.json',
			'feed'         => 'licences',
			'preset'       => 'licence-inbound',
			'contractType' => 'Licence',
			'feedLabel'    => 'licences',
		],
		'contracts'    => [
			'template'     => 'itsm-inbound-contracts.json',
			'feed'         => 'contracts',
			'preset'       => 'contract-inbound',
			'contractType' => 'SLA',
			'feedLabel'    => 'contracts',
		],
		'outbound'     => ['template' => 'itsm-outbound-applications.json', 'feed' => 'outbound', 'preset' => 'application-outbound'],
		'file'         => ['template' => 'itsm-file-applications.json', 'feed' => 'file', 'preset' => 'file'],
	];

	/**
	 * Constructor.
	 *
	 * @param ItsmFlowGateway              $gateway           OpenRegister's flow store.
	 * @param IAppConfig                   $appConfig         The app settings.
	 * @param IURLGenerator                $urlGenerator      Builds the catalogue link sent to the desk.
	 * @param LoggerInterface              $logger            The logger.
	 * @param ConnectionReportService|null $connectionReports Tells integriq what the set-up met.
	 * @param string|null                  $templateDir       Where the flow templates live; tests point it elsewhere.
	 */
	public function __construct(
		private readonly ItsmFlowGateway $gateway,
		private readonly IAppConfig $appConfig,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
		private readonly ?ConnectionReportService $connectionReports = null,
		private readonly ?string $templateDir = null,
	) {
	}//end __construct()

	/**
	 * What is set up today.
	 *
	 * @return array<string, mixed> The desk, the organisation, the flows and the desks on offer.
	 *
	 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-007-the-cmdb-page-says-what-stackiq-is
	 */
	public function status(): array {
		$config = $this->config();
		$desks  = [];
		foreach (self::DESKS as $key => $desk) {
			$desks[] = ['id' => $key, 'label' => $desk['label']];
		}

		return [
			'available'    => $this->gateway->available(),
			'enabled'      => $this->appConfig->getValueBool(AppIdentity::APP_ID, self::ENABLED_KEY, false),
			'desk'         => ($config['desk'] ?? null),
			'organisation' => ($config['organisation'] ?? null),
			'flows'        => ($config['flows'] ?? []),
			'setUpAt'      => ($config['setUpAt'] ?? null),
			'desks'        => $desks,
		];
	}//end status()

	/**
	 * Fill every template for one desk and one organisation.
	 *
	 * @param ItsmFlowRequest $request The desk, the organisation's uuid, the run-as user, the desk source's
	 *                                 location and the asset template.
	 *
	 * @return array<string, array<string, mixed>> The filled flow documents, by flow key.
	 *
	 * @throws InvalidArgumentException When the desk is unknown or a template is missing.
	 *
	 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
	 */
	public function buildFlows(ItsmFlowRequest $request): array {
		if (isset(self::DESKS[$request->desk]) === false) {
			throw new InvalidArgumentException(
				'Unknown service desk "' . $request->desk . '". Choose one of: ' . implode(', ', array_keys(self::DESKS)) . '.'
			);
		}

		$exchange = $this->exchangeOf(request: $request);
		$flows    = [];
		foreach (self::FLOWS as $key => $flow) {
			$flow['key']  = $key;
			$template     = $this->template(file: self::templateFile(exchange: $exchange, flow: $flow));
			$flows[$key] = self::fill(value: $template, values: self::flowValues(exchange: $exchange, flow: $flow));
		}

		return $flows;
	}//end buildFlows()

	/**
	 * What every flow of one set-up shares: the request, the desk's profile, the catalogue link and the desk base.
	 *
	 * @param ItsmFlowRequest $request The set-up, for a known desk.
	 *
	 * @return array<string, mixed> The request, the desk's profile, the catalogue link and the desk base.
	 */
	private function exchangeOf(ItsmFlowRequest $request): array {
		return [
			'request' => $request,
			'profile' => self::DESKS[$request->desk],
			'appUrl'  => rtrim($this->urlGenerator->getAbsoluteURL('/index.php/apps/' . AppIdentity::APP_ID), '/'),
			'base'    => self::baseOf(location: $request->location),
		];
	}//end exchangeOf()

	/**
	 * The template file of one flow: the desk's own for the relations, the flow's for every other.
	 *
	 * @param array<string, mixed> $exchange What every flow of the set-up shares.
	 * @param array<string, mixed> $flow     The flow, with its key.
	 *
	 * @return string
	 */
	private static function templateFile(array $exchange, array $flow): string {
		if ($flow['key'] === 'relations') {
			return $exchange['profile']['relationsTemplate'];
		}

		return $flow['template'];
	}//end templateFile()

	/**
	 * The placeholder values of one flow, in the order they are filled.
	 *
	 * @param array<string, mixed> $exchange What every flow of the set-up shares.
	 * @param array<string, mixed> $flow     The flow, with its key.
	 *
	 * @return array<string, mixed>
	 */
	private static function flowValues(array $exchange, array $flow): array {
		$flow['deskKey'] = self::deskKeyOf(desk: $exchange['request']->desk, flowKey: $flow['key']);
		$values = self::runValues(exchange: $exchange, flow: $flow) + self::feedValues(exchange: $exchange, flow: $flow);
		$values += self::deskEndpoints(exchange: $exchange);
		if ($flow['key'] === 'file') {
			$values['SYNC']   = 'itsm-file-applications';
			$values['PRESET'] = 'itsm-file-application-inbound';
		}

		return $values;
	}//end flowValues()

	/**
	 * The desk key a flow names: `file` for the file flow, the desk for every other.
	 *
	 * @param string $desk    The desk key.
	 * @param string $flowKey The flow key.
	 *
	 * @return string
	 */
	private static function deskKeyOf(string $desk, string $flowKey): string {
		if ($flowKey === 'file') {
			return 'file';
		}

		return $desk;
	}//end deskKeyOf()

	/**
	 * Who and what the flow runs for: the register, the consumer, the run-as user, the schedule and the desk.
	 *
	 * @param array<string, mixed> $exchange What every flow of the set-up shares.
	 * @param array<string, mixed> $flow     The flow, with its desk key.
	 *
	 * @return array<string, mixed>
	 */
	private static function runValues(array $exchange, array $flow): array {
		$request = $exchange['request'];

		return [
			'REGISTER'   => self::REGISTER,
			'CONSUMER'   => $request->organisation,
			'RUN_AS'     => $request->runAs,
			'CRON'       => self::CRON,
			'DESK'       => $flow['deskKey'],
			'DESK_LABEL' => $exchange['profile']['label'],
			'SOURCE'     => $exchange['profile']['source'],
		];
	}//end runValues()

	/**
	 * What the flow exchanges: its synchronization, preset, contract type and feed label, and the catalogue link.
	 *
	 * @param array<string, mixed> $exchange What every flow of the set-up shares.
	 * @param array<string, mixed> $flow     The flow, with its desk key.
	 *
	 * @return array<string, mixed>
	 */
	private static function feedValues(array $exchange, array $flow): array {
		return [
			'SYNC'          => 'itsm-' . $flow['deskKey'] . '-' . $flow['feed'],
			'PRESET'        => 'itsm-' . $flow['deskKey'] . '-' . $flow['preset'],
			'CONTRACT_TYPE' => ($flow['contractType'] ?? 'Licence'),
			'FEED_LABEL'    => ($flow['feedLabel'] ?? $flow['feed']),
			'APP_URL'       => $exchange['appUrl'],
		];
	}//end feedValues()

	/**
	 * How the flow talks to the desk: its endpoints and methods, the record link and base, the asset template.
	 *
	 * @param array<string, mixed> $exchange What every flow of the set-up shares.
	 *
	 * @return array<string, mixed>
	 */
	private static function deskEndpoints(array $exchange): array {
		$profile = $exchange['profile'];

		return [
			'CREATE_ENDPOINT' => $profile['createEndpoint'],
			'CREATE_METHOD'   => $profile['createMethod'],
			'UPDATE_ENDPOINT' => $profile['updateEndpoint'],
			'UPDATE_METHOD'   => $profile['updateMethod'],
			'RESPONSE_ID'     => $profile['responseId'],
			'RECORD_URL'      => str_replace('%BASE%', $exchange['base'], $profile['recordUrl']),
			'DESK_BASE'       => $exchange['base'],
			'TEMPLATE_ID'     => $exchange['request']->templateId,
			'CALL_QUERY'      => $profile['callQuery'],
		];
	}//end deskEndpoints()

	/**
	 * Set up the exchange: check every flow, then create or update them all.
	 *
	 * Nothing is saved unless every flow passes preflight, so the instance never
	 * holds half an exchange. Running it again updates the flows it created.
	 *
	 * @param string $desk         The desk key.
	 * @param string $organisation The uuid of the organisation whose applications are exchanged.
	 * @param string $runAs        The user the scheduled imports run as.
	 * @param string $templateId   The desk's asset template for new records, when the desk needs one.
	 *
	 * @return array<string, mixed> `created`, and either `flows` or `blocking` per flow key.
	 *
	 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
	 */
	public function setUp(string $desk, string $organisation, string $runAs, string $templateId = ''): array {
		return $this->setUpExchange(
			request: new ItsmFlowRequest(desk: $desk, organisation: $organisation, runAs: $runAs, location: '', templateId: $templateId)
		);
	}//end setUp()

	/**
	 * Set up the exchange for one request: check every flow, then create or update them all.
	 *
	 * @param ItsmFlowRequest $request The desk, the organisation as given, the run-as user and the asset template.
	 *
	 * @return array<string, mixed> `created`, and either `flows` or `blocking` per flow key.
	 */
	private function setUpExchange(ItsmFlowRequest $request): array {
		$found = $this->preconditions(desk: $request->desk, organisation: $request->organisation);
		if (is_string($found) === true) {
			return $this->refuse(message: $found);
		}

		// The flows compare this with a usage's consumer uuid, so store the uuid
		// even when the admin gave an id or a slug.
		$location = (string) ($found['source']['location'] ?? '');
		$request  = $request->withSource(organisation: $found['organisation'], location: $location);
		$checked = $this->checkedFlows(request: $request);
		if (isset($checked['refusal']) === true) {
			return $checked['refusal'];
		}

		return $this->publishFlows(request: $request, flows: $checked['flows']);
	}//end setUpExchange()

	/**
	 * Build every flow and preflight it.
	 *
	 * @param ItsmFlowRequest $request The set-up, with the organisation's uuid and the desk source's location.
	 *
	 * @return array<string, array<string, mixed>> The flows (`flows`), or the refusal (`refusal`) when one cannot be
	 *                                             checked or OpenRegister refuses one.
	 */
	private function checkedFlows(ItsmFlowRequest $request): array {
		try {
			$flows = $this->buildFlows(request: $request);

			$blocking = $this->blockingFindings(flows: $flows);
		} catch (Throwable $e) {
			$this->logger->error('[ItsmExchangeService] Checking the exchange flows failed', ['exception' => $e]);
			return ['refusal' => $this->refuse(message: 'Nothing was created. The flows could not be checked: ' . $e->getMessage())];
		}

		if ($blocking !== []) {
			return ['refusal' => $this->blockedRefusal(blocking: $blocking)];
		}

		return ['flows' => $flows];
	}//end checkedFlows()

	/**
	 * The refusal naming the first flow OpenRegister refused, its step and its reason.
	 *
	 * @param array<string, mixed> $blocking The blocking findings by flow key.
	 *
	 * @return array<string, mixed>
	 */
	private function blockedRefusal(array $blocking): array {
		$first = (array) reset($blocking);
		$entry = (array) ($first[0] ?? []);
		return $this->refuse(
			message: 'Nothing was created. OpenRegister refused flow "' . (string) array_key_first($blocking) . '": step '
				. (string) ($entry['step'] ?? '?') . ', ' . (string) ($entry['reason'] ?? 'unknown reason') . '.',
			blocking: $blocking
		);
	}//end blockedRefusal()

	/**
	 * Save and publish every checked flow, updating the ones an earlier set-up created; a failure keeps what was saved.
	 *
	 * @param ItsmFlowRequest $request The set-up.
	 * @param array<string, array<string, mixed>> $flows The checked flows by key.
	 *
	 * @return array<string, mixed>
	 */
	private function publishFlows(ItsmFlowRequest $request, array $flows): array {
		$stored = (array) ($this->config()['flows'] ?? []);
		$saved  = [];
		try {
			foreach ($flows as $key => $flow) {
				$saved[$key] = $this->gateway->saveAndPublish(flow: $flow, uuid: $this->storedUuid(stored: $stored, key: $key));
			}
		} catch (Throwable $e) {
			$this->logger->error('[ItsmExchangeService] Saving the exchange flows failed', ['exception' => $e]);
			$this->storeConfig(desk: $request->desk, organisation: $request->organisation, flows: array_merge($stored, $saved));
			return $this->refuse(message: 'Saving the flows failed after ' . count($saved) . ' of ' . count($flows) . ': ' . $e->getMessage());
		}

		return $this->enableExchange(request: $request, saved: $saved);
	}//end publishFlows()

	/**
	 * Store the saved flows, switch the exchange on and tell integriq.
	 *
	 * @param ItsmFlowRequest $request The set-up.
	 * @param array<string, string> $saved The saved flow uuids by key.
	 *
	 * @return array<string, mixed>
	 */
	private function enableExchange(ItsmFlowRequest $request, array $saved): array {
		$this->storeConfig(desk: $request->desk, organisation: $request->organisation, flows: $saved);
		$this->appConfig->setValueBool(AppIdentity::APP_ID, self::ENABLED_KEY, true);
		$this->connectionReports?->itsmSetUp(
			created: true,
			message: count($saved) . ' flows set up for ' . self::DESKS[$request->desk]['label'] . '. The first import runs tonight.'
		);

		return ['created' => true, 'desk' => $request->desk, 'flows' => $saved];
	}//end enableExchange()

	/**
	 * What must be there before any flow is built: the engine, the desk, the organisation and integriq's source.
	 *
	 * @param string $desk         The desk key.
	 * @param string $organisation The organisation uuid.
	 *
	 * @return array{source: array<string, mixed>, organisation: string}|string The integriq source and the
	 *                                                                          organisation's uuid, or why the
	 *                                                                          set-up cannot start.
	 */
	private function preconditions(string $desk, string $organisation): array|string {
		if ($this->gateway->available() === false) {
			return 'OpenRegister\'s flow engine is not available, so no exchange can be set up.';
		}

		if (isset(self::DESKS[$desk]) === false) {
			return 'Unknown service desk "' . $desk . '".';
		}

		$found = $this->gateway->findObject(register: self::REGISTER, schema: 'organization', id: $organisation);
		if ($found === null || (string) ($found['uuid'] ?? '') === '') {
			return 'The organisation ' . $organisation . ' does not exist in stackiq.';
		}

		$profile = self::DESKS[$desk];
		$source  = $this->gateway->findObject(register: 'integriq', schema: 'source', id: $profile['source']);
		if ($source === null) {
			return 'Integriq has no source "' . $profile['source'] . '". Add the ' . $profile['label'] . ' source in integriq first.';
		}

		return ['source' => $source, 'organisation' => (string) $found['uuid']];
	}//end preconditions()

	/**
	 * Preflight every flow and keep the blocking findings.
	 *
	 * @param array<string, array<string, mixed>> $flows The filled flows by key.
	 *
	 * @return array<string, list<array<string, mixed>>> The blocking findings by flow key; empty when all pass.
	 */
	private function blockingFindings(array $flows): array {
		$blocking = [];
		foreach ($flows as $key => $flow) {
			$findings = $this->gateway->inspect(flow: $flow);
			if ($findings['blocking'] !== []) {
				$blocking[$key] = $findings['blocking'];
			}
		}

		return $blocking;
	}//end blockingFindings()

	/**
	 * The uuid a flow was stored under by an earlier set-up, or null.
	 *
	 * @param array<string, mixed> $stored The stored flow uuids.
	 * @param string               $key    The flow key.
	 *
	 * @return string|null The uuid, or null when there is none.
	 */
	private function storedUuid(array $stored, string $key): ?string {
		$previous = ($stored[$key] ?? null);
		if (is_string($previous) === false || $previous === '') {
			return null;
		}

		return $previous;
	}//end storedUuid()

	/**
	 * Replace every `%KEY%` placeholder in the strings of a value.
	 *
	 * A string that is exactly one placeholder takes the value as it is, so a
	 * list or an object can be filled in; any other string gets text.
	 *
	 * @param mixed                $value  The template, or a part of it.
	 * @param array<string, mixed> $values The placeholder values, by key without the percent signs.
	 *
	 * @return mixed The filled value.
	 *
	 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
	 */
	public static function fill(mixed $value, array $values): mixed {
		if (is_array($value) === true) {
			return array_map(static fn (mixed $item): mixed => self::fill(value: $item, values: $values), $value);
		}

		if (is_string($value) === false) {
			return $value;
		}

		return self::fillText(text: $value, values: $values);
	}//end fill()

	/**
	 * Fill the placeholders of one string.
	 *
	 * @param string               $text   The string.
	 * @param array<string, mixed> $values The placeholder values, by key without the percent signs.
	 *
	 * @return mixed The value itself for a string that is exactly one known placeholder, else the filled text.
	 */
	private static function fillText(string $text, array $values): mixed {
		if (preg_match('/^%([A-Z_]+)%$/', $text, $whole) === 1 && array_key_exists($whole[1], $values) === true) {
			return $values[$whole[1]];
		}

		[$search, $replace] = self::textReplacements(values: $values);

		return str_replace($search, $replace, $text);
	}//end fillText()

	/**
	 * The placeholders that take text, and their texts; list and object values are left out.
	 *
	 * @param array<string, mixed> $values The placeholder values, by key without the percent signs.
	 *
	 * @return array{0: array<int, string>, 1: array<int, string>} The placeholders and their texts.
	 */
	private static function textReplacements(array $values): array {
		$search  = [];
		$replace = [];
		foreach ($values as $key => $text) {
			if (is_array($text) === false) {
				$search[]  = '%' . $key . '%';
				$replace[] = (string) $text;
			}
		}

		return [$search, $replace];
	}//end textReplacements()

	/**
	 * The scheme, host and port of a location, without its path.
	 *
	 * @param string $location The source's location.
	 *
	 * @return string The base, or an empty string when the location has no host.
	 *
	 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
	 */
	public static function baseOf(string $location): string {
		$parts = parse_url(trim($location));
		if (is_array($parts) === false || isset($parts['host']) === false) {
			return '';
		}

		$base = ($parts['scheme'] ?? 'https') . '://' . $parts['host'];
		if (isset($parts['port']) === true) {
			$base .= ':' . $parts['port'];
		}

		return $base;
	}//end baseOf()

	/**
	 * Read one template.
	 *
	 * @param string $file The file name.
	 *
	 * @return array<string, mixed> The template.
	 *
	 * @throws InvalidArgumentException When it is missing or not JSON.
	 */
	private function template(string $file): array {
		$path    = ($this->templateDir ?? __DIR__ . '/../Settings/flows') . '/' . $file;
		$content = '';
		if (is_file($path) === true) {
			$content = (string) file_get_contents($path);
		}

		$decoded = json_decode($content, true);
		if (is_array($decoded) === false) {
			throw new InvalidArgumentException('The flow template ' . $file . ' is missing or not JSON.');
		}

		return $decoded;
	}//end template()

	/**
	 * The stored set-up.
	 *
	 * @return array<string, mixed> The stored set-up, or an empty array.
	 */
	private function config(): array {
		$decoded = json_decode($this->appConfig->getValueString(AppIdentity::APP_ID, self::CONFIG_KEY, '{}'), true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end config()

	/**
	 * Store the set-up.
	 *
	 * @param string                $desk         The desk key.
	 * @param string                $organisation The organisation uuid.
	 * @param array<string, string> $flows        The flow uuids by key.
	 *
	 * @return void
	 */
	private function storeConfig(string $desk, string $organisation, array $flows): void {
		$this->appConfig->setValueString(
			AppIdentity::APP_ID,
			self::CONFIG_KEY,
			(string) json_encode(['desk' => $desk, 'organisation' => $organisation, 'flows' => $flows, 'setUpAt' => date(DATE_ATOM)])
		);
	}//end storeConfig()

	/**
	 * A refused set-up, reported to the Integrations page.
	 *
	 * @param string               $message  What stopped it.
	 * @param array<string, mixed> $blocking The preflight findings, by flow key.
	 *
	 * @return array<string, mixed> The answer.
	 */
	private function refuse(string $message, array $blocking = []): array {
		$this->connectionReports?->itsmSetUp(created: false, message: $message);

		return ['created' => false, 'message' => $message, 'blocking' => $blocking];
	}//end refuse()
}//end class
