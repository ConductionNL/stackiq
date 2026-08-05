<?php
/**
 * Register-shape tests for application-lifecycle-tracking.
 *
 * Asserts the additive schema changes are well-formed and OPTIONAL (so an
 * import over existing data is non-destructive — existing gebruik objects
 * without the new fields stay valid), and that the two lifecycle notification
 * rules are declared in the canonical x-openregister-notifications dialect.
 *
 * @category  Tests
 * @package   OCA\SoftwareCatalog\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/application-lifecycle-tracking/specs/application-lifecycle-tracking/spec.md
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Validates the softwarecatalogus register file shape for the lifecycle change.
 */
class LifecycleRegisterShapeTest extends TestCase
{
    /**
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
        $this->assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded, 'register file must be valid JSON');
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
        $this->assertArrayHasKey($slug, $schemas, "schema $slug must exist");
        return $schemas[$slug];
    }//end schema()

    /**
     * The gebruik schema gains the two optional replacement fields.
     *
     * @return void
     */
    public function testGebruikHasOptionalReplacementFields(): void
    {
        $gebruik = $this->schema('gebruik');
        $props   = $gebruik['properties'] ?? [];

        $this->assertArrayHasKey('geplandeVervanging', $props);
        $this->assertArrayHasKey('geplandeVervangingsDatum', $props);

        // Optional: not listed in `required` (import-over-existing is non-destructive).
        $required = $gebruik['required'] ?? [];
        $this->assertNotContains('geplandeVervanging', $required);
        $this->assertNotContains('geplandeVervangingsDatum', $required);

        // The replacement is a related-object reference to a module.
        $this->assertSame('related-object', $props['geplandeVervanging']['objectConfiguration']['handling'] ?? null);
        $this->assertStringContainsString('module', $props['geplandeVervanging']['$ref'] ?? '');
        $this->assertSame('date', $props['geplandeVervangingsDatum']['format'] ?? null);
    }//end testGebruikHasOptionalReplacementFields()

    /**
     * The phaseout-approaching rule is declared on gebruik in the canonical dialect.
     *
     * @return void
     */
    public function testGebruikDeclaresPhaseoutRule(): void
    {
        $rules = $this->schema('gebruik')['x-openregister-notifications'] ?? [];
        $this->assertArrayHasKey('phaseout-approaching', $rules);

        $rule = $rules['phaseout-approaching'];
        $this->assertSame('scheduled', $rule['trigger']['type']);
        $filter = $rule['trigger']['filter']['startDatumUitTeFaseren'] ?? [];
        $this->assertSame('withinNext', $filter['operator'] ?? null);
        $this->assertArrayHasKey('nl', $rule['subject']);
        $this->assertArrayHasKey('en', $rule['subject']);
    }//end testGebruikDeclaresPhaseoutRule()

    /**
     * The eol-approaching rule is declared on moduleVersie in the canonical dialect.
     *
     * @return void
     */
    public function testModuleVersieDeclaresEolRule(): void
    {
        $rules = $this->schema('moduleVersie')['x-openregister-notifications'] ?? [];
        $this->assertArrayHasKey('eol-approaching', $rules);

        $rule = $rules['eol-approaching'];
        $this->assertSame('scheduled', $rule['trigger']['type']);
        $filter = $rule['trigger']['filter']['datumEindeOndersteuning'] ?? [];
        $this->assertSame('withinNext', $filter['operator'] ?? null);
    }//end testModuleVersieDeclaresEolRule()
}//end class
