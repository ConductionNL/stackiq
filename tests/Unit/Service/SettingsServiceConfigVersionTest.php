<?php

/**
 * Unit tests for the content-derived import version signature computed by
 * SettingsService::computeConfigVersion() (register-import-reliability).
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/settings-service/spec.md#requirement-the-system-shall-run-auto-configuration-import-and-configuration-maintenance-req-003
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Regression coverage for the original defect: the import version passed
 * to OpenRegister's version-gated importFromApp() was derived only from
 * the register JSON's own `info.version` field plus a hash of the
 * ADR-037 fragment files — never from the monolith register file's own
 * content. A change that edited the monolith directly without also
 * bumping `info.version` by hand produced a byte-identical version, and
 * OpenRegister's version gate silently skipped the import. These tests
 * exercise `computeConfigVersion()` directly (a pure function of three
 * strings, invoked via reflection since it is private) so the defect
 * class can be asserted against without needing a live OpenRegister
 * container or filesystem fixtures.
 *
 * @spec openspec/specs/settings-service/spec.md#requirement-the-system-shall-run-auto-configuration-import-and-configuration-maintenance-req-003
 */
final class SettingsServiceConfigVersionTest extends TestCase {

	/**
	 * Invoke the private static SettingsService::computeConfigVersion().
	 *
	 * @param string $baseVersion The register JSON's own info.version.
	 * @param string $monolithContent The raw monolith file content.
	 * @param string $fragmentSig The accumulated fragment signature (may be empty).
	 *
	 * @return string The computed content-derived version.
	 */
	private function computeConfigVersion(string $baseVersion, string $monolithContent, string $fragmentSig): string {
		$m = new ReflectionMethod(SettingsService::class, 'computeConfigVersion');
		$m->setAccessible(true);
		return $m->invoke(null, $baseVersion, $monolithContent, $fragmentSig);
	}//end computeConfigVersion()

	/**
	 * THE REGRESSION TEST: a monolith-content-only edit — info.version and
	 * every fragment file byte-identical — MUST still change the computed
	 * version. A signature derived only from info.version + the fragment
	 * hash (the original, defective behavior) would keep this identical,
	 * which is exactly how eight merged market-gap changes went dead on an
	 * upgraded instance while CI and `occ upgrade` both reported success.
	 *
	 * @return void
	 */
	public function testMonolithContentChangeAloneChangesComputedVersion(): void {
		$baseVersion = '2.4.0';
		$fragmentSig = 'contract-approval.json:' . md5('{"paths":{}}') . ';';

		$before = $this->computeConfigVersion(
			baseVersion: $baseVersion,
			monolithContent: '{"info":{"version":"2.4.0"},"components":{"schemas":{"module":{}}}}',
			fragmentSig: $fragmentSig
		);

		// Simulate a wave change editing the monolith directly (new schema
		// added) WITHOUT bumping info.version — the exact scenario from the
		// live reproduction — and with every fragment file untouched.
		$after = $this->computeConfigVersion(
			baseVersion: $baseVersion,
			monolithContent: '{"info":{"version":"2.4.0"},"components":{"schemas":{"module":{},"bioMeasure":{}}}}',
			fragmentSig: $fragmentSig
		);

		$this->assertNotSame(
			$before,
			$after,
			'A monolith content change with an unchanged info.version and unchanged fragments '
			. 'must still change the computed configVersion, otherwise OpenRegister\'s version-gated '
			. 'importFromApp() silently skips the re-import.'
		);
	}//end testMonolithContentChangeAloneChangesComputedVersion()

	/**
	 * Conversely: identical inputs (no monolith change, no fragment
	 * change, no info.version change) MUST produce an identical version,
	 * so an unchanged register still short-circuits at OpenRegister's
	 * version gate rather than re-importing on every repair-step run
	 * (performance regression the design explicitly guards against).
	 *
	 * @return void
	 */
	public function testUnchangedInputsProduceIdenticalVersion(): void {
		$args = [
			'baseVersion' => '2.4.0',
			'monolithContent' => '{"info":{"version":"2.4.0"},"components":{"schemas":{"module":{}}}}',
			'fragmentSig' => 'contract-approval.json:' . md5('{}') . ';',
		];

		$first = $this->computeConfigVersion(...$args);
		$second = $this->computeConfigVersion(...$args);

		$this->assertSame($first, $second);
	}//end testUnchangedInputsProduceIdenticalVersion()

	/**
	 * A fragment-content-only change (monolith and info.version untouched)
	 * must also change the computed version — the pre-existing, already
	 * correct half of the signature must keep working after this fix.
	 *
	 * @return void
	 */
	public function testFragmentContentChangeAloneChangesComputedVersion(): void {
		$monolithContent = '{"info":{"version":"2.4.0"},"components":{"schemas":{"module":{}}}}';

		$before = $this->computeConfigVersion(
			baseVersion: '2.4.0',
			monolithContent: $monolithContent,
			fragmentSig: 'contract-approval.json:' . md5('{"paths":{}}') . ';'
		);
		$after = $this->computeConfigVersion(
			baseVersion: '2.4.0',
			monolithContent: $monolithContent,
			fragmentSig: 'contract-approval.json:' . md5('{"paths":{"/foo":{}}}') . ';'
		);

		$this->assertNotSame($before, $after);
	}//end testFragmentContentChangeAloneChangesComputedVersion()

	/**
	 * The computed version carries a `+base.<hash>` component whenever a
	 * monolith is present, and only appends `+frag.<hash>` when fragment
	 * files actually exist — an app with no fragments yet must not carry a
	 * stray, empty `+frag.` suffix.
	 *
	 * @return void
	 */
	public function testVersionFormatOmitsFragmentSuffixWhenNoFragmentsExist(): void {
		$version = $this->computeConfigVersion(
			baseVersion: '2.4.0',
			monolithContent: '{"info":{"version":"2.4.0"}}',
			fragmentSig: ''
		);

		$this->assertStringStartsWith('2.4.0+base.', $version);
		$this->assertStringNotContainsString('+frag.', $version);
	}//end testVersionFormatOmitsFragmentSuffixWhenNoFragmentsExist()
}//end class
