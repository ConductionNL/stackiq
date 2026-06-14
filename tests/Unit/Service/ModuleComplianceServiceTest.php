<?php
/**
 * Unit tests for ModuleComplianceService.
 *
 * Covers the standards-derivation core that the compliance subscriber depends
 * on: UUID extraction across the lenient relation shapes, deduplication, the
 * unresolved-relation (no standaardversie) case, and the order-independent
 * loop guard that prevents an event-save loop.
 *
 * @category  Tests
 * @package   OCA\SoftwareCatalog\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/module-compliance-assessment/specs/module-compliance-assessment/spec.md
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use OCA\SoftwareCatalog\Service\ModuleComplianceService;
use OCA\SoftwareCatalog\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Test class for ModuleComplianceService standards-derivation logic.
 */
class ModuleComplianceServiceTest extends TestCase
{
    /**
     * @var ContainerInterface|MockObject
     */
    private ContainerInterface|MockObject $container;

    /**
     * @var SettingsService|MockObject
     */
    private SettingsService|MockObject $settingsService;

    /**
     * @var LoggerInterface|MockObject
     */
    private LoggerInterface|MockObject $logger;

    /**
     * @var ModuleComplianceService
     */
    private ModuleComplianceService $service;

    /**
     * Set up the service with mocked collaborators.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->container       = $this->createMock(ContainerInterface::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->service = new ModuleComplianceService(
            $this->container,
            $this->settingsService,
            $this->logger
        );
    }//end setUp()

    /**
     * Invoke a private method via reflection.
     *
     * @param string $method The method name.
     * @param array  $args   Positional arguments.
     *
     * @return mixed The method return value.
     */
    private function invoke(string $method, array $args): mixed
    {
        $reflection = new ReflectionMethod(ModuleComplianceService::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($this->service, $args);
    }//end invoke()

    /**
     * Build a fake compliance object exposing getObject()/getId().
     *
     * @param array $data The object data bag.
     * @param mixed $id   The object id.
     *
     * @return object A stub compliance object.
     */
    private function complianceObject(array $data, mixed $id = 1): object
    {
        return new class($data, $id) {
            /**
             * @param array $data The data bag.
             * @param mixed $id   The id.
             */
            public function __construct(private array $data, private mixed $id)
            {
            }

            /**
             * @return array The object data.
             */
            public function getObject(): array
            {
                return $this->data;
            }

            /**
             * @return mixed The object id.
             */
            public function getId(): mixed
            {
                return $this->id;
            }
        };
    }//end complianceObject()

    /**
     * Extraction resolves string, array, and object standaardversie shapes.
     *
     * @return void
     */
    public function testExtractResolvesAllRelationShapes(): void
    {
        $objects = [
            $this->complianceObject(['standaardversie' => 'uuid-string'], 1),
            $this->complianceObject(['standaardversie' => ['uuid' => 'uuid-array']], 2),
            $this->complianceObject(['standaardversie' => (object) ['uuid' => 'uuid-object']], 3),
        ];

        $result = $this->invoke('extractStandaardversieUuids', [$objects]);
        sort($result);

        $this->assertSame(['uuid-array', 'uuid-object', 'uuid-string'], $result);
    }//end testExtractResolvesAllRelationShapes()

    /**
     * Extraction deduplicates repeated standaardversie UUIDs.
     *
     * @return void
     */
    public function testExtractDeduplicates(): void
    {
        $objects = [
            $this->complianceObject(['standaardversie' => 'dup'], 1),
            $this->complianceObject(['standaardversie' => 'dup'], 2),
            $this->complianceObject(['standaardversie' => 'unique'], 3),
        ];

        $result = array_values($this->invoke('extractStandaardversieUuids', [$objects]));
        sort($result);

        $this->assertSame(['dup', 'unique'], $result);
    }//end testExtractDeduplicates()

    /**
     * A compliance record with no standaardversie relation contributes nothing
     * (unresolved-relation handling — never invents a column).
     *
     * @return void
     */
    public function testExtractSkipsRecordsWithoutStandaardversie(): void
    {
        $objects = [
            $this->complianceObject(['standaardGemma' => 'GEMMA-ONLY'], 1),
            $this->complianceObject(['standaardversie' => 'resolved'], 2),
        ];

        $result = array_values($this->invoke('extractStandaardversieUuids', [$objects]));

        $this->assertSame(['resolved'], $result);
    }//end testExtractSkipsRecordsWithoutStandaardversie()

    /**
     * The loop guard is order-independent: the same set in a different order
     * is NOT considered different (no re-save).
     *
     * @return void
     */
    public function testLoopGuardIgnoresOrder(): void
    {
        $different = $this->invoke('arraysAreDifferent', [['a', 'b', 'c'], ['c', 'b', 'a']]);
        $this->assertFalse($different, 'Same set in different order must not be flagged as different');
    }//end testLoopGuardIgnoresOrder()

    /**
     * The loop guard detects a genuinely changed set.
     *
     * @return void
     */
    public function testLoopGuardDetectsChange(): void
    {
        $this->assertTrue($this->invoke('arraysAreDifferent', [['a', 'b'], ['a', 'b', 'c']]));
        $this->assertTrue($this->invoke('arraysAreDifferent', [['a'], []]));
    }//end testLoopGuardDetectsChange()

    /**
     * normaliseCurrentStandaarden coerces non-array stored values to [].
     *
     * @return void
     */
    public function testNormaliseCoercesNonArrayToEmpty(): void
    {
        $this->assertSame([], $this->invoke('normaliseCurrentStandaarden', [null]));
        $this->assertSame([], $this->invoke('normaliseCurrentStandaarden', ['scalar']));
        $this->assertSame(['x'], $this->invoke('normaliseCurrentStandaarden', [['x']]));
    }//end testNormaliseCoercesNonArrayToEmpty()
}//end class
