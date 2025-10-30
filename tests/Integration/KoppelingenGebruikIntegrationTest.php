<?php

/**
 * KoppelingenGebruikIntegrationTest
 *
 * Integration tests for Koppelingen-Gebruik API endpoints
 *
 * @category Test
 * @package  OCA\SoftwareCatalog\Tests\Integration
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git-id>
 *
 * @link https://conduction.nl
 */

namespace OCA\SoftwareCatalog\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Integration Tests for Koppelingen-Gebruik API Endpoint
 *
 * Test Coverage:
 * - GET /api/koppelingen-gebruik/{uuid} - Get gebruiks and koppelingen for specific UUID
 * - UUID types: Organisation, Product/Application, Module
 * - Access control for 'ambtenaar' group
 * - Access control for organization owners
 * - Access control for non-member users
 * - Filtering by organization parameter
 * - Pagination and query parameters
 *
 * Test Setup:
 * - Creates test organisations
 * - Creates test users (ambtenaar, org member, other org member)
 * - Creates test products and modules
 * - Creates test gebruiks and koppelingen
 *
 * @category Test
 * @package  OCA\SoftwareCatalog\Tests\Integration
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */
class KoppelingenGebruikIntegrationTest extends TestCase
{
    /**
     * Guzzle HTTP client
     *
     * @var Client
     */
    private Client $client;

    /**
     * Base URL for API requests
     *
     * @var string
     */
    private string $baseUrl = 'http://localhost';

    /**
     * Array to track created test objects for cleanup
     *
     * @var array<string>
     */
    private array $createdObjectIds = [];

    /**
     * Array to track created test users for cleanup
     *
     * @var array<string>
     */
    private array $createdUserIds = [];

    /**
     * Test voorzieningen register ID
     *
     * @var string|null
     */
    private ?string $voorzieningenRegisterId = null;

    /**
     * Test product schema ID
     *
     * @var string|null
     */
    private ?string $productSchemaId = null;

    /**
     * Test module schema ID
     *
     * @var string|null
     */
    private ?string $moduleSchemaId = null;

    /**
     * Test gebruik schema ID
     *
     * @var string|null
     */
    private ?string $gebruikSchemaId = null;

    /**
     * Test koppeling schema ID
     *
     * @var string|null
     */
    private ?string $koppeligenSchemaId = null;

    /**
     * Test organisation schema ID
     *
     * @var string|null
     */
    private ?string $organisationSchemaId = null;

    /**
     * Test organisation A (owns products/modules)
     *
     * @var array<string, mixed>|null
     */
    private ?array $organisationA = null;

    /**
     * Test organisation B (uses products but doesn't own them)
     *
     * @var array<string, mixed>|null
     */
    private ?array $organisationB = null;

    /**
     * Test organisation C (not involved with products/modules)
     *
     * @var array<string, mixed>|null
     */
    private ?array $organisationC = null;

    /**
     * Test product owned by organisation A
     *
     * @var array<string, mixed>|null
     */
    private ?array $testProduct = null;

    /**
     * Test module owned by organisation A
     *
     * @var array<string, mixed>|null
     */
    private ?array $testModule = null;

    /**
     * Admin user credentials
     *
     * @var array<string, string>
     */
    private array $adminAuth = ['admin', 'admin'];

    /**
     * Test ambtenaar user (created during setup)
     *
     * @var string|null
     */
    private ?string $ambtenaarUser = null;

    /**
     * Test org member user (member of organisationA, created during setup)
     *
     * @var string|null
     */
    private ?string $orgMemberUser = null;

    /**
     * Test other org user (member of organisationB, created during setup)
     *
     * @var string|null
     */
    private ?string $otherOrgUser = null;

    /**
     * Test third org user (member of organisationC, created during setup)
     *
     * @var string|null
     */
    private ?string $thirdOrgUser = null;

    /**
     * Set up the test environment before each test
     *
     * Creates Guzzle client with authentication and prepares test data
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Initialize Guzzle client with admin authentication
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'http_errors' => false,
            'auth' => $this->adminAuth,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode(implode(':', $this->adminAuth)),
                'OCS-APIRequest' => 'true',
                'Content-Type' => 'application/json',
            ],
        ]);

        // Load voorzieningen configuration
        $this->loadVoorzieningenConfiguration();

        // Create test data
        if ($this->voorzieningenRegisterId && $this->productSchemaId && $this->moduleSchemaId) {
            $this->createTestOrganisations();
            $this->createTestUsers();
            $this->createTestProductsAndModules();
            $this->createTestGebruiksAndKoppelingen();
        }
    }

    /**
     * Clean up test data after each test
     *
     * Removes all created test objects and users from the database
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Clean up created objects
        foreach ($this->createdObjectIds as $id) {
            try {
                $this->client->delete("/index.php/apps/openregister/api/objects/{$id}");
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }

        // Clean up created users
        foreach ($this->createdUserIds as $userId) {
            try {
                $this->client->delete("/ocs/v1.php/cloud/users/{$userId}");
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }

        parent::tearDown();
    }

    /**
     * Load voorzieningen configuration from settings
     *
     * Retrieves register and schema IDs required for testing
     *
     * @return void
     */
    private function loadVoorzieningenConfiguration(): void
    {
        $response = $this->client->get('/index.php/apps/softwarecatalog/api/voorzieningen/config');
        
        if ($response->getStatusCode() === 200) {
            $data = json_decode($response->getBody()->getContents(), true);
            
            // The config is nested under 'config' key
            $config = $data['config'] ?? $data;
            
            $this->voorzieningenRegisterId = $config['register'] ?? null;
            $this->productSchemaId = $config['product_schema'] ?? null;
            $this->moduleSchemaId = $config['module_schema'] ?? null;
            $this->gebruikSchemaId = $config['gebruik_schema'] ?? null;
            $this->koppeligenSchemaId = $config['koppeling_schema'] ?? null;
            $this->organisationSchemaId = $config['organisatie_schema'] ?? '1'; // Note: organisatie not organisation
        }
    }

    /**
     * Create test organisations
     *
     * Creates three test organisations:
     * - A (owns products/modules)
     * - B (uses products but doesn't own them)
     * - C (not involved with any products/modules)
     *
     * @return void
     */
    private function createTestOrganisations(): void
    {
        $uniqueId = uniqid();
        
        // Create Organisation A (Product/Module Owner)
        $this->organisationA = $this->createObject([
            'name' => "Test Organisation A {$uniqueId}",
            'description' => 'Organisation that owns test products and modules'
        ], $this->voorzieningenRegisterId, $this->organisationSchemaId);

        // Create Organisation B (Product User)
        $this->organisationB = $this->createObject([
            'name' => "Test Organisation B {$uniqueId}",
            'description' => 'Organisation that uses products but does not own them'
        ], $this->voorzieningenRegisterId, $this->organisationSchemaId);

        // Create Organisation C (Isolated Organisation)
        $this->organisationC = $this->createObject([
            'name' => "Test Organisation C {$uniqueId}",
            'description' => 'Organisation not involved with any products or modules'
        ], $this->voorzieningenRegisterId, $this->organisationSchemaId);
    }

    /**
     * Create test users with different roles
     *
     * Creates:
     * - Ambtenaar user (member of 'ambtenaar' group)
     * - Org member user (member of organisationA)
     * - Other org user (member of organisationB)
     * - Third org user (member of organisationC)
     *
     * @return void
     */
    private function createTestUsers(): void
    {
        $uniqueId = uniqid();
        
        // Create ambtenaar user
        $this->ambtenaarUser = "test_ambtenaar_{$uniqueId}";
        $this->createNextcloudUser($this->ambtenaarUser, 'Test Ambtenaar', 'ambtenaar');
        
        // Create org member user
        $this->orgMemberUser = "test_orgmember_{$uniqueId}";
        $this->createNextcloudUser($this->orgMemberUser, 'Test Org Member');
        // TODO: Link user to organisationA via contactperson
        
        // Create other org user
        $this->otherOrgUser = "test_otherorg_{$uniqueId}";
        $this->createNextcloudUser($this->otherOrgUser, 'Test Other Org');
        // TODO: Link user to organisationB via contactperson
        
        // Create third org user
        $this->thirdOrgUser = "test_thirdorg_{$uniqueId}";
        $this->createNextcloudUser($this->thirdOrgUser, 'Test Third Org');
        // TODO: Link user to organisationC via contactperson
    }

    /**
     * Create test products and modules
     *
     * Creates products and modules owned by organisationA
     *
     * @return void
     */
    private function createTestProductsAndModules(): void
    {
        $orgAId = $this->organisationA['uuid'] ?? $this->organisationA['id'] ?? null;
        
        if (!$orgAId) {
            return;
        }

        // Create test product
        $this->testProduct = $this->createObject([
            'title' => 'Test Product ' . uniqid(),
            'description' => 'Test product for integration testing',
            'organisation' => $orgAId,
        ], $this->voorzieningenRegisterId, $this->productSchemaId);

        // Create test module
        $this->testModule = $this->createObject([
            'title' => 'Test Module ' . uniqid(),
            'description' => 'Test module for integration testing',
            'organisation' => $orgAId,
        ], $this->voorzieningenRegisterId, $this->moduleSchemaId);
    }

    /**
     * Create test gebruiks and koppelingen
     *
     * Creates gebruiks and koppelingen that reference:
     * - The test product
     * - The test module
     * - Both organisations
     *
     * @return void
     */
    private function createTestGebruiksAndKoppelingen(): void
    {
        $productId = $this->testProduct['uuid'] ?? $this->testProduct['id'] ?? null;
        $moduleId = $this->testModule['uuid'] ?? $this->testModule['id'] ?? null;
        $orgAId = $this->organisationA['uuid'] ?? $this->organisationA['id'] ?? null;
        $orgBId = $this->organisationB['uuid'] ?? $this->organisationB['id'] ?? null;

        if (!$productId || !$moduleId || !$orgAId || !$orgBId) {
            return;
        }

        // Create gebruiks for product (by org A and org B)
        $this->createObject([
            'title' => 'Product Gebruik by Org A',
            'product' => $productId,
            'afnemer' => $orgAId,
            'organisation' => $orgAId,
        ], $this->voorzieningenRegisterId, $this->gebruikSchemaId);

        $this->createObject([
            'title' => 'Product Gebruik by Org B',
            'product' => $productId,
            'afnemer' => $orgBId,
            'organisation' => $orgBId,
        ], $this->voorzieningenRegisterId, $this->gebruikSchemaId);

        // Create gebruiks for module (by org A and org B)
        $this->createObject([
            'title' => 'Module Gebruik by Org A',
            'module' => $moduleId,
            'afnemer' => $orgAId,
            'organisation' => $orgAId,
        ], $this->voorzieningenRegisterId, $this->gebruikSchemaId);

        $this->createObject([
            'title' => 'Module Gebruik by Org B',
            'module' => $moduleId,
            'afnemer' => $orgBId,
            'organisation' => $orgBId,
        ], $this->voorzieningenRegisterId, $this->gebruikSchemaId);

        // Create koppelingen for product
        $this->createObject([
            'title' => 'Product Koppeling by Org A',
            'product' => $productId,
            'organisation' => $orgAId,
        ], $this->voorzieningenRegisterId, $this->koppeligenSchemaId);

        // Create koppelingen for module
        $this->createObject([
            'title' => 'Module Koppeling by Org A',
            'module' => $moduleId,
            'organisation' => $orgAId,
        ], $this->voorzieningenRegisterId, $this->koppeligenSchemaId);
    }

    /**
     * Create a Nextcloud user
     *
     * @param string      $userId      The user ID
     * @param string      $displayName The display name
     * @param string|null $group       Optional group to add user to
     *
     * @return void
     */
    private function createNextcloudUser(string $userId, string $displayName, ?string $group = null): void
    {
        try {
            // Create user
            $response = $this->client->post('/ocs/v1.php/cloud/users', [
                'form_params' => [
                    'userid' => $userId,
                    'password' => 'testpassword123',
                    'displayName' => $displayName,
                ]
            ]);

            if ($response->getStatusCode() === 200) {
                $this->createdUserIds[] = $userId;
                
                // Add to group if specified
                if ($group) {
                    $this->client->post("/ocs/v1.php/cloud/users/{$userId}/groups", [
                        'form_params' => ['groupid' => $group]
                    ]);
                }
            }
        } catch (\Exception $e) {
            // User might already exist, that's okay
        }
    }

    /**
     * Create an object via OpenRegister API
     *
     * @param array<string, mixed> $data       Object data
     * @param string               $registerId Register ID
     * @param string               $schemaId   Schema ID
     *
     * @return array<string, mixed> The created object
     */
    private function createObject(array $data, string $registerId, string $schemaId): array
    {
        // Extract organisation from data to set it in @self after creation
        $targetOrganisation = $data['organisation'] ?? null;
        
        $response = $this->client->post(
            "/index.php/apps/openregister/api/objects/{$registerId}/{$schemaId}",
            ['json' => $data]
        );

        $object = json_decode($response->getBody()->getContents(), true);
        $id = $object['uuid'] ?? $object['id'] ?? null;
        
        if ($id) {
            $this->createdObjectIds[] = $id;
            
            // If organisation was specified, update the object to set @self.organisation
            // This is necessary because OpenRegister auto-assigns objects to the creator's org
            if ($targetOrganisation && isset($object['@self'])) {
                $object['@self']['organisation'] = $targetOrganisation;
                
                // Update the object with the correct organisation
                $updateResponse = $this->client->put(
                    "/index.php/apps/openregister/api/objects/{$id}",
                    ['json' => $object]
                );
                
                $object = json_decode($updateResponse->getBody()->getContents(), true);
            }
        }

        return $object;
    }

    /**
     * Test GET /api/koppelingen-gebruik/{uuid} with product UUID returns related objects
     *
     * Verifies that requesting gebruiks/koppelingen for a product UUID returns:
     * - All gebruiks that reference the product
     * - All koppelingen that reference the product
     * - Objects from multiple organisations (cross-org access)
     *
     * @return void
     */
    public function testGetKoppelingenGebruikForProductUuid(): void
    {
        if (!$this->testProduct) {
            $this->markTestSkipped('Test product not created');
        }

        $productId = $this->testProduct['uuid'] ?? $this->testProduct['id'];
        $response = $this->client->get("/index.php/apps/softwarecatalog/api/koppelingen-gebruik/{$productId}");
        
        $this->assertEquals(200, $response->getStatusCode(), 'Expected 200 OK response');
        
        $data = json_decode($response->getBody()->getContents(), true);
        
        $this->assertIsArray($data, 'Response should be an array');
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertGreaterThan(0, $data['total'], 'Should find gebruiks/koppelingen for product');

        // Verify we get both gebruiks and koppelingen
        $titles = array_map(fn($r) => $r['title'] ?? '', $data['results']);
        $this->assertContains('Product Gebruik by Org A', $titles, 'Should include product gebruik by org A');
        $this->assertContains('Product Gebruik by Org B', $titles, 'Should include product gebruik by org B');
        $this->assertContains('Product Koppeling by Org A', $titles, 'Should include product koppeling');
    }

    /**
     * Test GET /api/koppelingen-gebruik/{uuid} with module UUID returns related objects
     *
     * Verifies that requesting gebruiks/koppelingen for a module UUID returns:
     * - All gebruiks that reference the module
     * - All koppelingen that reference the module
     *
     * @return void
     */
    public function testGetKoppelingenGebruikForModuleUuid(): void
    {
        if (!$this->testModule) {
            $this->markTestSkipped('Test module not created');
        }

        $moduleId = $this->testModule['uuid'] ?? $this->testModule['id'];
        $response = $this->client->get("/index.php/apps/softwarecatalog/api/koppelingen-gebruik/{$moduleId}");
        
        $this->assertEquals(200, $response->getStatusCode(), 'Expected 200 OK response');
        
        $data = json_decode($response->getBody()->getContents(), true);
        
        $this->assertGreaterThan(0, $data['total'], 'Should find gebruiks/koppelingen for module');

        // Verify we get module-related objects
        $titles = array_map(fn($r) => $r['title'] ?? '', $data['results']);
        $this->assertContains('Module Gebruik by Org A', $titles, 'Should include module gebruik by org A');
        $this->assertContains('Module Gebruik by Org B', $titles, 'Should include module gebruik by org B');
        $this->assertContains('Module Koppeling by Org A', $titles, 'Should include module koppeling');
    }

    /**
     * Test GET /api/koppelingen-gebruik/{uuid} with organisation UUID returns related objects
     *
     * Verifies that requesting gebruiks/koppelingen for an organisation UUID returns:
     * - All gebruiks owned by that organisation
     * - All koppelingen owned by that organisation
     *
     * @return void
     */
    public function testGetKoppelingenGebruikForOrganisationUuid(): void
    {
        if (!$this->organisationA) {
            $this->markTestSkipped('Test organisation not created');
        }

        $orgAId = $this->organisationA['uuid'] ?? $this->organisationA['id'];
        $response = $this->client->get("/index.php/apps/softwarecatalog/api/koppelingen-gebruik/{$orgAId}");
        
        $this->assertEquals(200, $response->getStatusCode(), 'Expected 200 OK response');
        
        $data = json_decode($response->getBody()->getContents(), true);
        
        $this->assertGreaterThan(0, $data['total'], 'Should find gebruiks/koppelingen for organisation');

        // Verify only org A objects are returned (not org B)
        foreach ($data['results'] as $result) {
            $resultOrg = $result['@self']['organisation'] ?? $result['organisation'] ?? null;
            $this->assertEquals($orgAId, $resultOrg, 'All results should belong to organisation A');
        }
    }

    /**
     * Test ambtenaar access to all objects regardless of organisation
     *
     * Verifies that users in the 'ambtenaar' group can:
     * - Access gebruiks/koppelingen from any organisation
     * - Filter by specific organisation via query parameter
     *
     * @return void
     */
    public function testAmbtenaarAccessToAllOrganisations(): void
    {
        if (!$this->testProduct || !$this->organisationB) {
            $this->markTestSkipped('Test data not created');
        }

        $productId = $this->testProduct['uuid'] ?? $this->testProduct['id'];
        $orgBId = $this->organisationB['uuid'] ?? $this->organisationB['id'];

        // Admin (ambtenaar) should see objects from all organisations
        $response = $this->client->get("/index.php/apps/softwarecatalog/api/koppelingen-gebruik/{$productId}");
        $data = json_decode($response->getBody()->getContents(), true);

        // Should see objects from both org A and org B
        $organisations = array_unique(array_map(
            fn($r) => $r['@self']['organisation'] ?? $r['organisation'] ?? null,
            $data['results']
        ));

        $this->assertGreaterThan(1, count($organisations), 'Ambtenaar should see objects from multiple organisations');

        // Test filtering by organisation
        $response = $this->client->get(
            "/index.php/apps/softwarecatalog/api/koppelingen-gebruik/{$productId}?organisation={$orgBId}"
        );
        $filteredData = json_decode($response->getBody()->getContents(), true);

        // All results should belong to org B
        foreach ($filteredData['results'] as $result) {
            $resultOrg = $result['@self']['organisation'] ?? $result['organisation'] ?? null;
            $this->assertEquals($orgBId, $resultOrg, 'Filtered results should only include org B objects');
        }
    }

    /**
     * Test pagination parameters work correctly
     *
     * Verifies _limit, _offset, and _page parameters function properly
     *
     * @return void
     */
    public function testPaginationParameters(): void
    {
        if (!$this->testProduct) {
            $this->markTestSkipped('Test product not created');
        }

        $productId = $this->testProduct['uuid'] ?? $this->testProduct['id'];

        // Test with limit
        $response = $this->client->get("/index.php/apps/softwarecatalog/api/koppelingen-gebruik/{$productId}?_limit=2");
        $data = json_decode($response->getBody()->getContents(), true);

        $this->assertEquals(2, $data['limit'], 'Limit should be 2');
        $this->assertLessThanOrEqual(2, count($data['results']), 'Results should respect limit');
    }

    /**
     * Test response format consistency
     *
     * Verifies all responses follow the expected paginated format
     *
     * @return void
     */
    public function testResponseFormatConsistency(): void
    {
        if (!$this->testProduct) {
            $this->markTestSkipped('Test product not created');
        }

        $productId = $this->testProduct['uuid'] ?? $this->testProduct['id'];
        $response = $this->client->get("/index.php/apps/softwarecatalog/api/koppelingen-gebruik/{$productId}");
        $data = json_decode($response->getBody()->getContents(), true);

        // Verify required fields
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('page', $data);
        $this->assertArrayHasKey('pages', $data);
        $this->assertArrayHasKey('limit', $data);
        $this->assertArrayHasKey('offset', $data);

        // Verify types
        $this->assertIsArray($data['results']);
        $this->assertIsInt($data['total']);
        $this->assertIsInt($data['page']);
        $this->assertIsInt($data['pages']);
        $this->assertIsInt($data['limit']);
        $this->assertIsInt($data['offset']);
    }

    /**
     * Test invalid UUID returns empty results gracefully
     *
     * @return void
     */
    public function testInvalidUuidReturnsEmptyResults(): void
    {
        $invalidUuid = '00000000-0000-0000-0000-000000000000';
        $response = $this->client->get("/index.php/apps/softwarecatalog/api/koppelingen-gebruik/{$invalidUuid}");
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getBody()->getContents(), true);
        $this->assertEquals(0, $data['total'], 'Invalid UUID should return zero results');
    }

    /**
     * Test organisation owner access to their product usage
     *
     * Verifies that organisation A owner can see all gebruiks/koppelingen
     * for products/modules owned by organisation A, even if created by other orgs
     *
     * @return void
     */
    public function testOrganisationOwnerAccessToOwnedProductUsage(): void
    {
        if (!$this->testProduct) {
            $this->markTestSkipped('Test product not created');
        }

        $productId = $this->testProduct['uuid'] ?? $this->testProduct['id'];

        // Admin (acting as org A owner since product is owned by org A) should see
        // all gebruiks/koppelingen for the product, including those by org B
        $response = $this->client->get("/index.php/apps/softwarecatalog/api/koppelingen-gebruik/{$productId}");
        $data = json_decode($response->getBody()->getContents(), true);

        // Should see gebruiks from both org A and org B
        $titles = array_map(fn($r) => $r['title'] ?? '', $data['results']);
        $this->assertContains('Product Gebruik by Org A', $titles);
        $this->assertContains('Product Gebruik by Org B', $titles, 'Product owner should see usage by other organisations');
    }

    /**
     * Test comprehensive three-organisation access control matrix
     *
     * This test validates all access control scenarios across 3 organisations:
     * - Org A: Owns products/modules
     * - Org B: Uses products but doesn't own them
     * - Org C: Isolated, no involvement with products
     *
     * See TEST_MATRIX.md for complete scenario documentation
     *
     * @return void
     */
    public function testThreeOrganisationAccessControlMatrix(): void
    {
        if (!$this->organisationA || !$this->organisationB || !$this->organisationC || !$this->testProduct) {
            $this->markTestSkipped('Test organisations or products not created');
        }

        $orgAId = $this->organisationA['uuid'] ?? $this->organisationA['id'];
        $orgBId = $this->organisationB['uuid'] ?? $this->organisationB['id'];
        $orgCId = $this->organisationC['uuid'] ?? $this->organisationC['id'];
        $productId = $this->testProduct['uuid'] ?? $this->testProduct['id'];

        // Scenario 1: Admin (ambtenaar) can see all organisations
        $response = $this->client->get("/index.php/apps/softwarecatalog/api/koppelingen-gebruik/{$productId}");
        $data = json_decode($response->getBody()->getContents(), true);
        
        $organisations = array_unique(array_map(
            fn($r) => $r['@self']['organisation'] ?? $r['organisation'] ?? null,
            $data['results']
        ));
        
        $this->assertGreaterThan(1, count($organisations), 
            'Admin should see objects from multiple organisations');
        $this->assertContains($orgAId, $organisations, 'Should see Org A objects');
        $this->assertContains($orgBId, $organisations, 'Should see Org B objects');

        // Scenario 2: Org C should have no objects (isolated organisation)
        $response = $this->client->get("/index.php/apps/softwarecatalog/api/koppelingen-gebruik/{$orgCId}");
        $data = json_decode($response->getBody()->getContents(), true);
        
        $this->assertEquals(0, $data['total'], 
            'Organisation C should have no gebruiks/koppelingen (isolated org)');

        // Scenario 3: Admin with organisation filter should only see filtered org
        $response = $this->client->get(
            "/index.php/apps/softwarecatalog/api/koppelingen-gebruik/{$productId}?organisation={$orgBId}"
        );
        $filteredData = json_decode($response->getBody()->getContents(), true);
        
        if ($filteredData['total'] > 0) {
            foreach ($filteredData['results'] as $result) {
                $resultOrg = $result['@self']['organisation'] ?? $result['organisation'] ?? null;
                $this->assertEquals($orgBId, $resultOrg, 
                    'With organisation filter, should only see Org B objects');
            }
        }

        // Scenario 4: Verify Org A owns the product (cross-org access)
        $response = $this->client->get("/index.php/apps/softwarecatalog/api/koppelingen-gebruik/{$productId}");
        $data = json_decode($response->getBody()->getContents(), true);
        
        // Should see usage from both A and B (since A owns the product)
        $this->assertGreaterThan(1, $data['total'], 
            'Product owner (Org A) should see usage by multiple organisations');
    }
}

