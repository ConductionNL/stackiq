<?php

/**
 * Verification test for the `contract` schema's OpenRegister RBAC read rule
 * (vendor-visibility-rbac REQ-006).
 *
 * Contract CRUD runs entirely through OpenRegister's own object store
 * (ADR-022, `contract-administration`) — there is no app-local contract
 * controller for reads. This capability's REQ-006 therefore verifies (and,
 * per this change, corrects) the schema-config RBAC read rule directly
 * rather than exercising app controller code — a live end-to-end
 * OpenRegister RBAC engine is not available in this sandboxed unit-test
 * environment, so this test asserts on the deployed rule's *shape*: that
 * "public" and an unscoped `aanbod-beheerder` grant are gone, and that
 * `aanbod-beheerder` is match-scoped to the caller's own organisation via
 * the `_organisation` OR-stamped multitenancy field — the exact signal
 * already trusted elsewhere in this codebase
 * (`ContractApprovalService::authorizeSubmit()`).
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/vendor-visibility-rbac/spec.md#requirement-contract-reads-must-deny-non-counterparty-cross-organisation-access-via-the-openregister-schema-rbac-rule-req-006
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the `contract` schema's `authorization.read` rule.
 */
class ContractRbacTest extends TestCase
{

    /**
     * Load the contract schema's `authorization` block from the real,
     * deployed register config — not a fixture — so this test fails the
     * moment the shipped config regresses.
     *
     * @return array<string,mixed>
     */
    private function loadContractAuthorization(): array
    {
        $path = __DIR__.'/../../../lib/Settings/softwarecatalogus_register.json';
        $this->assertFileExists($path, 'softwarecatalogus_register.json must exist');

        $config = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('contract', $config['components']['schemas']);
        $this->assertArrayHasKey('authorization', $config['components']['schemas']['contract']);

        return $config['components']['schemas']['contract']['authorization'];

    }//end loadContractAuthorization()


    /**
     * TC-13 (config-shape form): "public" MUST NOT appear in the contract
     * read rule — an anonymous/any-group caller must never read a contract.
     * This was the primary leak: any authenticated OR unauthenticated caller
     * could read any organisation's contract.
     *
     * @return void
     */
    public function testContractReadRuleDoesNotGrantPublicAccess(): void
    {
        $authorization = $this->loadContractAuthorization();

        foreach ($authorization['read'] as $entry) {
            if (is_string($entry) === true) {
                $this->assertNotSame('public', $entry, 'contract.authorization.read MUST NOT contain a bare "public" grant');
                continue;
            }

            $this->assertNotSame('public', $entry['group'] ?? null, 'contract.authorization.read MUST NOT contain a "public" group entry');
        }

    }//end testContractReadRuleDoesNotGrantPublicAccess()


    /**
     * TC-13: `aanbod-beheerder` MUST NOT be an unscoped (bare-string) read
     * grant on `contract` — it MUST be match-scoped to the caller's own
     * organisation via `_organisation`, so a vendor who is not the
     * contract's own organisation is denied.
     *
     * @return void
     */
    public function testAanbodBeheerderReadIsOrganisationScoped(): void
    {
        $authorization = $this->loadContractAuthorization();

        foreach ($authorization['read'] as $entry) {
            $this->assertNotSame(
                'aanbod-beheerder',
                $entry,
                'aanbod-beheerder MUST NOT be a bare unscoped read grant on contract (REQ-006)'
            );
        }

        $scopedEntries = array_values(
            array_filter(
                $authorization['read'],
                static function ($entry) {
                    return is_array($entry) === true && ($entry['group'] ?? null) === 'aanbod-beheerder';
                }
            )
        );

        $this->assertNotEmpty($scopedEntries, 'aanbod-beheerder MUST have a match-scoped read grant on contract');

        foreach ($scopedEntries as $entry) {
            $this->assertArrayHasKey('match', $entry);
            $this->assertArrayHasKey('_organisation', $entry['match']);
            $this->assertSame('$organisation', $entry['match']['_organisation']);
        }

    }//end testAanbodBeheerderReadIsOrganisationScoped()


    /**
     * TC-14 (config-shape form): `ambtenaar` retains an unrestricted read
     * grant — admin/ambtenaar MUST retain read access regardless of
     * counterparty status.
     *
     * @return void
     */
    public function testAmbtenaarRetainsUnrestrictedRead(): void
    {
        $authorization = $this->loadContractAuthorization();

        $this->assertContains('ambtenaar', $authorization['read']);

    }//end testAmbtenaarRetainsUnrestrictedRead()


}//end class
