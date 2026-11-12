<?php

/**
 * Golden test for ItsmFileImportService::import().
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\Itsm\ItsmFlowGateway;
use OCA\Stackiq\Service\ItsmFileImportService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the outcomes of importing an ITSM exchange file.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 */
class ItsmFileImportServiceImportGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/itsm/file-import.json';

	private const FLOW = '{"flows":{"file":"flow-1"}}';

	/**
	 * Temporary files made by a case.
	 *
	 * @var array<int, string>
	 */
	private array $tmp = [];

	/**
	 * Remove the temporary files.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->tmp as $file) {
			@unlink($file);
		}

	}//end tearDown()

	/**
	 * Write a temporary file.
	 *
	 * @param string   $content The content.
	 * @param int|null $size    Grow the file to this size instead.
	 *
	 * @return string The path.
	 */
	private function file(string $content, ?int $size = null): string {
		$path = tempnam(sys_get_temp_dir(), 'itsm');
		file_put_contents($path, $content);
		if ($size !== null) {
			$handle = fopen($path, 'r+');
			ftruncate($handle, $size);
			fclose($handle);
		}

		$this->tmp[] = $path;
		return $path;
	}//end file()

	/**
	 * The cases: stored config, file, upload name and gateway failure.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$good = "recordId,name\nr1,Alpha\nr2,Beta\n";
		return [
			'config not json'    => ['config' => 'nope', 'path' => $this->file($good), 'name' => 'a.csv'],
			'config not object'  => ['config' => '"x"', 'path' => $this->file($good), 'name' => 'a.csv'],
			'no flows'           => ['config' => '{}', 'path' => $this->file($good), 'name' => 'a.csv'],
			'flows a string'     => ['config' => '{"flows":"abc"}', 'path' => $this->file($good), 'name' => 'a.csv'],
			'empty flow'         => ['config' => '{"flows":{"file":""}}', 'path' => $this->file($good), 'name' => 'a.csv'],
			'numeric flow'       => ['config' => '{"flows":{"file":7}}', 'path' => $this->file($good), 'name' => 'a.csv'],
			'too large'          => ['config' => self::FLOW, 'path' => $this->file($good, 10485761), 'name' => 'a.csv'],
			'exactly the limit'  => ['config' => self::FLOW, 'path' => $this->file("recordId\n", 10485760), 'name' => 'a.csv'],
			'missing file'       => ['config' => self::FLOW, 'path' => '/nonexistent/itsm.csv', 'name' => 'a.csv'],
			'unknown extension'  => ['config' => self::FLOW, 'path' => $this->file($good), 'name' => 'a.txt'],
			'header only'        => ['config' => self::FLOW, 'path' => $this->file("recordId,name\n"), 'name' => 'a.csv'],
			'row without key'    => ['config' => self::FLOW, 'path' => $this->file("recordId,name\nr1,A\n,B\n"), 'name' => 'a.csv'],
			'semicolon file'     => ['config' => self::FLOW, 'path' => $this->file("recordId;name\nr1;A\n"), 'name' => 'A.CSV'],
			'starts'             => ['config' => self::FLOW, 'path' => $this->file($good), 'name' => 'a.csv'],
			'gateway fails'      => ['config' => self::FLOW, 'path' => $this->file($good), 'name' => 'a.csv', 'fail' => true],
		];
	}//end cases()

	/**
	 * Run one case and record the outcome.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function record(array $case): array {
		$trace   = [];
		$gateway = $this->createMock(ItsmFlowGateway::class);
		$gateway->method('run')->willReturnCallback(function (string $uuid, array $payload) use (&$trace, $case): string {
			$trace[] = ['run', $uuid, $payload];
			if (($case['fail'] ?? false) === true) {
				throw new \RuntimeException('engine down');
			}

			return 'run-1';
		});
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(function (...$args) use (&$trace, $case): string {
			$trace[] = ['getValueString', ...$args];
			return $case['config'];
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(function (string $message, array $context = []) use (&$trace): void {
			$trace[] = ['error', $message, get_class($context['exception']), $context['exception']->getMessage()];
		});

		$result = (new ItsmFileImportService($gateway, $config, $logger))->import($case['path'], $case['name']);

		return ['result' => $result, 'trace' => $trace];
	}//end record()

	/**
	 * Every case matches the recorded outcome.
	 *
	 * @return void
	 */
	public function testImportMatchesGolden(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$actual[$name] = $this->record(case: $case);
		}

		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$this->assertSame(json_decode((string) file_get_contents(self::FIXTURE), true), $actual);

	}//end testImportMatchesGolden()
}//end class
