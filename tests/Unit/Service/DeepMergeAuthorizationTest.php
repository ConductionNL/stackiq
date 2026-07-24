<?php
/**
 * Unit tests for SettingsService::deepMergeConfig()'s authorization-replace
 * fix (catalog-ratings, softwarecatalog#375).
 *
 * The general-purpose register-fragment merge concatenates list values
 * (documented, correct for e.g. extending a `required` array). Applied
 * naively to an `authorization` rule list, concatenation is a fail-OPEN
 * trap: a base `read: ["public"]` concatenated with any overlay still
 * contains the unconditional `"public"` entry, so the schema stays fully
 * world-readable no matter what the overlay adds. These tests assert the
 * fix (any key literally named `authorization` replaces list values instead
 * of concatenating) and the regression (every other key still concatenates,
 * unchanged).
 *
 * @category  Tests
 * @package   OCA\SoftwareCatalog\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/catalog-ratings/spec.md#requirement-the-register-fragment-merge-must-replace-authorization-rule-lists-not-concatenate-them
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use OCA\SoftwareCatalog\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Test class for the deepMergeConfig() authorization-replace fix.
 */
class DeepMergeAuthorizationTest extends TestCase
{
    /**
     * Invoke the private static SettingsService::deepMergeConfig() via reflection.
     *
     * @param array<mixed> $base    The base config.
     * @param array<mixed> $overlay The fragment overlay.
     *
     * @return array<mixed> The merged result.
     */
    private function merge(array $base, array $overlay): array
    {
        $method = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
        $method->setAccessible(true);
        return $method->invoke(null, $base, $overlay);
    }//end merge()

    /**
     * A base `authorization.read` of `["public"]` concatenated the naive way
     * would still contain `"public"` — the fix REPLACES it wholesale so the
     * dangerous unconditional entry is fully removed.
     *
     * @return void
     *
     * @spec openspec/specs/catalog-ratings/spec.md#requirement-the-register-fragment-merge-must-replace-authorization-rule-lists-not-concatenate-them
     */
    public function testAuthorizationListReplacesNotConcatenates(): void
    {
        $base = [
            'components' => [
                'schemas' => [
                    'beoordeeling' => [
                        'authorization' => [
                            'read' => ['public'],
                        ],
                    ],
                ],
            ],
        ];

        $overlay = [
            'components' => [
                'schemas' => [
                    'beoordeeling' => [
                        'authorization' => [
                            'read'   => [['group' => 'public', 'match' => ['status' => 'approved']]],
                            'create' => ['software-catalog-users'],
                        ],
                    ],
                ],
            ],
        ];

        $merged = $this->merge($base, $overlay);
        $read   = $merged['components']['schemas']['beoordeeling']['authorization']['read'];

        $this->assertSame([['group' => 'public', 'match' => ['status' => 'approved']]], $read);
        $this->assertNotContains('public', $read, 'the merged read rule MUST NOT contain the bare "public" entry');
        $this->assertSame(
            ['software-catalog-users'],
            $merged['components']['schemas']['beoordeeling']['authorization']['create']
        );
    }//end testAuthorizationListReplacesNotConcatenates()

    /**
     * A key not named `authorization` still concatenates lists — the fix is
     * scoped to `authorization` only, not a general merge-strategy change.
     *
     * @return void
     *
     * @spec openspec/specs/catalog-ratings/spec.md#requirement-the-register-fragment-merge-must-replace-authorization-rule-lists-not-concatenate-them
     */
    public function testNonAuthorizationListsStillConcatenate(): void
    {
        $base = [
            'components' => [
                'schemas' => [
                    'beoordeeling' => [
                        'required' => ['naam'],
                    ],
                ],
            ],
        ];

        $overlay = [
            'components' => [
                'schemas' => [
                    'beoordeeling' => [
                        'required' => ['waardering'],
                    ],
                ],
            ],
        ];

        $merged = $this->merge($base, $overlay);

        $this->assertSame(
            ['naam', 'waardering'],
            $merged['components']['schemas']['beoordeeling']['required']
        );
    }//end testNonAuthorizationListsStillConcatenate()

    /**
     * A fragment matching the shape of the real
     * register.d/catalog-ratings.json fragment merged onto the real (today)
     * beoordeeling authorization block leaves NO unconditional public entry
     * in read, and populates create/update/delete (currently entirely
     * absent).
     *
     * @return void
     *
     * @spec openspec/specs/catalog-ratings/spec.md#requirement-creating-updating-or-deleting-a-review-must-be-governed-by-explicit-authorization-rules
     */
    public function testFullAuthorizationBlockReplacement(): void
    {
        $base = [
            'components' => [
                'schemas' => [
                    'beoordeeling' => [
                        'authorization' => [
                            'read' => ['public'],
                        ],
                    ],
                ],
            ],
        ];

        $fragmentPath = __DIR__.'/../../../lib/Settings/register.d/catalog-ratings.json';
        $fragment     = json_decode((string) file_get_contents($fragmentPath), true);
        $this->assertIsArray($fragment, 'catalog-ratings.json fragment MUST be valid JSON');

        $merged = $this->merge($base, $fragment);
        $auth   = $merged['components']['schemas']['beoordeeling']['authorization'];

        $this->assertArrayHasKey('create', $auth);
        $this->assertNotEmpty($auth['create']);
        $this->assertArrayHasKey('update', $auth);
        $this->assertNotEmpty($auth['update']);
        $this->assertArrayHasKey('delete', $auth);
        $this->assertNotEmpty($auth['delete']);
        $this->assertNotContains('public', $auth['read']);
        $this->assertNotContains('public', $auth['update']);
        $this->assertNotContains('public', $auth['delete']);
    }//end testFullAuthorizationBlockReplacement()

    /**
     * `beoordeeling.authorization.update` MUST NOT grant the broad
     * catalog-user group every other schema in this register gets update
     * access to — that narrowness is what makes "non-author cannot edit
     * another's review" true (owner-privilege covers the author's own case).
     *
     * @return void
     *
     * @spec openspec/specs/catalog-ratings/spec.md#requirement-only-the-reviews-author-or-an-organisation-scoped-admin-may-update-it-unrelated-users-must-be-refused
     */
    public function testUpdateAuthorizationExcludesBroadCatalogUserGroup(): void
    {
        $fragmentPath = __DIR__.'/../../../lib/Settings/register.d/catalog-ratings.json';
        $fragment     = json_decode((string) file_get_contents($fragmentPath), true);
        $update       = $fragment['components']['schemas']['beoordeeling']['authorization']['update'];

        $this->assertNotContains('software-catalog-users', $update);
        $this->assertNotContains('aanbod-beheerder', $update);
        $this->assertNotContains('ambtenaar', $update);
    }//end testUpdateAuthorizationExcludesBroadCatalogUserGroup()

    /**
     * `beoordeeling.authorization.delete` is restricted to catalog-admin
     * groups only (per the brief: "deletion restricted") — not the broad
     * list every other schema in this register grants delete to.
     *
     * @return void
     *
     * @spec openspec/specs/catalog-ratings/spec.md#requirement-review-deletion-must-be-restricted-to-catalog-admins-plus-the-owner
     */
    public function testDeleteAuthorizationIsAdminOnly(): void
    {
        $fragmentPath = __DIR__.'/../../../lib/Settings/register.d/catalog-ratings.json';
        $fragment     = json_decode((string) file_get_contents($fragmentPath), true);
        $delete       = $fragment['components']['schemas']['beoordeeling']['authorization']['delete'];

        $this->assertSame(['software-catalog-admins'], $delete);
    }//end testDeleteAuthorizationIsAdminOnly()
}//end class
