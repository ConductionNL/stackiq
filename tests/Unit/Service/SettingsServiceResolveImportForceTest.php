<?php

/**
 * Unit tests for SettingsService::resolveImportForce() — the
 * force-when-stale-version workaround for
 * https://github.com/ConductionNL/openregister/issues/2075.
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/settings-service/spec.md#requirement-the-system-shall-run-auto-configuration-import-and-configuration-maintenance-req-003
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use OCA\OpenRegister\Service\ConfigurationService;
use OCA\SoftwareCatalog\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Covers the version-comparison decision `loadSettings()` uses to decide
 * whether `importFromApp()` should be called with `force=true`.
 *
 * Background: OpenRegister's `importFromApp(force: false)` advances the
 * STORED configuration version whenever any registers/schemas/objects come
 * back from the import, but does NOT apply property/authorization changes
 * to schemas that already exist — only newly-created schemas receive the
 * full payload. Verified live: a `catalog-ratings` fragment adding
 * `auteur`/`status`/`authorization.read` to the pre-existing `beoordeeling`
 * schema advanced the stored configuration version across an `occ upgrade`
 * (proving the content-derived version signature itself works), yet the
 * schema was left unchanged until a subsequent `force: true` import ran.
 * `resolveImportForce()` closes that gap on the consumer side by comparing
 * the freshly computed content-derived version against the version
 * OpenRegister already has stored for this app and forcing whenever they
 * differ.
 */
final class SettingsServiceResolveImportForceTest extends TestCase
{


    /**
     * Build a SettingsService instance without running its constructor, and
     * a reflection handle to the private resolveImportForce() method.
     *
     * @return array{0: SettingsService, 1: ReflectionMethod}
     */
    private function makeSubject(): array
    {
        $service = $this->getMockBuilder(SettingsService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $method = new ReflectionMethod($service, 'resolveImportForce');
        $method->setAccessible(true);

        return [$service, $method];
    }//end makeSubject()


    /**
     * (a) Computed and stored versions differ, caller did not ask for
     * force: resolveImportForce() MUST return true, so loadSettings()
     * passes force=true to importFromApp() and the change to an existing
     * schema actually applies instead of only advancing the stored marker.
     *
     * @return void
     */
    public function testVersionsDifferForcesImportEvenWhenCallerDidNotRequestForce(): void
    {
        [$service, $method] = $this->makeSubject();

        /** @var ConfigurationService|MockObject $configurationService */
        $configurationService = $this->createMock(ConfigurationService::class);
        $configurationService->method('getConfiguredAppVersion')
            ->with('softwarecatalog')
            ->willReturn('2.4.0+base.9003c029');

        $result = $method->invoke(
            $service,
            $configurationService,
            'softwarecatalog',
            '2.4.0+base.f6e72fc8+frag.92299b19',
            false
        );

        $this->assertTrue(
            $result,
            'A stale stored version must force the import so changes to already-existing schemas apply '
            .'(ConductionNL/openregister#2075) — not just re-record the new version.'
        );
    }//end testVersionsDifferForcesImportEvenWhenCallerDidNotRequestForce()


    /**
     * (b) Computed and stored versions match, caller did not ask for
     * force: resolveImportForce() MUST return false, preserving the
     * existing cheap no-op path — an unchanged register must not trigger a
     * forced import on every call.
     *
     * @return void
     */
    public function testVersionsMatchDoesNotForceImport(): void
    {
        [$service, $method] = $this->makeSubject();

        /** @var ConfigurationService|MockObject $configurationService */
        $configurationService = $this->createMock(ConfigurationService::class);
        $configurationService->method('getConfiguredAppVersion')
            ->with('softwarecatalog')
            ->willReturn('2.4.0+base.f6e72fc8+frag.92299b19');

        $result = $method->invoke(
            $service,
            $configurationService,
            'softwarecatalog',
            '2.4.0+base.f6e72fc8+frag.92299b19',
            false
        );

        $this->assertFalse(
            $result,
            'Matching computed/stored versions must NOT force the import — otherwise every loadSettings() '
            .'call would re-import unconditionally, a performance regression.'
        );
    }//end testVersionsMatchDoesNotForceImport()


    /**
     * (c) An explicit caller-supplied force=true MUST still force the
     * import, regardless of how the computed and stored versions compare
     * — resolveImportForce() must not weaken the pre-existing explicit
     * force semantics.
     *
     * @return void
     */
    public function testExplicitCallerForceAlwaysForcesRegardlessOfVersionMatch(): void
    {
        [$service, $method] = $this->makeSubject();

        /** @var ConfigurationService|MockObject $configurationService */
        $configurationService = $this->createMock(ConfigurationService::class);
        $configurationService->expects($this->never())->method('getConfiguredAppVersion');

        $result = $method->invoke(
            $service,
            $configurationService,
            'softwarecatalog',
            '2.4.0+base.f6e72fc8+frag.92299b19',
            true
        );

        $this->assertTrue(
            $result,
            'An explicit caller force=true must always force the import, without even needing to read '
            .'back the stored version.'
        );
    }//end testExplicitCallerForceAlwaysForcesRegardlessOfVersionMatch()


    /**
     * A stored version of null (nothing imported yet for this app, or
     * getConfiguredAppVersion() could not determine one) is treated as
     * "differs" — resolveImportForce() MUST return true, since there is
     * either nothing to skip (first import) or the safe default is to
     * force.
     *
     * @return void
     */
    public function testNullStoredVersionIsTreatedAsDifferingAndForcesImport(): void
    {
        [$service, $method] = $this->makeSubject();

        /** @var ConfigurationService|MockObject $configurationService */
        $configurationService = $this->createMock(ConfigurationService::class);
        $configurationService->method('getConfiguredAppVersion')->willReturn(null);

        $result = $method->invoke(
            $service,
            $configurationService,
            'softwarecatalog',
            '2.4.0+base.f6e72fc8+frag.92299b19',
            false
        );

        $this->assertTrue($result);
    }//end testNullStoredVersionIsTreatedAsDifferingAndForcesImport()


    /**
     * If getConfiguredAppVersion() itself throws (defensive path — the real
     * implementation already catches its own exceptions, but the caller
     * must not blow up if that ever changes), resolveImportForce() MUST
     * treat the lookup as "unknown" and still return a boolean (forcing),
     * not propagate the exception.
     *
     * @return void
     */
    public function testExceptionFromStoredVersionLookupIsTreatedAsUnknownAndForcesImport(): void
    {
        [$service, $method] = $this->makeSubject();

        /** @var ConfigurationService|MockObject $configurationService */
        $configurationService = $this->createMock(ConfigurationService::class);
        $configurationService->method('getConfiguredAppVersion')
            ->willThrowException(new \RuntimeException('lookup failed'));

        $result = $method->invoke(
            $service,
            $configurationService,
            'softwarecatalog',
            '2.4.0+base.f6e72fc8+frag.92299b19',
            false
        );

        $this->assertTrue($result);
    }//end testExceptionFromStoredVersionLookupIsTreatedAsUnknownAndForcesImport()
}//end class
