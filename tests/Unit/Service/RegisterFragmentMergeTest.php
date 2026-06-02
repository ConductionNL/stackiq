<?php

/**
 * Unit tests for the ADR-037 modular register fragment deep-merge.
 *
 * @category Test
 * @package  OCA\SoftwareCatalog\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/modular-register-manifest-fragments/specs/modular-config/spec.md
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use OCA\SoftwareCatalog\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies that disjoint register fragments union cleanly so concurrent
 * OpenSpec change builds never collide on the shared register file (ADR-037).
 */
final class RegisterFragmentMergeTest extends TestCase
{
    /**
     * Invoke the private static SettingsService::deepMergeConfig().
     *
     * @param array<mixed> $base    Base config.
     * @param array<mixed> $overlay Fragment.
     *
     * @return array<mixed> Merged config.
     */
    private function merge(array $base, array $overlay): array
    {
        $m = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
        $m->setAccessible(true);
        return $m->invoke(null, $base, $overlay);
    }//end merge()

    /**
     * Two fragments adding disjoint OpenAPI schemas/paths union by key.
     *
     * @return void
     */
    public function testDisjointFragmentsUnionSchemasAndPaths(): void
    {
        $base = [
            'components' => ['schemas' => ['Existing' => ['type' => 'object']]],
            'paths'      => ['/existing' => ['get' => []]],
        ];

        $base = $this->merge(
                base: $base,
                overlay: [
                    'components' => ['schemas' => ['AlphaComponent' => ['type' => 'object']]],
                    'paths'      => ['/alpha' => ['get' => []]],
                ]
                );
        $base = $this->merge(
                base: $base,
                overlay: [
                    'components' => ['schemas' => ['BetaService' => ['type' => 'object']]],
                    'paths'      => ['/beta' => ['post' => []]],
                ]
                );

        $this->assertArrayHasKey(key: 'Existing', array: $base['components']['schemas']);
        $this->assertArrayHasKey(key: 'AlphaComponent', array: $base['components']['schemas']);
        $this->assertArrayHasKey(key: 'BetaService', array: $base['components']['schemas']);
        $this->assertCount(expectedCount: 3, haystack: $base['components']['schemas']);
        $this->assertArrayHasKey(key: '/existing', array: $base['paths']);
        $this->assertArrayHasKey(key: '/alpha', array: $base['paths']);
        $this->assertArrayHasKey(key: '/beta', array: $base['paths']);
    }//end testDisjointFragmentsUnionSchemasAndPaths()

    /**
     * List arrays are concatenated; scalars overwrite.
     *
     * @return void
     */
    public function testListsConcatenateAndScalarsOverwrite(): void
    {
        $merged = $this->merge(
            base: ['required' => ['a', 'b'], 'info' => ['version' => '0.1.0']],
            overlay: ['required' => ['c'], 'info' => ['version' => '0.2.0']]
        );
        $this->assertSame(expected: ['a', 'b', 'c'], actual: $merged['required']);
        $this->assertSame(expected: '0.2.0', actual: $merged['info']['version']);
    }//end testListsConcatenateAndScalarsOverwrite()
}//end class
