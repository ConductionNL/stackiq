<?php

/**
 * A canned PeerCatalogClient for FederationService tests.
 *
 * It answers fetch() with a listing given up front, read the way the real
 * client reads a page (`{results: [...], organisation?}` or a bare list); a
 * Throwable fails the fetch with its message as the reason. It records the
 * peers it was asked for, so a test can assert that a blocked host was never
 * fetched.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Support
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

namespace OCA\Stackiq\Tests\Unit\Support;

use OCA\Stackiq\Service\Federation\PeerCatalogClient;

/**
 * Answers every fetch with the same canned listing.
 */
class FakePeerCatalogClient extends PeerCatalogClient {
	/**
	 * The peers fetched, in order.
	 *
	 * @var array<int,string>
	 */
	public array $fetched = [];

	/**
	 * Constructor. Deliberately skips the parent's HTTP dependencies.
	 *
	 * @param mixed $listing The listing to answer, or a Throwable to fail with.
	 */
	public function __construct(private mixed $listing) {
	}//end __construct()

	/**
	 * Answer the canned listing.
	 *
	 * @param string $peerUrl The peer address.
	 *
	 * @return array{ok:bool, reason:string, organisation:?string, entries:array<int,array<string,mixed>>}
	 */
	public function fetch(string $peerUrl): array {
		$this->fetched[] = $peerUrl;
		if ($this->listing instanceof \Throwable) {
			return ['ok' => false, 'reason' => $this->listing->getMessage(), 'organisation' => null, 'entries' => []];
		}

		$listing = $this->listing;
		$entries = $listing;
		if (is_array($listing) === true && is_array($listing['results'] ?? null) === true) {
			$entries = $listing['results'];
		}

		$organisation = null;
		if (is_array($listing) === true && is_string($listing['organisation'] ?? null) === true) {
			$organisation = $listing['organisation'];
		}

		return ['ok' => true, 'reason' => 'ok', 'organisation' => $organisation, 'entries' => array_values((array)$entries)];
	}//end fetch()
}//end class
