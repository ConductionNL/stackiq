<?php

/**
 * Regression tests for GebruikService::getApplicationIds.
 *
 * Covers SB2: runtime fatal for aanbod-beheerder users caused by calling
 * getObject() on an already-serialized array after jsonSerialize().
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\SoftwareCatalog\Service\GebruikService;
use OCA\SoftwareCatalog\Service\SettingsService;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for getApplicationIds serialisation safety (SB2).
 *
 * @category Test
 * @package  OCA\SoftwareCatalog\Tests\Unit\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://codeberg.org/Conduction/SoftwareCatalog
 */
class GebruikServiceGetApplicationIdsTest extends TestCase
{

    /** @var SettingsService|MockObject */
    private SettingsService|MockObject $settingsService;

    /** @var IAppManager|MockObject */
    private IAppManager|MockObject $appManager;

    /** @var ContainerInterface|MockObject */
    private ContainerInterface|MockObject $container;

    /** @var ObjectService|MockObject */
    private ObjectService|MockObject $objectService;

    /** @var LoggerInterface|MockObject */
    private LoggerInterface|MockObject $logger;

    private GebruikService $service;


    /**
     * Set up mocks and the service under test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->settingsService = $this->createMock(SettingsService::class);
        $this->appManager      = $this->createMock(IAppManager::class);
        $this->objectService   = $this->createMock(ObjectService::class);
        $this->logger          = $this->createMock(LoggerInterface::class);
        $this->container       = $this->createMock(ContainerInterface::class);

        // openregister is "installed".
        $this->appManager
            ->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->container
            ->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($this->objectService);

        // SettingsService returns a minimal voorzieningen config.
        $this->settingsService
            ->method('getVoorzieningenConfig')
            ->willReturn(
                [
                    'register'      => 'reg-1',
                    'gebruik_schema' => 'schema-gebruik',
                    'module_schema'  => 'schema-module',
                ]
            );

        $this->service = new GebruikService(
            $this->settingsService,
            $this->appManager,
            $this->container,
            $this->logger
        );

    }//end setUp()


    /**
     * SB2-regression: when ObjectService returns ObjectEntity instances,
     * getApplicationIds must NOT call getObject() on the already-serialized
     * array and must return the id from @self.id.
     *
     * Before the fix this threw "Call to a member function getObject() on array".
     *
     * @return void
     */
    public function testGetApplicationIdsWithObjectEntityReturnsIds(): void
    {
        $uuid = 'applic-uuid-1234';

        // Build a mock ObjectEntity whose jsonSerialize() returns @self metadata.
        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity
            ->method('jsonSerialize')
            ->willReturn(
                [
                    '@self' => ['id' => $uuid],
                    'naam'  => 'TestApplication',
                ]
            );

        // getObject() must NOT be called — if it is the test would fail via
        // the unexpected-call expectation below.
        $objectEntity->expects($this->never())->method('getObject');

        $this->objectService
            ->method('searchObjectsPaginated')
            ->willReturn(['results' => [$objectEntity]]);

        $result = $this->service->getApplicationIds([]);

        $this->assertSame([$uuid], $result);

    }//end testGetApplicationIdsWithObjectEntityReturnsIds()


    /**
     * SB2-regression: when ObjectService returns plain arrays (already
     * serialized), getApplicationIds must return the ids without any method
     * call on the item.
     *
     * @return void
     */
    public function testGetApplicationIdsWithArrayResultsReturnsIds(): void
    {
        $uuid = 'applic-uuid-5678';

        $arrayResult = [
            '@self' => ['id' => $uuid],
            'naam'  => 'TestApplication2',
        ];

        $this->objectService
            ->method('searchObjectsPaginated')
            ->willReturn(['results' => [$arrayResult]]);

        $result = $this->service->getApplicationIds([]);

        $this->assertSame([$uuid], $result);

    }//end testGetApplicationIdsWithArrayResultsReturnsIds()


}//end class
