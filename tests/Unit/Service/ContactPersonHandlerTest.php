<?php

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use OCA\SoftwareCatalog\Service\SoftwareCatalogue\ContactPersonHandler;
use OCA\SoftwareCatalog\Service\SettingsService;
use OCA\SoftwareCatalog\Service\SymfonyEmailService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IGroup;
use OCP\Security\ISecureRandom;
use Psr\Container\ContainerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Test class for ContactPersonHandler organization type mapping
 *
 * This class tests the organization type to role group mapping functionality
 * that was implemented to replace configuration-based role assignment.
 *
 * @category Tests
 * @package  OCA\SoftwareCatalog\Tests\Unit\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 * @version  1.0.0
 */
class ContactPersonHandlerTest extends TestCase
{
    /**
     * Mock of the IUserManager service
     *
     * @var IUserManager|MockObject
     */
    private IUserManager|MockObject $userManager;

    /**
     * Mock of the IGroupManager service
     *
     * @var IGroupManager|MockObject
     */
    private IGroupManager|MockObject $groupManager;

    /**
     * Mock of the ContainerInterface service
     *
     * @var ContainerInterface|MockObject
     */
    private ContainerInterface|MockObject $container;

    /**
     * Mock of the LoggerInterface
     *
     * @var LoggerInterface|MockObject
     */
    private LoggerInterface|MockObject $logger;

    /**
     * Mock of the SettingsService
     *
     * @var SettingsService|MockObject
     */
    private SettingsService|MockObject $settingsService;

    /**
     * The ContactPersonHandler instance under test
     *
     * @var ContactPersonHandler
     */
    private ContactPersonHandler $contactPersonHandler;

    /**
     * Set up the test environment before each test
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create mocks
        $this->userManager = $this->createMock(IUserManager::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->settingsService = $this->createMock(SettingsService::class);

        // Configure container to return settings service
        $this->container->method('get')
            ->with('OCA\SoftwareCatalog\Service\SettingsService')
            ->willReturn($this->settingsService);

        // Create the ContactPersonHandler instance
        $this->contactPersonHandler = new ContactPersonHandler(
            $this->userManager,
            $this->createMock(ISecureRandom::class),
            $this->groupManager,
            $this->createMock(IAppConfig::class),
            $this->container,
            $this->createMock(IAppManager::class),
            $this->logger,
            $this->createMock(SymfonyEmailService::class),
            $this->createMock(IConfig::class)
        );
    }

    /**
     * Test organization type to role group mapping
     *
     * This test verifies that the getRoleGroupByOrganizationType method
     * correctly maps organization types to role groups according to business rules.
     *
     * @return void
     */
    public function testGetRoleGroupByOrganizationType(): void
    {
        // Create a minimal ContactPersonHandler for testing
        $handler = $this->createPartialMock(ContactPersonHandler::class, []);
        
        // Use reflection to access the private method
        $reflection = new ReflectionClass($handler);
        $method = $reflection->getMethod('getRoleGroupByOrganizationType');
        $method->setAccessible(true);

        // Test case 1: Gemeente -> gebruik-beheerder
        $result = $method->invoke($handler, 'Gemeente');
        $this->assertEquals('gebruik-beheerder', $result, 'Gemeente should map to gebruik-beheerder');

        // Test case 2: gemeente (lowercase) -> gebruik-beheerder
        $result = $method->invoke($handler, 'gemeente');
        $this->assertEquals('gebruik-beheerder', $result, 'gemeente (lowercase) should map to gebruik-beheerder');

        // Test case 3: Leverancier -> aanbod-beheerder
        $result = $method->invoke($handler, 'Leverancier');
        $this->assertEquals('aanbod-beheerder', $result, 'Leverancier should map to aanbod-beheerder');

        // Test case 4: leverancier (lowercase) -> aanbod-beheerder
        $result = $method->invoke($handler, 'leverancier');
        $this->assertEquals('aanbod-beheerder', $result, 'leverancier (lowercase) should map to aanbod-beheerder');

        // Test case 5: Samenwerking -> gebruik-beheerder
        $result = $method->invoke($handler, 'Samenwerking');
        $this->assertEquals('gebruik-beheerder', $result, 'Samenwerking should map to gebruik-beheerder');

        // Test case 6: samenwerking (lowercase) -> gebruik-beheerder
        $result = $method->invoke($handler, 'samenwerking');
        $this->assertEquals('gebruik-beheerder', $result, 'samenwerking (lowercase) should map to gebruik-beheerder');

        // Test case 7: Community -> aanbod-beheerder
        $result = $method->invoke($handler, 'Community');
        $this->assertEquals('aanbod-beheerder', $result, 'Community should map to aanbod-beheerder');

        // Test case 8: community (lowercase) -> aanbod-beheerder
        $result = $method->invoke($handler, 'community');
        $this->assertEquals('aanbod-beheerder', $result, 'community (lowercase) should map to aanbod-beheerder');

        // Test case 9: Unknown organization type -> empty string
        $result = $method->invoke($handler, 'UnknownType');
        $this->assertEquals('', $result, 'Unknown organization type should return empty string');

        // Test case 10: Empty string -> empty string
        $result = $method->invoke($handler, '');
        $this->assertEquals('', $result, 'Empty organization type should return empty string');

        // Test case 11: Whitespace handling
        $result = $method->invoke($handler, '  Gemeente  ');
        $this->assertEquals('gebruik-beheerder', $result, 'Organization type with whitespace should be trimmed and mapped correctly');
    }

    /**
     * Test addUserToGroupWithCheck method behavior
     *
     * This test verifies that the addUserToGroupWithCheck method
     * only adds users to existing groups and does not create new groups.
     *
     * @return void
     */
    public function testAddUserToGroupWithCheck(): void
    {
        // Create mocks
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');

        $existingGroup = $this->createMock(IGroup::class);
        $existingGroup->method('inGroup')->with($user)->willReturn(false);

        // Use reflection to access the private method
        $reflection = new ReflectionClass($this->contactPersonHandler);
        $method = $reflection->getMethod('addUserToGroupWithCheck');
        $method->setAccessible(true);

        // Test case 1: Group exists, user not in group - should add user
        $this->groupManager->expects($this->once())
            ->method('get')
            ->with('existing-group')
            ->willReturn($existingGroup);

        $existingGroup->expects($this->once())
            ->method('addUser')
            ->with($user);

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                'Added user to existing group',
                $this->callback(function ($context) {
                    return $context['username'] === 'testuser' &&
                           $context['groupName'] === 'existing-group' &&
                           $context['type'] === 'test-type';
                })
            );

        $method->invoke($this->contactPersonHandler, $user, 'existing-group', 'test-type');

        // Test case 2: Group does not exist - should log warning and not create group
        $this->groupManager->expects($this->once())
            ->method('get')
            ->with('non-existing-group')
            ->willReturn(null);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Group does not exist, skipping user assignment',
                $this->callback(function ($context) {
                    return $context['username'] === 'testuser' &&
                           $context['groupName'] === 'non-existing-group' &&
                           $context['type'] === 'test-type';
                })
            );

        $method->invoke($this->contactPersonHandler, $user, 'non-existing-group', 'test-type');
    }

    /**
     * Clean up after each test
     *
     * @return void
     */
    protected function tearDown(): void
    {
        parent::tearDown();
    }
}
