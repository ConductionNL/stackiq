<?php

/**
 * The review-moderation overlay lands on the schema the reviews are in.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/catalog-ratings/spec.md#requirement-public-read-access-to-a-review-must-be-restricted-to-approved-reviews
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\ModerationService;
use OCA\Stackiq\Service\ReviewService;
use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Merges the REAL fragment onto the REAL register, which is the only thing that
 * can tell "the merge works" from "the merge reaches the right schema".
 *
 * `DeepMergeAuthorizationTest` proves `deepMergeConfig()` replaces an
 * `authorization` list rather than concatenating it, and it proved that
 * throughout the period this was broken. It builds its own base keyed to match
 * the fragment, so the one thing it could never notice is the fragment naming a
 * schema the base does not have.
 *
 * That is what happened. `beoordeeling` was TITLED "Assessment" and its slug
 * became `software-review` (`RenameDutchSchemaSlugs`), and the fragment was
 * keyed on the title. It overlaid a schema nothing declares, so the real
 * `software-review` kept its base `read: ["public"]`: every pending and
 * rejected review was publicly readable, which is the exact hole the fragment
 * was written to close. Nothing failed, because the fragment merge unions by
 * key and a key nobody else uses simply creates a schema nobody reads.
 */
class ReviewModerationOverlayReachesTheSchemaTest extends TestCase {

	/**
	 * The register JSON with every `register.d` fragment merged over it.
	 *
	 * @return array<mixed> The merged register.
	 */
	private function mergedRegister(): array {
		$root = dirname(__DIR__, 3);

		$base = json_decode(
			(string)file_get_contents($root.'/lib/Settings/softwarecatalogus_register.json'),
			true
		);
		$this->assertIsArray(actual: $base, message: 'the register JSON must parse');

		$method = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$method->setAccessible(true);

		$fragments = glob($root.'/lib/Settings/register.d/*.json');
		$this->assertNotEmpty(actual: $fragments, message: 'there must be fragments to merge');

		sort($fragments);
		foreach ($fragments as $fragment) {
			$overlay = json_decode((string)file_get_contents($fragment), true);
			$this->assertIsArray(actual: $overlay, message: basename($fragment).' must parse');
			$base = $method->invoke(null, $base, $overlay);
		}

		return $base;

	}//end mergedRegister()

	/**
	 * Every schema a `register.d` fragment names must already exist in the
	 * register it overlays.
	 *
	 * A fragment is an OVERLAY, and the merge unions by key, so a fragment
	 * naming a schema the base does not have does not fail: it quietly creates
	 * one. The overlay then applies perfectly, to nothing.
	 *
	 * @return void
	 */
	public function testEveryFragmentOverlaysASchemaThatExists(): void {
		$root = dirname(__DIR__, 3);

		$base = json_decode(
			(string)file_get_contents($root.'/lib/Settings/softwarecatalogus_register.json'),
			true
		);
		$declared = array_keys($base['components']['schemas']);

		$fragments = glob($root.'/lib/Settings/register.d/*.json');
		$this->assertNotEmpty(actual: $fragments);

		// A fragment may also add a schema of its own, provided it registers it
		// in a register's schema list (ADR-037); an overlay of a misspelt slug
		// is registered nowhere and still counts as an orphan.
		foreach ($fragments as $fragment) {
			$overlay = json_decode((string)file_get_contents($fragment), true);
			foreach (($overlay['components']['registers'] ?? []) as $register) {
				$declared = array_merge($declared, ($register['schemas'] ?? []));
			}
		}

		$orphans = [];
		foreach ($fragments as $fragment) {
			$overlay = json_decode((string)file_get_contents($fragment), true);
			// A fragment that declares a whole schema (slug and properties) and
			// attaches it to a register adds it, rather than overlaying one.
			$added = [];
			foreach (($overlay['components']['registers'] ?? []) as $register) {
				$added = array_merge($added, ($register['schemas'] ?? []));
			}

			foreach (($overlay['components']['schemas'] ?? []) as $slug => $schema) {
				$isNew = in_array($slug, $added, true) === true && ($schema['slug'] ?? null) === $slug && isset($schema['properties']) === true;
				if (in_array($slug, $declared, true) === false && $isNew === false) {
					$orphans[] = basename($fragment).' -> '.$slug;
				}
			}
		}

		$this->assertSame(expected: [], actual: $orphans, message: 'a fragment overlays a schema the register does not declare');

	}//end testEveryFragmentOverlaysASchemaThatExists()

	/**
	 * The schema the review services actually write to carries the gated public
	 * read, and no bare `"public"` survives the merge.
	 *
	 * Keyed off the services' own constants rather than a literal, so a later
	 * slug move breaks this test instead of quietly un-gating the schema again.
	 *
	 * @return void
	 */
	public function testTheReviewSchemaPublicReadIsGatedOnApproved(): void {
		$slug = ReviewService::REVIEW_TYPE;
		$this->assertSame(
			expected: $slug,
			actual: ModerationService::MODERATED_TYPE_REVIEW,
			message: 'the write path and the moderation queue must name the same schema'
		);

		$schemas = $this->mergedRegister()['components']['schemas'];
		$this->assertArrayHasKey(key: $slug, array: $schemas);

		$read = $schemas[$slug]['authorization']['read'];

		$this->assertNotContains(
			needle: 'public',
			haystack: $read,
			message: 'a bare public read makes every pending and rejected review world-readable'
		);
		$this->assertNotContains(
			needle: ['group' => 'public', 'crossOrganisation' => true],
			haystack: $read,
			message: 'an unconditional public read makes every pending and rejected review world-readable'
		);
		$this->assertContains(
			needle: ['group' => 'public', 'match' => ['status' => 'approved'], 'crossOrganisation' => true],
			haystack: $read,
			message: 'anonymous readers must see approved reviews and only those'
		);

	}//end testTheReviewSchemaPublicReadIsGatedOnApproved()

	/**
	 * The base register on its own is NOT gated. Without this the test above
	 * cannot distinguish "the overlay applied" from "the base was already fine",
	 * and it would keep passing if the fragment stopped being merged at all.
	 *
	 * @return void
	 */
	public function testTheBaseRegisterIsUngatedWithoutTheFragment(): void {
		$root = dirname(__DIR__, 3);
		$base = json_decode(
			(string)file_get_contents($root.'/lib/Settings/softwarecatalogus_register.json'),
			true
		);

		$read = $base['components']['schemas'][ReviewService::REVIEW_TYPE]['authorization']['read'];

		$this->assertContains(
			needle: ['group' => 'public', 'crossOrganisation' => true],
			haystack: $read,
			message: 'the base is expected to carry the unconditional public read that the fragment removes; '
			.'if the base has been gated directly, this test and the fragment are now redundant'
		);

	}//end testTheBaseRegisterIsUngatedWithoutTheFragment()

	/**
	 * A pending review is readable only by its author and the catalogue admins
	 * (REQ-CLR-008). The author reaches it through OpenRegister's owner admit,
	 * so every read rule but the admins' must match approved reviews only.
	 *
	 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/catalog-ratings/spec.md#requirement-a-pending-review-is-readable-only-by-its-author-and-the-moderators-req-clr-008
	 *
	 * @return void
	 */
	public function testOnlyTheAdminsReadAReviewThatIsNotApproved(): void {
		$read = $this->mergedRegister()['components']['schemas'][ReviewService::REVIEW_TYPE]['authorization']['read'];

		$this->assertContains(needle: 'software-catalog-admins', haystack: $read, message: 'the moderators must read every review');

		$ungated = [];
		foreach ($read as $rule) {
			if ($rule === 'software-catalog-admins') {
				continue;
			}

			if (is_array($rule) === false || ($rule['match']['status'] ?? null) !== 'approved') {
				$ungated[] = $rule;
			}
		}

		$this->assertSame(expected: [], actual: $ungated, message: 'a group outside the admins would read pending and rejected reviews');

		$groups = array_map(static fn ($rule): string => (string)($rule['group'] ?? $rule), $read);
		$this->assertContains(needle: 'software-catalog-users', haystack: $groups, message: 'catalogue users still read approved reviews');
		$this->assertContains(needle: 'public', haystack: $groups, message: 'anonymous readers still read approved reviews');

	}//end testOnlyTheAdminsReadAReviewThatIsNotApproved()

	/**
	 * The review form does not offer the status, so its author cannot approve
	 * their own review (REQ-CLR-008).
	 *
	 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/catalog-ratings/spec.md#requirement-a-pending-review-is-readable-only-by-its-author-and-the-moderators-req-clr-008
	 *
	 * @return void
	 */
	public function testTheReviewFormDoesNotOfferTheStatus(): void {
		$status = $this->mergedRegister()['components']['schemas'][ReviewService::REVIEW_TYPE]['properties']['status'];

		$this->assertTrue(condition: ($status['hideOnForm'] ?? false), message: 'the author must not set the status on the form');
		$this->assertSame(expected: 'pending', actual: $status['default']);

	}//end testTheReviewFormDoesNotOfferTheStatus()

}//end class
