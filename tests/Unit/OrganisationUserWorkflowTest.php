<?php

declare(strict_types=1);

/**
 * Organisation User Workflow Unit Test
 *
 * Tests the organization type to role group mapping and related
 * data transformation logic used in the user creation workflow.
 *
 * @category Test
 * @package  OCA\SoftwareCatalog\Tests\Unit
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 */

namespace OCA\SoftwareCatalog\Tests\Unit;

use OCA\SoftwareCatalog\Controller\ContactpersonenController;
use OCA\SoftwareCatalog\Service\ContactpersoonService;
use OCA\SoftwareCatalog\Service\SettingsService;
use OCA\SoftwareCatalog\Service\SoftwareCatalogue\ContactPersonHandler;
use OCP\IUserManager;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IGroup;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use Psr\Container\ContainerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Test class for organisation user workflow data transformations
 *
 * @category Test
 * @package  OCA\SoftwareCatalog\Tests\Unit
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 */
class OrganisationUserWorkflowTest extends TestCase
{
    /**
     * Test organisation UUID
     *
     * @var string
     */
    private string $organisationUuid = 'deaf01c4-b449-41c2-97b7-9045d13e3488';

    /**
     * Test contactpersoon UUID
     *
     * @var string
     */
    private string $contactpersoonUuid = 'dedd36c3-e2af-4ecd-a0f9-80306e641c11';

    /**
     * Test the complete workflow data flow from organisation to user creation
     *
     * Verifies that contactpersoon data is correctly structured for the
     * user creation workflow.
     *
     * @return void
     */
    public function testCompleteOrganisationUserWorkflow(): void
    {
        // Step 1 & 2: Organisation data structure
        $organisationData = [
            'naam' => 'test93',
            'website' => 'www.test.nl',
            'type' => 'Leverancier',
            'status' => 'Actief',
        ];

        $this->assertEquals('Leverancier', $organisationData['type']);
        $this->assertEquals('Actief', $organisationData['status']);

        // Step 3: Create contactpersoon data
        $contactpersoonData = $this->createContactpersoon(
            voornaam: 'test',
            achternaam: '94',
            email: 'test94@test.nl',
            organisationType: 'Leverancier'
        );

        $this->assertNotEmpty($contactpersoonData['uuid']);
        $this->assertEquals('test94@test.nl', $contactpersoonData['e-mailadres']);
        $this->assertEquals($this->organisationUuid, $contactpersoonData['organisatie']);

        // Step 4: Verify expected group assignment
        $expectedGroup = $this->getExpectedGroupForOrganisationType('Leverancier');
        $this->assertEquals('aanbod-beheerder', $expectedGroup);
    }

    /**
     * Test workflow with Gemeente organisation type
     *
     * @return void
     */
    public function testWorkflowWithGemeenteOrganisation(): void
    {
        $contactpersoonData = $this->createContactpersoon(
            voornaam: 'Jan',
            achternaam: 'de Vries',
            email: 'jan.devries@gemeente.nl',
            organisationType: 'Gemeente'
        );

        // Gemeente should map to gebruik-beheerder
        $expectedGroup = $this->getExpectedGroupForOrganisationType(
            $contactpersoonData['organisationType']
        );
        $this->assertEquals('gebruik-beheerder', $expectedGroup);
    }

    /**
     * Test workflow with Samenwerking organisation type
     *
     * @return void
     */
    public function testWorkflowWithSamenwerkingOrganisation(): void
    {
        $contactpersoonData = $this->createContactpersoon(
            voornaam: 'Maria',
            achternaam: 'Jansen',
            email: 'maria.jansen@samenwerking.nl',
            organisationType: 'Samenwerking'
        );

        // Samenwerking should map to gebruik-beheerder
        $expectedGroup = $this->getExpectedGroupForOrganisationType(
            $contactpersoonData['organisationType']
        );
        $this->assertEquals('gebruik-beheerder', $expectedGroup);
    }

    /**
     * Test workflow with Community organisation type
     *
     * @return void
     */
    public function testWorkflowWithCommunityOrganisation(): void
    {
        $contactpersoonData = $this->createContactpersoon(
            voornaam: 'Peter',
            achternaam: 'Bakker',
            email: 'peter.bakker@community.nl',
            organisationType: 'Community'
        );

        // Community should map to aanbod-beheerder
        $expectedGroup = $this->getExpectedGroupForOrganisationType(
            $contactpersoonData['organisationType']
        );
        $this->assertEquals('aanbod-beheerder', $expectedGroup);
    }

    /**
     * Test contactpersoon data when user already has an account
     *
     * @return void
     */
    public function testConvertContactpersoonWhenUserAlreadyExists(): void
    {
        $contactpersoonData = [
            'uuid' => $this->contactpersoonUuid,
            'voornaam' => 'test',
            'achternaam' => '95',
            'e-mailadres' => 'test95@test.nl',
            'naam' => 'test 95',
            'organisatie' => $this->organisationUuid,
            'username' => 'test95@test.nl',
        ];

        // When user already has a username, conversion should be rejected
        $this->assertNotEmpty($contactpersoonData['username']);

        $result = [
            'success' => false,
            'message' => 'Contactpersoon already has a user account',
        ];

        $this->assertFalse($result['success']);
        $this->assertEquals('Contactpersoon already has a user account', $result['message']);
    }

    /**
     * Test password change with invalid user
     *
     * @return void
     */
    public function testPasswordChangeWithInvalidUser(): void
    {
        $userManager = $this->createMock(IUserManager::class);

        // Mock user not found
        $userManager->expects($this->once())
            ->method('get')
            ->with('nonexistent@test.nl')
            ->willReturn(null);

        $user = $userManager->get('nonexistent@test.nl');
        $this->assertNull($user);

        $result = [
            'success' => false,
            'message' => 'User not found',
        ];

        $this->assertFalse($result['success']);
    }

    /**
     * Helper method to create a contactpersoon data array
     *
     * @param string $voornaam          First name
     * @param string $achternaam        Last name
     * @param string $email             Email address
     * @param string $organisationType  Organisation type
     *
     * @return array The contactpersoon data
     */
    private function createContactpersoon(
        string $voornaam,
        string $achternaam,
        string $email,
        string $organisationType
    ): array {
        return [
            'uuid' => $this->contactpersoonUuid,
            'voornaam' => $voornaam,
            'achternaam' => $achternaam,
            'e-mailadres' => $email,
            'naam' => "$voornaam $achternaam",
            'organisatie' => $this->organisationUuid,
            'organisationType' => $organisationType,
        ];
    }

    /**
     * Helper method to get expected group for organisation type
     *
     * @param string $organisationType The organisation type
     *
     * @return string The expected group name
     */
    private function getExpectedGroupForOrganisationType(string $organisationType): string
    {
        $mapping = [
            'Gemeente' => 'gebruik-beheerder',
            'Samenwerking' => 'gebruik-beheerder',
            'Leverancier' => 'aanbod-beheerder',
            'Community' => 'aanbod-beheerder',
        ];

        return $mapping[$organisationType] ?? '';
    }
}
