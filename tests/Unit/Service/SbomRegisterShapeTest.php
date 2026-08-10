<?php

/**
 * Register-shape tests for sbom-import.
 *
 * Guards the exact defect that shipped in the 2026-07-23 wave and was only
 * caught by live e2e: the SBOM provenance properties (sbomLastImportedAt,
 * sbomFormat, sbomFileName, sbomComponents) were authored on the *organisatie*
 * schema instead of *moduleVersie*. Because SbomImportService::recordProvenance()
 * writes them onto the moduleVersie object and getStatus() reads them back from
 * it, the misplacement meant the moduleVersie magic table never gained the
 * columns, provenance writes were silently dropped, and the import-status
 * endpoint always reported "never imported". These assertions fail loudly if
 * the properties ever drift back onto the wrong schema.
 *
 * @category  Tests
 * @package   OCA\SoftwareCatalog\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/sbom-import/spec.md
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Validates the softwarecatalogus register file shape for the SBOM import change.
 */
class SbomRegisterShapeTest extends TestCase
{

    /**
     * The SBOM provenance properties, in one place so every assertion agrees.
     *
     * @var array<int,string>
     */
    private const SBOM_PROVENANCE_PROPS = [
        'sbomLastImportedAt',
        'sbomFormat',
        'sbomFileName',
        'sbomComponents',
    ];

    /**
     * The decoded register file, loaded once in setUp().
     *
     * @var array<string,mixed>
     */
    private array $register;

    /**
     * Load and decode the register file once.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $path = __DIR__.'/../../../lib/Settings/softwarecatalogus_register.json';
        $this->assertFileExists(filename: $path);
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray(actual: $decoded, message: 'register file must be valid JSON');
        $this->register = $decoded;
    }//end setUp()

    /**
     * Fetch a schema definition by slug.
     *
     * @param string $slug The schema slug.
     *
     * @return array<string,mixed> The schema definition.
     */
    private function schema(string $slug): array
    {
        $schemas = $this->register['components']['schemas'] ?? [];
        $this->assertArrayHasKey(key: $slug, array: $schemas, message: "schema $slug must exist");
        return $schemas[$slug];
    }//end schema()

    /**
     * The provenance properties live on moduleVersie — the object the importer
     * actually writes them to and the status endpoint reads them from.
     *
     * @return void
     * @spec   openspec/specs/sbom-import/spec.md#scenario-a-successful-import-records-provenance-on-the-version
     */
    public function testModuleVersieCarriesSbomProvenance(): void
    {
        $props = $this->schema(slug: 'moduleVersie')['properties'] ?? [];
        foreach (self::SBOM_PROVENANCE_PROPS as $field) {
            $this->assertArrayHasKey(
                key: $field,
                array: $props,
                message: "moduleVersie must declare $field so recordProvenance()/getStatus() persist"
            );
        }

        $this->assertSame(
            expected: ['cyclonedx-json', 'spdx-json'],
            actual: $props['sbomFormat']['enum'] ?? null,
            message: 'sbomFormat enum must match SbomImportService::SUPPORTED_FORMATS'
        );
        $this->assertStringContainsString(
            needle: 'sbomComponent',
            haystack: $props['sbomComponents']['$ref'] ?? '',
            message: 'sbomComponents must reference the sbomComponent schema'
        );
    }//end testModuleVersieCarriesSbomProvenance()

    /**
     * The provenance properties must NOT sit on organisatie — the misplacement
     * that shipped the silent-drop defect.
     *
     * @return void
     * @spec   openspec/specs/sbom-import/spec.md#scenario-a-successful-import-records-provenance-on-the-version
     */
    public function testOrganisatieDoesNotCarrySbomProvenance(): void
    {
        $props = $this->schema(slug: 'organisatie')['properties'] ?? [];
        foreach (self::SBOM_PROVENANCE_PROPS as $field) {
            $this->assertArrayNotHasKey(
                key: $field,
                array: $props,
                message: "organisatie must NOT declare $field — SBOM provenance belongs on moduleVersie"
            );
        }
    }//end testOrganisatieDoesNotCarrySbomProvenance()

    /**
     * Provenance is additive/optional so an import over existing moduleVersie
     * rows without these fields stays non-destructive.
     *
     * @return void
     * @spec   openspec/specs/sbom-import/spec.md#scenario-existing-versions-are-unaffected-by-the-schema-addition
     */
    public function testSbomProvenanceIsOptional(): void
    {
        $required = $this->schema(slug: 'moduleVersie')['required'] ?? [];
        foreach (self::SBOM_PROVENANCE_PROPS as $field) {
            $this->assertNotContains(
                needle: $field,
                haystack: $required,
                message: "$field must be optional so existing versions remain valid"
            );
        }
    }//end testSbomProvenanceIsOptional()

    /**
     * The sbomComponent catalog schema exists and is wired into the
     * voorzieningen register, so imported components have a home table.
     *
     * @return void
     * @spec   openspec/specs/sbom-import/spec.md#scenario-a-parsed-component-persists-with-its-moduleversie-relation
     */
    public function testSbomComponentIsRegisteredInVoorzieningen(): void
    {
        $sbomComponent = $this->schema(slug: 'sbomComponent');
        $props         = $sbomComponent['properties'] ?? [];
        $this->assertArrayHasKey(key: 'moduleVersie', array: $props, message: 'sbomComponent must relate back to its moduleVersie');

        $voorzieningen = $this->register['components']['registers']['voorzieningen'] ?? [];
        $this->assertContains(
            needle: 'sbomComponent',
            haystack: $voorzieningen['schemas'] ?? [],
            message: 'sbomComponent must be a member of the voorzieningen register'
        );
    }//end testSbomComponentIsRegisteredInVoorzieningen()
}//end class
