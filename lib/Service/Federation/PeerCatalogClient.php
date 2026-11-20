<?php

/**
 * Peer catalogue client.
 *
 * Reads a federation peer's public listing of published entries over HTTP,
 * through Nextcloud's own HTTP client, page by page up to a configured cap.
 * The caller runs the SSRF guard first; this client only talks to a host the
 * guard allowed, and lets Nextcloud reach a private address only for a host
 * the administrator allowlisted in `local_federation_hosts`.
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
use Psr\Log\LoggerInterface;

/**
 * Fetches the published entries a peer catalogue exposes to anonymous readers.
 *
 * @spec openspec/changes/federation-pull-fetches-the-peer/specs/federated-catalog-sync/spec.md#requirement-a-pull-reads-the-peers-own-published-catalogue-req-fed-010
 */
class PeerCatalogClient {
	/**
	 * The path of a peer's public listing of published entries, below its base
	 * URL: OpenCatalogi's public publications API, which only answers entries
	 * whose publication date has passed.
	 */
	public const LISTING_PATH = '/index.php/apps/opencatalogi/api/publications';

	/**
	 * The number of entries asked for per page.
	 */
	public const PAGE_SIZE = 100;

	/**
	 * Constructor.
	 *
	 * @param IClientService   $clientService Nextcloud's HTTP client factory.
	 * @param FederationConfig $config        The timeout, the cap and the local allowlist.
	 * @param LoggerInterface  $logger        Logger.
	 *
	 * @spec openspec/changes/federation-pull-fetches-the-peer/specs/federated-catalog-sync/spec.md#requirement-a-pull-reads-the-peers-own-published-catalogue-req-fed-010
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly FederationConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The URL of a peer's listing: the peer's address plus the listing path,
	 * or the address as it is when it already names the listing.
	 *
	 * @param string $peerUrl The peer address.
	 *
	 * @return string The listing URL.
	 *
	 * @spec openspec/changes/federation-pull-fetches-the-peer/specs/federated-catalog-sync/spec.md#requirement-a-pull-reads-the-peers-own-published-catalogue-req-fed-010
	 */
	public function listingUrl(string $peerUrl): string {
		$base = rtrim(trim($peerUrl), '/');
		if (str_ends_with($base, self::LISTING_PATH) === true) {
			return $base;
		}

		return $base.self::LISTING_PATH;
	}//end listingUrl()

	/**
	 * Fetch the peer's published entries, following pages until the peer has
	 * no more or the cap is reached.
	 *
	 * A timeout, a status outside 2xx or a body that is not a JSON listing
	 * fails the whole fetch, so a half-read catalogue never reaches the merge.
	 *
	 * @param string $peerUrl The peer address.
	 *
	 * @return array{ok:bool, reason:string, organisation:?string, entries:array<int,array<string,mixed>>}
	 *
	 * @spec openspec/changes/federation-pull-fetches-the-peer/specs/federated-catalog-sync/spec.md#requirement-a-pull-reads-the-peers-own-published-catalogue-req-fed-010
	 */
	public function fetch(string $peerUrl): array {
		$cap = $this->config->getMaxEntries();
		$entries = [];
		$total = 0;
		$organisation = null;
		$page = 1;

		do {
			$answer = $this->fetchPage(peerUrl: $peerUrl, page: $page, limit: min(self::PAGE_SIZE, ($cap - $total)));
			if ($answer['ok'] === false) {
				return ['ok' => false, 'reason' => $answer['reason'], 'organisation' => null, 'entries' => []];
			}

			$organisation ??= $answer['organisation'];
			$entries = array_merge($entries, $answer['entries']);
			$total += count($answer['entries']);
			$more = ($answer['entries'] !== [] && $page < $answer['pages'] && $total < $cap);
			$page++;
		} while ($more === true);

		return [
			'ok' => true,
			'reason' => 'ok',
			'organisation' => $organisation,
			'entries' => array_slice($entries, 0, $cap),
		];
	}//end fetch()

	/**
	 * Fetch one page of the listing.
	 *
	 * @param string $peerUrl The peer address.
	 * @param int    $page    The 1-based page.
	 * @param int    $limit   The entries asked for.
	 *
	 * @return array{ok:bool, reason:string, organisation:?string, entries:array<int,array<string,mixed>>, pages:int}
	 *
	 * @spec openspec/changes/federation-pull-fetches-the-peer/specs/federated-catalog-sync/spec.md#requirement-a-pull-reads-the-peers-own-published-catalogue-req-fed-010
	 */
	private function fetchPage(string $peerUrl, int $page, int $limit): array {
		try {
			$response = $this->clientService->newClient()->get(
				$this->listingUrl(peerUrl: $peerUrl),
				[
					'query' => ['_page' => $page, '_limit' => $limit],
					'timeout' => $this->config->getPeerTimeout(),
					'http_errors' => false,
					'headers' => ['Accept' => 'application/json'],
					'nextcloud' => ['allow_local_address' => $this->isAllowlisted(peerUrl: $peerUrl)],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning('[Federation] peer not reachable', ['peer' => $peerUrl, 'error' => $e::class]);
			return $this->failure(reason: 'not reachable');
		}

		$status = $response->getStatusCode();
		if ($status < 200 || $status > 299) {
			return $this->failure(reason: 'peer answered HTTP '.$status);
		}

		return $this->readListing(body: (string)$response->getBody());
	}//end fetchPage()

	/**
	 * Read a listing body: `{results: [...], pages?, organisation?}` or a bare
	 * list of entries.
	 *
	 * @param string $body The response body.
	 *
	 * @return array{ok:bool, reason:string, organisation:?string, entries:array<int,array<string,mixed>>, pages:int}
	 *
	 * @spec openspec/changes/federation-pull-fetches-the-peer/specs/federated-catalog-sync/spec.md#requirement-a-pull-reads-the-peers-own-published-catalogue-req-fed-010
	 */
	private function readListing(string $body): array {
		$listing = json_decode($body, true);
		if (is_array($listing) === false) {
			return $this->failure(reason: 'peer answered no JSON listing');
		}

		$entries = $listing;
		if (array_key_exists('results', $listing) === true) {
			$entries = $listing['results'];
		}

		if (is_array($entries) === false || array_is_list($entries) === false) {
			return $this->failure(reason: 'peer answered no JSON listing');
		}

		return [
			'ok' => true,
			'reason' => 'ok',
			'organisation' => $this->organisationName(listing: $listing),
			'entries' => array_values(array_filter($entries, 'is_array')),
			'pages' => max(1, (int)($listing['pages'] ?? 1)),
		];
	}//end readListing()

	/**
	 * The peer catalogue's name, when the listing gives one.
	 *
	 * @param array<mixed> $listing The decoded listing.
	 *
	 * @return string|null The name, or null.
	 *
	 * @spec openspec/changes/federation-pull-fetches-the-peer/specs/federated-catalog-sync/spec.md#requirement-mirrored-entries-show-where-they-came-from-req-fed-011
	 */
	private function organisationName(array $listing): ?string {
		$name = $listing['organisation'] ?? ($listing['catalog']['title'] ?? null);
		if (is_string($name) === true && trim($name) !== '') {
			return trim($name);
		}

		return null;
	}//end organisationName()

	/**
	 * Whether the peer's host is on the administrator's local allowlist, the
	 * only case in which Nextcloud may connect to a private address.
	 *
	 * @param string $peerUrl The peer address.
	 *
	 * @return bool True when allowlisted.
	 *
	 * @spec openspec/changes/federation-pull-fetches-the-peer/specs/federated-catalog-sync/spec.md#requirement-a-pull-reads-the-peers-own-published-catalogue-req-fed-010
	 */
	private function isAllowlisted(string $peerUrl): bool {
		$host = parse_url($peerUrl, PHP_URL_HOST);
		return is_string($host) === true && in_array($host, $this->config->getLocalFederationHosts(), true) === true;
	}//end isAllowlisted()

	/**
	 * A failed page.
	 *
	 * @param string $reason Why it failed.
	 *
	 * @return array{ok:false, reason:string, organisation:null, entries:array<int,array<string,mixed>>, pages:int}
	 *
	 * @spec openspec/changes/federation-pull-fetches-the-peer/specs/federated-catalog-sync/spec.md#requirement-a-pull-reads-the-peers-own-published-catalogue-req-fed-010
	 */
	private function failure(string $reason): array {
		return ['ok' => false, 'reason' => $reason, 'organisation' => null, 'entries' => [], 'pages' => 0];
	}//end failure()
}//end class
