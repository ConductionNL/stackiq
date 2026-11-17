<?php

/**
 * Peer catalogue client.
 *
 * Reads one peer's own published catalogue over HTTP: OpenCatalogi's public
 * publication listing at the peer's address, without aggregation (so the
 * peer's own entries only, never what the peer mirrors from others), page by
 * page up to the configured cap, and the name of the peer's catalogue.
 *
 * The caller runs the SSRF host guard first. This client adds Nextcloud's own
 * local-address refusal on top: a host that resolves to a private network is
 * refused by the HTTP client unless the peer is on the local-federation
 * allowlist, and that refusal reads as `blocked`.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Federation
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/federation-pull-fetches-the-peer/specs/federated-catalog-sync/spec.md#requirement-a-pull-reads-the-peers-own-published-catalogue-req-fed-010
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Federation;

use OCP\Http\Client\IClientService;
use OCP\Http\Client\LocalServerException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fetches a peer's published entries and catalogue name.
 */
class PeerCatalogClient {
	/**
	 * The peer's public publication listing, relative to its base URL.
	 */
	public const LISTING_PATH = '/index.php/apps/opencatalogi/api/federation/publications';

	/**
	 * The peer's public catalogue listing, relative to its base URL.
	 */
	public const CATALOGI_PATH = '/index.php/apps/opencatalogi/api/catalogi';

	/**
	 * Entries asked for per page.
	 */
	public const PAGE_SIZE = 100;

	/**
	 * The reason a peer that does not answer is given.
	 */
	public const NOT_REACHABLE = 'not reachable';

	/**
	 * The reason a host the HTTP client refuses as local is given.
	 */
	public const BLOCKED = 'blocked';

	/**
	 * The reason an answer that is not a listing is given.
	 */
	public const INVALID_ANSWER = 'invalid answer';

	/**
	 * Constructor.
	 *
	 * @param IClientService   $clients The Nextcloud HTTP client factory.
	 * @param FederationConfig $config  Timeout and entry cap.
	 * @param LoggerInterface  $logger  Logger.
	 */
	public function __construct(
		private readonly IClientService $clients,
		private readonly FederationConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Fetch the peer's published entries, page by page up to the cap, and its catalogue name.
	 *
	 * @param string $peerUrl    The peer base URL (already passed by the SSRF guard).
	 * @param bool   $allowLocal Whether the peer is on the local-federation allowlist.
	 *
	 * @return array{ok:bool, reason:string, organisation:?string, entries:array<int,array<string,mixed>>}
	 *
	 * @spec openspec/changes/federation-pull-fetches-the-peer/specs/federated-catalog-sync/spec.md#requirement-a-pull-reads-the-peers-own-published-catalogue-req-fed-010
	 */
	public function fetch(string $peerUrl, bool $allowLocal=false): array {
		$base = rtrim($peerUrl, '/');
		$cap = $this->config->getMaxEntries();
		$entries = [];
		$page = 1;

		while (count($entries) < $cap) {
			$answer = $this->getJson(
				url: $base . self::LISTING_PATH,
				query: ['_aggregate' => 'false', '_page' => $page, '_limit' => min(self::PAGE_SIZE, ($cap - count($entries)))],
				allowLocal: $allowLocal
			);
			if ($answer['ok'] === false) {
				return ['ok' => false, 'reason' => $answer['reason'], 'organisation' => null, 'entries' => []];
			}

			$results = $answer['body']['results'] ?? null;
			if (is_array($results) === false) {
				return ['ok' => false, 'reason' => self::INVALID_ANSWER, 'organisation' => null, 'entries' => []];
			}

			foreach ($results as $entry) {
				if (is_array($entry) === true && count($entries) < $cap) {
					$entries[] = $entry;
				}
			}

			if ($results === [] || $this->isLastPage(body: $answer['body'], page: $page) === true) {
				break;
			}

			$page++;
		}//end while

		return [
			'ok' => true,
			'reason' => 'ok',
			'organisation' => $this->catalogueName(base: $base, allowLocal: $allowLocal),
			'entries' => $entries,
		];
	}//end fetch()

	/**
	 * Whether the listing says this page is its last.
	 *
	 * @param array<string,mixed> $body The decoded page.
	 * @param int                 $page The page just read.
	 *
	 * @return bool True when there is no further page.
	 */
	private function isLastPage(array $body, int $page): bool {
		$pages = $body['pages'] ?? null;
		if (is_numeric($pages) === true) {
			return $page >= (int)$pages;
		}

		// A listing without a page count is read as one page.
		return true;
	}//end isLastPage()

	/**
	 * The title of the peer's first public catalogue, or null when it cannot be read.
	 *
	 * The name is a label only, so a failure here never fails the pull.
	 *
	 * @param string $base       The peer base URL without a trailing slash.
	 * @param bool   $allowLocal Whether local addresses are allowed for this peer.
	 *
	 * @return string|null The catalogue name.
	 */
	private function catalogueName(string $base, bool $allowLocal): ?string {
		$answer = $this->getJson(url: $base . self::CATALOGI_PATH, query: ['_limit' => 1], allowLocal: $allowLocal);
		if ($answer['ok'] === false) {
			return null;
		}

		$first = $answer['body']['results'][0] ?? null;
		if (is_array($first) === false) {
			return null;
		}

		foreach (['title', 'name'] as $key) {
			$value = $first[$key] ?? null;
			if (is_string($value) === true && trim($value) !== '') {
				return trim($value);
			}
		}

		return null;
	}//end catalogueName()

	/**
	 * GET a JSON document, answering the decoded body or why it could not be read.
	 *
	 * @param string              $url        The URL.
	 * @param array<string,mixed> $query      The query parameters.
	 * @param bool                $allowLocal Whether a local address may be reached.
	 *
	 * @return array{ok:bool, reason:string, body:array<string,mixed>}
	 */
	private function getJson(string $url, array $query, bool $allowLocal): array {
		try {
			$response = $this->clients->newClient()->get(
				$url,
				[
					'query' => $query,
					'timeout' => $this->config->getPeerTimeout(),
					'headers' => ['Accept' => 'application/json'],
					'http_errors' => false,
					'nextcloud' => ['allow_local_address' => $allowLocal],
				]
			);
		} catch (LocalServerException $e) {
			$this->logger->warning('[Federation] peer address refused as local', ['url' => $url]);
			return ['ok' => false, 'reason' => self::BLOCKED, 'body' => []];
		} catch (Throwable $e) {
			$this->logger->warning('[Federation] peer did not answer', ['url' => $url, 'error' => $e->getMessage()]);
			return ['ok' => false, 'reason' => self::NOT_REACHABLE, 'body' => []];
		}

		$status = $response->getStatusCode();
		if ($status < 200 || $status >= 300) {
			return ['ok' => false, 'reason' => 'HTTP ' . $status, 'body' => []];
		}

		$body = json_decode((string)$response->getBody(), true);
		if (is_array($body) === false) {
			return ['ok' => false, 'reason' => self::INVALID_ANSWER, 'body' => []];
		}

		return ['ok' => true, 'reason' => 'ok', 'body' => $body];
	}//end getJson()
}//end class
