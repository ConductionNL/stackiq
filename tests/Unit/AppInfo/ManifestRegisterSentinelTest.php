<?php

/**
 * Manifest register-sentinel wiring tests.
 *
 * `src/manifest.json` addresses OpenRegister collections as a
 * `(register, schema)` pair, where the register is written as a runtime
 * sentinel — `"register": "@resolve:voorzieningen_register"`. The sentinel is
 * substituted in the browser by @conduction/nextcloud-vue's
 * `resolveManifestSentinels()`, which reads the value provisioned by
 * `Application::boot()` through `@nextcloud/initial-state`.
 *
 * Two things can go wrong with that arrangement, and NEITHER is visible to any
 * check this repo ran before these tests existed:
 *
 *   1. **A sentinel nobody provisions.** The manifest validator only checks the
 *      value's SHAPE, and the gate that cross-references the manifest against
 *      the register JSON deliberately skips sentinels — `isLiteralSlug()` in
 *      the gate package excludes any value containing `@`. So an unprovisioned
 *      key substitutes `null` and the page fetches
 *      `/api/objects/null/<schema>`.
 *
 *   2. **A sentinel pointing at a register that does not carry the schema.**
 *      This one shipped. The Standards pages read `schema: "element"` while
 *      naming `@resolve:voorzieningen_register`, and
 *      `lib/Settings/softwarecatalogus_register.json` attaches `element` to the
 *      SECOND register in the same file (`vng-gemma` / AMEF), not to the
 *      catalog register (`stackiq`, formerly slugged `voorzieningen`).
 *      Declaring a schema is not attaching it: only an
 *      attached schema is fetchable through `/api/objects/{register}/{schema}`,
 *      and since OpenRegister's 2026-08-16 change to
 *      `ObjectService::setSchema()` an unattached slug THROWS rather than
 *      falling back to a global lookup. Every load of `/standaarden` answered
 *      `404 {"message":"Schema not found: 'element'"}`.
 *
 * These tests close both holes statically, from the repository's own files.
 *
 * ⚠️ The sentinel → register-slug mapping below is DECLARED HERE, on purpose.
 * Nothing in the app declares it: the register ids are discovered at runtime by
 * `SettingsService::configureVoorzieningen()` / `configureAmef()`, the latter by
 * detecting which register carries the AMEF core schemas. So this map is the
 * written-down intent, and an unmapped sentinel FAILS rather than being skipped
 * — a new sentinel must be a decision, not a silent gap.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\AppInfo
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;

/**
 * Every manifest register sentinel must be provisioned, and must name a
 * register that actually attaches the schema the page asks for.
 */
class ManifestRegisterSentinelTest extends TestCase {

	/**
	 * Sentinel key => the register slug it is expected to resolve to in
	 * `lib/Settings/softwarecatalogus_register.json`.
	 *
	 * @var array<string, string>
	 */
	private const SENTINEL_REGISTERS = [
		'voorzieningen_register' => 'stackiq',
		'amef_register' => 'vng-gemma',
	];

	/**
	 * Repository root.
	 *
	 * @var string
	 */
	private string $root;

	/**
	 * Decoded `src/manifest.json`.
	 *
	 * @var array<string, mixed>
	 */
	private array $manifest;

	/**
	 * Decoded `lib/Settings/softwarecatalogus_register.json`.
	 *
	 * @var array<string, mixed>
	 */
	private array $registerConfig;

	/**
	 * Source of `lib/AppInfo/Application.php`.
	 *
	 * @var string
	 */
	private string $applicationPhp;

	/**
	 * Load the three artefacts under test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->root = dirname(__DIR__, 3);

		$manifest = json_decode(
			(string)file_get_contents($this->root . '/src/manifest.json'),
			true
		);
		$this->assertIsArray($manifest, 'src/manifest.json did not decode');
		$this->manifest = $manifest;

		$registerConfig = json_decode(
			(string)file_get_contents(
				$this->root . '/lib/Settings/softwarecatalogus_register.json'
			),
			true
		);
		$this->assertIsArray($registerConfig, 'the register JSON did not decode');
		$this->registerConfig = $registerConfig;

		$this->applicationPhp = (string)file_get_contents(
			$this->root . '/lib/AppInfo/Application.php'
		);
	}//end setUp()

	/**
	 * Collect every `(sentinel key, schema slug)` pair the manifest declares.
	 *
	 * Walks the whole tree rather than just `pages[].config`, because a detail
	 * page's widgets carry their own `content.{register,schema}` and those hit
	 * the same endpoint.
	 *
	 * @param mixed $node Current node.
	 * @param array<int, array<string>> $out Accumulator, by reference.
	 *
	 * @return void
	 */
	private function collectPairs($node, array &$out): void {
		if (is_array($node) === false) {
			return;
		}

		$register = ($node['register'] ?? null);
		$schema = ($node['schema'] ?? null);
		if (is_string($register) === true
			&& is_string($schema) === true
			&& str_starts_with($register, '@resolve:') === true
			&& $schema !== ''
		) {
			$out[] = [substr($register, strlen('@resolve:')), $schema];
		}

		foreach ($node as $child) {
			$this->collectPairs($child, $out);
		}
	}//end collectPairs()

	/**
	 * Every sentinel the manifest uses is provisioned by `Application::boot()`.
	 *
	 * @return void
	 */
	public function testEverySentinelIsProvisioned(): void {
		$pairs = [];
		$this->collectPairs($this->manifest, $pairs);

		$keys = array_values(array_unique(array_column($pairs, 0)));
		sort($keys);

		// A zero-pair run would pass every assertion below without checking
		// anything, so state the subject count first.
		$this->assertGreaterThan(
			0,
			count($keys),
			'no @resolve: register sentinels found in src/manifest.json — the '
			. 'collector is broken, not the manifest'
		);

		foreach ($keys as $key) {
			$this->assertStringContainsString(
				"provideInitialState('" . $key . "'",
				$this->applicationPhp,
				sprintf(
					'src/manifest.json uses "@resolve:%s" but '
					. 'lib/AppInfo/Application.php::boot() never provisions it, so '
					. 'it resolves to null and the page fetches '
					. '/api/objects/null/<schema>.',
					$key
				)
			);
		}
	}//end testEverySentinelIsProvisioned()

	/**
	 * Every `(sentinel, schema)` pair names a register that ATTACHES that
	 * schema in the register configuration.
	 *
	 * @return void
	 */
	public function testEveryPairNamesARegisterThatAttachesTheSchema(): void {
		$registers = ($this->registerConfig['components']['registers'] ?? []);
		$this->assertNotEmpty(
			$registers,
			'the register JSON declares no registers — the reader is broken'
		);

		$pairs = [];
		$this->collectPairs($this->manifest, $pairs);
		$this->assertGreaterThan(0, count($pairs), 'no (register, schema) pairs collected');

		foreach ($pairs as [$key, $schema]) {
			$this->assertArrayHasKey(
				$key,
				self::SENTINEL_REGISTERS,
				sprintf(
					'"@resolve:%s" is not in this test\'s sentinel map. Add it '
					. 'together with the register slug it resolves to — an '
					. 'unmapped sentinel is unchecked, and the defect this test '
					. 'exists for is exactly a sentinel pointing at the wrong '
					. 'register.',
					$key
				)
			);

			$slug = self::SENTINEL_REGISTERS[$key];
			$this->assertArrayHasKey(
				$slug,
				$registers,
				sprintf('register "%s" is not declared in the register JSON', $slug)
			);

			$attached = ($registers[$slug]['schemas'] ?? []);
			$this->assertContains(
				$schema,
				$attached,
				sprintf(
					'src/manifest.json reads schema "%s" from "@resolve:%s" '
					. '(register "%s"), but that register attaches only [%s]. '
					. 'GET /api/objects/{%s}/%s answers 404 "Schema not found". '
					. 'Point the page at the register that carries the schema — '
					. 'attaching the schema to this register instead would make '
					. 'the request succeed and return NOTHING, because objects '
					. 'live per register.',
					$schema,
					$key,
					$slug,
					implode(', ', $attached),
					$slug,
					$schema
				)
			);
		}
	}//end testEveryPairNamesARegisterThatAttachesTheSchema()

	/**
	 * Positive control for the check above: the attachment assertion really
	 * does reject a schema the register does not carry.
	 *
	 * Without this, `testEveryPairNamesARegisterThatAttachesTheSchema` passing
	 * is equally consistent with the register JSON listing every schema under
	 * every register, or with the reader silently yielding an empty list.
	 *
	 * @return void
	 */
	public function testTheAttachmentCheckCanFail(): void {
		$registers = ($this->registerConfig['components']['registers'] ?? []);
		$schemas = array_keys(($this->registerConfig['components']['schemas'] ?? []));

		$this->assertContains(
			'element',
			$schemas,
			'"element" is not even declared as a schema — the fixture moved'
		);
		$this->assertNotContains(
			'element',
			($registers['stackiq']['schemas'] ?? []),
			'"element" is now attached to the stackiq register. If that was deliberate, '
			. 'note that it makes the Standards fetch SUCCEED and return an '
			. 'empty list, because AMEF elements are written to the AMEF '
			. 'register — a visible error traded for an invisible pass.'
		);
		$this->assertContains(
			'element',
			($registers['vng-gemma']['schemas'] ?? []),
			'"element" is no longer attached to the AMEF register, so the '
			. 'Standards pages have nowhere to read from'
		);
	}//end testTheAttachmentCheckCanFail()
}//end class
