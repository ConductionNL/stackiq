<?php

/**
 * Golden test for the portal manifests of PortalContributionProvider.
 *
 * Pins the full manifest per audience, including the scope field, the
 * one-hop `via`, the trust gate and the field whitelist of every collection.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Portal
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Portal;

use OCA\Stackiq\Portal\PortalContributionProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden manifests per audience.
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
class PortalContributionManifestGoldenTest extends TestCase {
	/**
	 * Every audience, plus an unknown and a missing one.
	 *
	 * @return void
	 */
	public function testManifestsPerAudience(): void {
		$provider = new PortalContributionProvider();
		$out      = [];
		foreach (['vendor-org', 'participant-org', 'citizen', ''] as $audience) {
			$out[$audience] = $provider->getContribution(['audience' => $audience, 'organisation' => 'org-1', 'trust' => 'high']);
		}

		$out['no-audience'] = $provider->getContribution([]);

		$path = dirname(__DIR__, 2) . '/fixtures/portal/manifests.json';
		$this->assertSame(json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR), $out);
	}
}
