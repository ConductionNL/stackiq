<?php

/**
 * AskService
 *
 * Answers a question about the catalogue: one search of the catalogue as the
 * caller, then one grounded turn of hermiq's converse with what was found.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/insight-assistant-answers-and-action-trail/specs/assistant-insight/spec.md#requirement-req-ask-001-an-answer-lists-the-catalogue-entries-it-used
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCA\Stackiq\Exception\AskUnavailableException;
use OCA\Stackiq\Kernel\AppIdentity;
use OCA\Stackiq\Kernel\Contracts\AskServiceInterface;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCP\IURLGenerator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Search first, then converse (design D1).
 *
 * The search runs as the caller, so OpenRegister's RBAC and organisation
 * scoping decide what the assistant may see. Nothing is written, and the
 * assistant is never handed a tool: it reads only the entries passed to it.
 *
 * @spec openspec/changes/insight-assistant-answers-and-action-trail/specs/assistant-insight/spec.md#requirement-req-ask-001-an-answer-lists-the-catalogue-entries-it-used
 */
class AskService implements AskServiceInterface {

	/**
	 * The most entries one answer is grounded on.
	 */
	public const MAX_ENTRIES = 20;

	/**
	 * The hermiq contract resolved at runtime; absent when hermiq is not installed or enabled.
	 */
	public const HERMIQ_ASSISTANT = 'OCA\Hermiq\Kernel\Contracts\AssistantServiceInterface';

	/**
	 * The schemas searched, each with the fields its list page shows and its detail route.
	 *
	 * @var array<string, array{fields: list<string>, route: string}>
	 */
	private const SCHEMAS = [
		'module' => ['fields' => ['name', 'status', 'description'], 'route' => '/modules/%s'],
		'catalogService' => ['fields' => ['name', 'description'], 'route' => '/diensten'],
		'organization' => ['fields' => ['name', 'type', 'status', 'website'], 'route' => '/organisaties/%s'],
		'usage' => ['fields' => ['name', 'description'], 'route' => ''],
		'connection' => ['fields' => ['name', 'description'], 'route' => ''],
		'compliancy' => ['fields' => ['module', 'standardVersion', 'standardGemma', 'url'], 'route' => '/komplianties/%s'],
		'catalogContract' => ['fields' => ['contractNumber', 'contractType', 'startDate', 'endDate', 'cost', 'status'], 'route' => '/contracten/%s'],
	];

	/**
	 * How the assistant is asked to cite, so the sources can be read back from the reply.
	 */
	private const CITE_INSTRUCTION = 'Answer from the catalogue entries in the context only, '
		. 'and cite each entry you use by its ref in square brackets, such as [E1].';

	/**
	 * The longest a summary field value is passed on.
	 */
	private const MAX_FIELD_LENGTH = 200;

	/**
	 * Constructor.
	 *
	 * @param ObjectTypeLocatorInterface    $locator      Resolves each schema's register and schema id.
	 * @param RegisterConfigReaderInterface $registers    Gives OpenRegister's object service.
	 * @param ContainerInterface            $container    Resolves hermiq at runtime.
	 * @param IURLGenerator                 $urlGenerator Builds the entries' addresses.
	 * @param LoggerInterface               $logger       Reports a failed search.
	 *
	 * @spec openspec/changes/insight-assistant-answers-and-action-trail/specs/assistant-insight/spec.md#requirement-req-ask-001-an-answer-lists-the-catalogue-entries-it-used
	 */
	public function __construct(
		private readonly ObjectTypeLocatorInterface $locator,
		private readonly RegisterConfigReaderInterface $registers,
		private readonly ContainerInterface $container,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Answer a question from the catalogue entries the caller may read.
	 *
	 * When nothing matches, the assistant is not asked and the reply is empty.
	 *
	 * @param string      $question  The question.
	 * @param string|null $sessionId An earlier conversation to continue, or null.
	 * @param string      $userId    The caller.
	 *
	 * @return array{sessionId: string|null, reply: string, sources: list<array<string, string>>, searched: list<array<string, string>>}
	 *
	 * @throws AskUnavailableException When hermiq is not available.
	 *
	 * @spec openspec/changes/insight-assistant-answers-and-action-trail/specs/assistant-insight/spec.md#requirement-req-ask-001-an-answer-lists-the-catalogue-entries-it-used
	 */
	public function ask(string $question, ?string $sessionId, string $userId): array {
		$assistant = $this->assistant();
		$searched  = $this->search(question: $question);
		if ($searched === []) {
			return ['sessionId' => $sessionId, 'reply' => '', 'sources' => [], 'searched' => []];
		}

		$turn = $assistant->converse(
			$userId,
			$sessionId,
			$question . "\n\n" . self::CITE_INSTRUCTION,
			['app' => AppIdentity::APP_ID, 'contextData' => $searched]
		);

		$reply = (string)($turn['reply'] ?? '');

		return [
			'sessionId' => (string)($turn['sessionId'] ?? $sessionId),
			'reply' => $reply,
			'sources' => $this->cited(reply: $reply, searched: $searched),
			'searched' => $searched,
		];
	}//end ask()

	/**
	 * Hermiq's assistant, or a refusal when it is not there.
	 *
	 * @return object The assistant service.
	 *
	 * @throws AskUnavailableException When hermiq is not installed or enabled.
	 */
	private function assistant(): object {
		try {
			$assistant = $this->container->get(self::HERMIQ_ASSISTANT);
		} catch (Throwable $e) {
			throw new AskUnavailableException(message: 'The assistant is not available.', previous: $e);
		}

		if (is_object($assistant) === false || method_exists($assistant, 'converse') === false) {
			throw new AskUnavailableException(message: 'The assistant is not available.');
		}

		return $assistant;
	}//end assistant()

	/**
	 * One search per schema, as the caller, until MAX_ENTRIES entries are found.
	 *
	 * @param string $question The question, used as the full-text search.
	 *
	 * @return list<array<string, string>> The entries, numbered E1 onwards.
	 */
	private function search(string $question): array {
		try {
			$objects = $this->registers->getObjectService();
		} catch (Throwable $e) {
			$this->logger->warning('AskService: OpenRegister is not available', ['error' => $e->getMessage()]);
			return [];
		}

		if ($objects === null) {
			return [];
		}

		$entries = [];
		foreach (self::SCHEMAS as $type => $shape) {
			$remaining = (self::MAX_ENTRIES - count($entries));
			if ($remaining <= 0) {
				break;
			}

			$registerId = $this->locator->getRegisterIdForObjectType(objectType: $type);
			$schemaId   = $this->locator->getSchemaIdForObjectType(objectType: $type);
			if ($registerId === null || $schemaId === null) {
				continue;
			}

			try {
				// As the caller: RBAC and organisation scoping stay on.
				$result = $objects->searchObjectsPaginated(
					query: ['@self' => ['register' => $registerId, 'schema' => $schemaId], '_search' => $question, '_limit' => $remaining],
					_rbac: true,
					_multitenancy: true
				);
			} catch (Throwable $e) {
				$this->logger->warning('AskService: catalogue search failed', ['schema' => $type, 'error' => $e->getMessage()]);
				continue;
			}

			foreach (array_slice((array)($result['results'] ?? []), 0, $remaining) as $row) {
				$entries[] = $this->entry(ref: 'E' . (count($entries) + 1), type: $type, row: $this->asArray(row: $row));
			}
		}//end foreach

		return $entries;
	}//end search()

	/**
	 * One entry as the assistant and the panel see it.
	 *
	 * @param string               $ref  The entry's ref, E1 onwards.
	 * @param string               $type The schema.
	 * @param array<string, mixed> $row  The object.
	 *
	 * @return array<string, string>
	 */
	private function entry(string $ref, string $type, array $row): array {
		$id    = (string)($row['id'] ?? $row['uuid'] ?? ($row['@self']['id'] ?? ''));
		$title = '';
		foreach (['name', 'title', 'contractNumber'] as $field) {
			if (is_scalar($row[$field] ?? null) === true && (string)$row[$field] !== '') {
				$title = (string)$row[$field];
				break;
			}
		}

		$summary = [];
		foreach (self::SCHEMAS[$type]['fields'] as $field) {
			$value = ($row[$field] ?? null);
			if (is_scalar($value) === true && (string)$value !== '') {
				$summary[] = $field . ': ' . mb_substr((string)$value, 0, self::MAX_FIELD_LENGTH);
			}
		}

		$url   = '';
		$route = self::SCHEMAS[$type]['route'];
		if ($route !== '' && ($id !== '' || str_contains(haystack: $route, needle: '%s') === false)) {
			$url = rtrim($this->urlGenerator->getAbsoluteURL('/index.php/apps/' . AppIdentity::APP_ID), '/') . sprintf($route, rawurlencode($id));
		}

		if ($title === '') {
			$title = $id;
		}

		return [
			'ref' => $ref,
			'schema' => $type,
			'id' => $id,
			'title' => $title,
			'summary' => implode('; ', $summary),
			'url' => $url,
		];
	}//end entry()

	/**
	 * The entries whose ref the reply cites.
	 *
	 * @param string                      $reply    The reply.
	 * @param list<array<string, string>> $searched The entries sent.
	 *
	 * @return list<array<string, string>> The cited entries, in the order they were sent.
	 */
	private function cited(string $reply, array $searched): array {
		preg_match_all('/\bE(\d{1,2})\b/', $reply, $matches);
		$refs = array_map(static fn (string $n): string => 'E' . (int)$n, $matches[1]);

		return array_values(array_filter($searched, static fn (array $entry): bool => in_array($entry['ref'], $refs, true)));
	}//end cited()

	/**
	 * An OpenRegister result row as an array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>
	 */
	private function asArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$data = $row->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}

		}

		return [];
	}//end asArray()
}//end class
