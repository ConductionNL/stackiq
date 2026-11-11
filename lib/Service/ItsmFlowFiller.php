<?php

/**
 * Fills the exchange's flow templates with the values of one set-up.
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

/**
 * The placeholder values of each exchange flow, and the filling of a template with them.
 *
 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
 */
final class ItsmFlowFiller {
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
	public function fill(mixed $value, array $values): mixed {
		if (is_array($value) === true) {
			return array_map(fn (mixed $item): mixed => $this->fill(value: $item, values: $values), $value);
		}

		if (is_string($value) === false) {
			return $value;
		}

		return $this->fillText(text: $value, values: $values);
	}//end fill()

	/**
	 * Fill the placeholders of one string.
	 *
	 * @param string               $text   The string.
	 * @param array<string, mixed> $values The placeholder values, by key without the percent signs.
	 *
	 * @return mixed The value itself for a string that is exactly one known placeholder, else the filled text.
	 */
	private function fillText(string $text, array $values): mixed {
		if (preg_match('/^%([A-Z_]+)%$/', $text, $whole) === 1 && array_key_exists($whole[1], $values) === true) {
			return $values[$whole[1]];
		}

		[$search, $replace] = $this->textReplacements(values: $values);

		return str_replace($search, $replace, $text);
	}//end fillText()

	/**
	 * The placeholders that take text, and their texts; list and object values are left out.
	 *
	 * @param array<string, mixed> $values The placeholder values, by key without the percent signs.
	 *
	 * @return array{0: array<int, string>, 1: array<int, string>} The placeholders and their texts.
	 */
	private function textReplacements(array $values): array {
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
	 * The template file of one flow: the desk's own for the relations, the flow's for every other.
	 *
	 * @param array<string, mixed> $exchange What every flow of the set-up shares.
	 * @param array<string, mixed> $flow     The flow, with its key.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
	 */
	public function templateFile(array $exchange, array $flow): string {
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
	 *
	 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
	 */
	public function flowValues(array $exchange, array $flow): array {
		$flow['deskKey'] = $this->deskKeyOf(desk: $exchange['request']->desk, flowKey: $flow['key']);
		$values = $this->runValues(exchange: $exchange, flow: $flow) + $this->feedValues(exchange: $exchange, flow: $flow);
		$values += $this->deskEndpoints(exchange: $exchange);
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
	private function deskKeyOf(string $desk, string $flowKey): string {
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
	private function runValues(array $exchange, array $flow): array {
		$request = $exchange['request'];

		return [
			'REGISTER'   => ItsmExchangeService::REGISTER,
			'CONSUMER'   => $request->organisation,
			'RUN_AS'     => $request->runAs,
			'CRON'       => ItsmExchangeService::CRON,
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
	private function feedValues(array $exchange, array $flow): array {
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
	private function deskEndpoints(array $exchange): array {
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
}//end class
