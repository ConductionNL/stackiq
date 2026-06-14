<?php
/**
 * Register-shape tests for open-data-publishing.
 *
 * Asserts the additive `registratiestatus` moderation field on the organisatie
 * schema is present, optional, and enumerates the moderation states — so an
 * import over existing data is non-destructive (existing organisations without
 * the field stay valid) and the pending/active/rejected lifecycle is well-formed.
 *
 * @category  Tests
 * @package   OCA\SoftwareCatalog\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/open-data-publishing/specs/open-data-publishing/spec.md
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Validates the organisatie schema gains the moderation field.
 */
class OpenDataRegisterShapeTest extends TestCase
{
    /**
     * @var array<string,mixed>
     */
    private array $organisatie;

    /**
     * Load the organisatie schema once.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $path    = __DIR__.'/../../../lib/Settings/softwarecatalogus_register.json';
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);
        $this->organisatie = $decoded['components']['schemas']['organisatie'];
    }//end setUp()

    /**
     * The moderation field is present, optional, and enumerates the states.
     *
     * @return void
     */
    public function testOrganisatieHasModerationField(): void
    {
        $props = $this->organisatie['properties'] ?? [];
        $this->assertArrayHasKey('registratiestatus', $props);

        $field = $props['registratiestatus'];
        $this->assertSame('string', $field['type']);
        $this->assertSame(['pending', 'active', 'rejected'], $field['enum']);

        // Optional: not in `required` (import-over-existing is non-destructive).
        $required = $this->organisatie['required'] ?? [];
        $this->assertNotContains('registratiestatus', $required);
    }//end testOrganisatieHasModerationField()
}//end class
