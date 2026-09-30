<?php

/**
 * Existing applications and services get the record status Active.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Repair
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-004-merged-applications-and-services-shall-leave-the-lists-and-point-readers-to-the-survivor
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Repair;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Stackiq\Repair\BackfillRecordStatus;
use OCA\Stackiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The list filter hides a record whose status is Merged; SQL's `<>` also drops
 * a record with no status at all, so every existing row needs one.
 */
class BackfillRecordStatusTest extends TestCase {

	/**
	 * An object entity double.
	 *
	 * @param string               $uuid   The id.
	 * @param int                  $schema The schema id.
	 * @param array<string, mixed> $data   The object data.
	 *
	 * @return ObjectEntity The double.
	 */
	private function entity(string $uuid, int $schema, array $data): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getUuid')->willReturn($uuid);
		$entity->method('getSchema')->willReturn((string) $schema);
		$entity->method('getRegister')->willReturn('5');
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * Rows without a status become Active; Active and Merged rows are left alone.
	 *
	 * @return void
	 */
	public function testEveryApplicationAndServiceGetsAStatus(): void {
		$objects = [
			7 => [
				$this->entity('m1', 7, ['name' => 'Zaaksysteem']),
				$this->entity('m2', 7, ['name' => 'Other', 'recordStatus' => 'Active']),
				$this->entity('m3', 7, ['name' => 'Dup', 'recordStatus' => 'Merged']),
			],
			8 => [
				$this->entity('s1', 8, ['name' => 'Hosting', 'recordStatus' => '']),
			],
		];
		$saved = [];

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjects')->willReturnCallback(fn (array $query=[]): array => $objects[(int) $query['schema']] ?? []);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend=[], $register=null, $schema=null, $uuid=null) use (&$saved): ObjectEntity {
				$saved[(string) $uuid] = $object;
				return $this->createMock(ObjectEntity::class);
			}
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getRegisterIdForObjectType')->willReturn(5);
		$settings->method('getSchemaIdForObjectType')->willReturnCallback(fn (string $t): ?int => ['module' => 7, 'catalogService' => 8][$t] ?? null);

		(new BackfillRecordStatus($settings, $this->createMock(LoggerInterface::class)))->run($this->createMock(IOutput::class));

		$this->assertSame(['m1', 's1'], array_keys($saved));
		$this->assertSame('Active', $saved['m1']['recordStatus']);
		$this->assertSame('Zaaksysteem', $saved['m1']['name']);
		$this->assertSame('Active', $saved['s1']['recordStatus']);
	}//end testEveryApplicationAndServiceGetsAStatus()

	/**
	 * Without OpenRegister the step does nothing and does not throw.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingHappens(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		(new BackfillRecordStatus($settings, $this->createMock(LoggerInterface::class)))->run($this->createMock(IOutput::class));
		$this->addToAssertionCount(1);
	}//end testWithoutOpenRegisterNothingHappens()
}//end class
