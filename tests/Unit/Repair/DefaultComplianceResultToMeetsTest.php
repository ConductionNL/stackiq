<?php

/**
 * Unit tests for the DefaultComplianceResultToMeets repair step.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Repair
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/bio-measures-shipped-and-not-met/specs/bio-compliance-assessment/spec.md#requirement-a-compliance-claim-says-whether-the-application-meets-the-measure-req-bio-011
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Repair;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Repair\DefaultComplianceResultToMeets;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Existing claims get result=meets once; claims with a result are left alone.
 *
 * @covers \OCA\Stackiq\Repair\DefaultComplianceResultToMeets
 */
class DefaultComplianceResultToMeetsTest extends TestCase {

	/**
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $apps;

	/**
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * @var IOutput&MockObject
	 */
	private IOutput&MockObject $output;

	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->apps     = $this->createMock(originalClassName: IAppManager::class);
		$this->settings = $this->createMock(originalClassName: SettingsService::class);
		$this->logger   = $this->createMock(originalClassName: LoggerInterface::class);
		$this->output   = $this->createMock(originalClassName: IOutput::class);
		$this->objects  = $this->createMock(originalClassName: ObjectServiceInterface::class);
	}//end setUp()

	/**
	 * Run the step; every run opens and closes its progress bar exactly once.
	 *
	 * @return void
	 */
	private function runStep(): void {
		$this->output->expects($this->once())->method('startProgress')->with(1);
		$this->output->expects($this->once())->method('advance')->with(1);
		$this->output->expects($this->once())->method('finishProgress');
		(new DefaultComplianceResultToMeets(appManager: $this->apps, settingsService: $this->settings, logger: $this->logger))->run($this->output);
	}//end runStep()

	/**
	 * OpenRegister installed and the compliancy register/schema configured.
	 *
	 * @return void
	 */
	private function configured(): void {
		$this->apps->method('getInstalledApps')->willReturn(['openregister', 'stackiq']);
		$this->settings->method('getVoorzieningenRegisterId')->willReturn(5);
		$this->settings->method('getSchemaIdForObjectType')->with('compliancy')->willReturn(9);
		$this->settings->method('getObjectService')->willReturn($this->objects);
		$this->objects->method('setRegister')->with(5)->willReturnSelf();
		$this->objects->method('setSchema')->with(9)->willReturnSelf();
	}//end configured()

	/**
	 * A claim double.
	 *
	 * @param string              $uuid The uuid.
	 * @param array<string,mixed> $data The body.
	 *
	 * @return ObjectEntityInterface
	 */
	private function claim(string $uuid, array $data): ObjectEntityInterface {
		$entity = $this->createMock(originalClassName: ObjectEntityInterface::class);
		$entity->method('getUuid')->willReturn($uuid);
		$entity->method('getObject')->willReturn($data);
		$entity->method('getRegister')->willReturn('5');
		$entity->method('getSchema')->willReturn('9');
		return $entity;
	}//end claim()

	/**
	 * The step names what it does.
	 *
	 * @return void
	 */
	public function testName(): void {
		$step = new DefaultComplianceResultToMeets(appManager: $this->apps, settingsService: $this->settings, logger: $this->logger);
		$this->assertSame(expected: 'Default the result of existing compliance claims to meets', actual: $step->getName());
	}//end testName()

	/**
	 * Scenario "Existing claims": every claim without a result gets meets, with its other fields unchanged,
	 * and a claim that already says does-not-meet or meets is not saved.
	 *
	 * @return void
	 */
	public function testOnlyClaimsWithoutAResultAreDefaulted(): void {
		$this->configured();
		$this->objects->method('findAll')->with([], false, false)->willReturn(
			[
				$this->claim(uuid: 'old', data: ['module' => 'm-1', 'bioMeasure' => 'b-1', 'url' => 'https://example.org/report']),
				$this->claim(uuid: 'blank', data: ['module' => 'm-2', 'result' => '  ']),
				$this->claim(uuid: 'not-met', data: ['module' => 'm-3', 'result' => 'does-not-meet']),
				$this->claim(uuid: 'met', data: ['module' => 'm-4', 'result' => 'meets']),
			]
		);

		$saved = [];
		$this->objects->expects($this->exactly(count: 2))->method('saveObject')->willReturnCallback(
			function (array $object, array $extend, $register, $schema, $uuid, bool $_rbac, bool $_multitenancy) use (&$saved) {
				$saved[$uuid] = [$object, $register, $schema, $_rbac, $_multitenancy];
				return $this->claim(uuid: $uuid, data: $object);
			}
		);
		$this->output->expects($this->once())->method('info')
			->with('Compliance result backfill: 2 claim(s) defaulted to meets');

		$this->runStep();

		$this->assertSame(
			expected: [
				'old' => [['module' => 'm-1', 'bioMeasure' => 'b-1', 'url' => 'https://example.org/report', 'result' => 'meets'], '5', '9', false, false],
				'blank' => [['module' => 'm-2', 'result' => 'meets'], '5', '9', false, false],
			],
			actual: $saved
		);
	}//end testOnlyClaimsWithoutAResultAreDefaulted()

	/**
	 * A second run over claims that all carry a result writes nothing.
	 *
	 * @return void
	 */
	public function testASecondRunChangesNothing(): void {
		$this->configured();
		$this->objects->method('findAll')->willReturn([$this->claim(uuid: 'a', data: ['result' => 'meets'])]);
		$this->objects->expects($this->never())->method('saveObject');
		$this->output->expects($this->once())->method('info')
			->with('Compliance result backfill: 0 claim(s) defaulted to meets');

		$this->runStep();
	}//end testASecondRunChangesNothing()

	/**
	 * A claim that cannot be saved is logged and the others still get their result.
	 *
	 * @return void
	 */
	public function testAFailingSaveIsLoggedAndTheRestContinue(): void {
		$this->configured();
		$this->objects->method('findAll')->willReturn([$this->claim(uuid: 'bad', data: []), $this->claim(uuid: 'good', data: [])]);
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object, array $extend, $register, $schema, $uuid) {
				if ($uuid === 'bad') {
					throw new RuntimeException('locked');
				}

				return $this->claim(uuid: $uuid, data: $object);
			}
		);
		$this->logger->expects($this->once())->method('error')
			->with('[DefaultComplianceResultToMeets] Failed to default a claim', ['uuid' => 'bad', 'error' => 'locked']);
		$this->output->expects($this->once())->method('info')
			->with('Compliance result backfill: 1 claim(s) defaulted to meets');

		$this->runStep();
	}//end testAFailingSaveIsLoggedAndTheRestContinue()

	/**
	 * Without OpenRegister, or without the register or schema, nothing is read.
	 *
	 * @return void
	 */
	public function testSkipsWithoutOpenRegisterOrConfiguration(): void {
		$this->apps->method('getInstalledApps')->willReturn(['stackiq']);
		$this->settings->expects($this->never())->method('getVoorzieningenRegisterId');
		$this->output->expects($this->once())->method('info')
			->with('OpenRegister not installed — skipping the compliance result backfill');

		$this->runStep();
	}//end testSkipsWithoutOpenRegisterOrConfiguration()

	/**
	 * A failing read warns and saves nothing.
	 *
	 * @return void
	 */
	public function testAFailingReadWarnsAndSavesNothing(): void {
		$this->configured();
		$this->objects->method('findAll')->willThrowException(new RuntimeException('timeout'));
		$this->objects->expects($this->never())->method('saveObject');
		$this->output->expects($this->once())->method('warning')->with('Could not read compliance claims: timeout');
		$this->output->expects($this->never())->method('info');

		$this->runStep();
	}//end testAFailingReadWarnsAndSavesNothing()

	/**
	 * The compliancy schema declares the result with both answers and meets as default, in a bumped version.
	 *
	 * @return void
	 */
	public function testTheSchemaDeclaresTheResult(): void {
		$register = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/softwarecatalogus_register.json'), true, 512, JSON_THROW_ON_ERROR);
		$schema   = $register['components']['schemas']['compliancy'];

		$this->assertSame(expected: ['meets', 'does-not-meet'], actual: $schema['properties']['result']['enum']);
		$this->assertSame(expected: DefaultComplianceResultToMeets::DEFAULT_RESULT, actual: $schema['properties']['result']['default']);
		$this->assertTrue(condition: version_compare($schema['version'], '0.0.12', '>'));
	}//end testTheSchemaDeclaresTheResult()

}//end class
