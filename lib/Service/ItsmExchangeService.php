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
use OCA\Stackiq\AppInfo\Application;
use OCA\Stackiq\Service\Itsm\ItsmFlowGateway;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fills, checks and creates the service desk exchange flows.
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
		'licences'     => ['template' => 'itsm-inbound-contracts.json', 'feed' => 'licences', 'preset' => 'licence-inbound', 'contractType' => 'Licence', 'feedLabel' => 'licences'],
		'contracts'    => ['template' => 'itsm-inbound-contracts.json', 'feed' => 'contracts', 'preset' => 'contract-inbound', 'contractType' => 'SLA', 'feedLabel' => 'contracts'],
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
			'enabled'      => $this->appConfig->getValueBool(Application::APP_ID, self::ENABLED_KEY, false),
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
	 * @param string $desk         The desk key (topdesk, servicenow).
	 * @param string $organisation The uuid of the organisation whose applications are exchanged.
	 * @param string $runAs        The user the scheduled imports run as.
	 * @param string $location     The desk source's location; its scheme and host make the record links.
	 * @param string $templateId   The desk's asset template for a new record (TOPdesk needs one), or empty.
	 *
	 * @return array<string, array<string, mixed>> The filled flow documents, by flow key.
	 *
	 * @throws InvalidArgumentException When the desk is unknown or a template is missing.
	 *
	 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
	 */
	public function buildFlows(string $desk, string $organisation, string $runAs, string $location, string $templateId = ''): array {
		if (isset(self::DESKS[$desk]) === false) {
			throw new InvalidArgumentException('Unknown service desk "' . $desk . '". Choose one of: ' . implode(', ', array_keys(self::DESKS)) . '.');
		}

		$profile = self::DESKS[$desk];
		$appUrl  = rtrim($this->urlGenerator->getAbsoluteURL('/index.php/apps/' . Application::APP_ID), '/');
		$base    = self::baseOf(location: $location);
		$flows   = [];
		foreach (self::FLOWS as $key => $flow) {
			$deskKey = $desk;
			if ($key === 'file') {
				$deskKey = 'file';
			}

			$values = [
				'REGISTER'        => self::REGISTER,
				'CONSUMER'        => $organisation,
				'RUN_AS'          => $runAs,
				'CRON'            => self::CRON,
				'DESK'            => $deskKey,
				'DESK_LABEL'      => $profile['label'],
				'SOURCE'          => $profile['source'],
				'SYNC'            => 'itsm-' . $deskKey . '-' . $flow['feed'],
				'PRESET'          => 'itsm-' . $deskKey . '-' . $flow['preset'],
				'CONTRACT_TYPE'   => ($flow['contractType'] ?? 'Licence'),
				'FEED_LABEL'      => ($flow['feedLabel'] ?? $flow['feed']),
				'APP_URL'         => $appUrl,
				'CREATE_ENDPOINT' => $profile['createEndpoint'],
				'CREATE_METHOD'   => $profile['createMethod'],
				'UPDATE_ENDPOINT' => $profile['updateEndpoint'],
				'UPDATE_METHOD'   => $profile['updateMethod'],
				'RESPONSE_ID'     => $profile['responseId'],
				'RECORD_URL'      => str_replace('%BASE%', $base, $profile['recordUrl']),
				'DESK_BASE'       => $base,
				'TEMPLATE_ID'     => $templateId,
				'CALL_QUERY'      => $profile['callQuery'],
			];
			if ($key === 'file') {
				$values['SYNC']   = 'itsm-file-applications';
				$values['PRESET'] = 'itsm-file-application-inbound';
			}

			$template = $flow['template'];
			if ($key === 'relations') {
				$template = $profile['relationsTemplate'];
			}

			$flows[$key] = self::fill(value: $this->template(file: $template), values: $values);
		}//end foreach

		return $flows;
	}//end buildFlows()

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
		if ($this->gateway->available() === false) {
			return $this->refuse(message: 'OpenRegister\'s flow engine is not available, so no exchange can be set up.');
		}

		if (isset(self::DESKS[$desk]) === false) {
			return $this->refuse(message: 'Unknown service desk "' . $desk . '".');
		}

		if ($this->gateway->findObject(register: self::REGISTER, schema: 'organization', id: $organisation) === null) {
			return $this->refuse(message: 'The organisation ' . $organisation . ' does not exist in stackiq.');
		}

		$source = $this->gateway->findObject(register: 'integriq', schema: 'source', id: self::DESKS[$desk]['source']);
		if ($source === null) {
			return $this->refuse(message: 'Integriq has no source "' . self::DESKS[$desk]['source'] . '". Add the ' . self::DESKS[$desk]['label'] . ' source in integriq first.');
		}

		$flows    = $this->buildFlows(desk: $desk, organisation: $organisation, runAs: $runAs, location: (string) ($source['location'] ?? ''), templateId: $templateId);
		$blocking = [];
		foreach ($flows as $key => $flow) {
			$findings = $this->gateway->inspect(flow: $flow);
			if ($findings['blocking'] !== []) {
				$blocking[$key] = $findings['blocking'];
			}
		}

		if ($blocking !== []) {
			$first = (array) reset($blocking);
			$entry = (array) ($first[0] ?? []);
			return $this->refuse(
				message: 'Nothing was created. OpenRegister refused flow "' . (string) array_key_first($blocking) . '": step '
					. (string) ($entry['step'] ?? '?') . ', ' . (string) ($entry['reason'] ?? 'unknown reason') . '.',
				blocking: $blocking
			);
		}

		$stored = (array) ($this->config()['flows'] ?? []);
		$saved  = [];
		try {
			foreach ($flows as $key => $flow) {
				$previous    = $stored[$key] ?? null;
				$saved[$key] = $this->gateway->saveAndPublish(flow: $flow, uuid: is_string($previous) === true ? $previous : null);
			}
		} catch (Throwable $e) {
			$this->logger->error('[ItsmExchangeService] Saving the exchange flows failed', ['exception' => $e]);
			$this->storeConfig(desk: $desk, organisation: $organisation, flows: array_merge($stored, $saved));
			return $this->refuse(message: 'Saving the flows failed after ' . count($saved) . ' of ' . count($flows) . ': ' . $e->getMessage());
		}

		$this->storeConfig(desk: $desk, organisation: $organisation, flows: $saved);
		$this->appConfig->setValueBool(Application::APP_ID, self::ENABLED_KEY, true);
		$this->connectionReports?->itsmSetUp(created: true, message: count($saved) . ' flows set up for ' . self::DESKS[$desk]['label'] . '. The first import runs tonight.');

		return ['created' => true, 'desk' => $desk, 'flows' => $saved];
	}//end setUp()

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
			$filled = [];
			foreach ($value as $key => $item) {
				$filled[$key] = self::fill(value: $item, values: $values);
			}

			return $filled;
		}

		if (is_string($value) === false) {
			return $value;
		}

		if (preg_match('/^%([A-Z_]+)%$/', $value, $whole) === 1 && array_key_exists($whole[1], $values) === true) {
			return $values[$whole[1]];
		}

		$search  = [];
		$replace = [];
		foreach ($values as $key => $text) {
			if (is_array($text) === true) {
				continue;
			}

			$search[]  = '%' . $key . '%';
			$replace[] = (string) $text;
		}

		return str_replace($search, $replace, $value);
	}//end fill()

	/**
	 * The scheme, host and port of a location, without its path.
	 *
	 * @param string $location The source's location.
	 *
	 * @return string The base, or an empty string when the location has no host.
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
		$dir     = ($this->templateDir ?? __DIR__ . '/../Settings/flows');
		$content = @file_get_contents($dir . '/' . $file);
		$decoded = json_decode((string) $content, true);
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
		$decoded = json_decode($this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, '{}'), true);
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
			Application::APP_ID,
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
