<?php

/**
 * Golden test for getAmefConfig of ArchiMateService and ArchiMateImportService.
 *
 * Records, per case and per service, the configuration returned and every
 * config read and log line: stored JSON, malformed JSON with some ids set and
 * others empty or blank, and a config read that throws.
 * Recorded with RECORD_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/archimate-import/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\ArchiMateImportService;
use OCA\Stackiq\Service\ArchiMateService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Golden AMEF configuration reads.
 *
 * @spec openspec/specs/archimate-import/spec.md
 */
class ArchiMateAmefConfigGoldenTest extends TestCase {

	/**
	 * Everything the doubles saw, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $trace = [];

	/**
	 * The golden file.
	 *
	 * @return string
	 */
	private static function golden(): string {
		return __DIR__ . '/golden/archiMateAmefConfig.json';

	}//end golden()

	/**
	 * The cases: service class and stored config values ('throw' makes every read throw).
	 *
	 * @return array<string, array{0: class-string, 1: array<string, string>|string}>
	 */
	public static function cases(): array {
		$partial = ['amef_config' => 'not-json', 'amef_register' => '11', 'amef_views_schema' => '  ', 'amef_elements_schema' => '7'];
		$cases   = [];
		foreach ([ArchiMateService::class, ArchiMateImportService::class] as $class) {
			$short = substr(string: $class, offset: (strrpos(haystack: $class, needle: '\\') + 1));
			$cases[$short . ' stored json']    = [$class, ['amef_config' => '{"register_id":"3","views_schema":"9"}']];
			$cases[$short . ' default']        = [$class, []];
			$cases[$short . ' malformed json'] = [$class, $partial];
			$cases[$short . ' read throws']    = [$class, 'throw'];
		}

		return $cases;

	}//end cases()

	/**
	 * GetAmefConfig observes and answers as recorded.
	 *
	 * @param class-string         $class  The service.
	 * @param array<string, string>|string $values The stored values, or 'throw'.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testConfigMatchesTheRecording(string $class, array|string $values): void {
		$this->trace = [];
		$result      = $this->service(class: $class, values: $values)->getAmefConfig();

		$this->check(key: (string) $this->dataName(), value: ['result' => $result, 'calls' => $this->trace]);

	}//end testConfigMatchesTheRecording()

	/**
	 * The service with only the config and logger it reads the AMEF configuration through.
	 *
	 * @param class-string                 $class  The service.
	 * @param array<string, string>|string $values The stored values, or 'throw'.
	 *
	 * @return object
	 */
	private function service(string $class, array|string $values): object {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($values): string {
				$this->trace[] = ['config.getValueString', $app, $key, $default];
				if ($values === 'throw') {
					throw new \RuntimeException('config down');
				}

				return $values[$key] ?? $default;
			}
		);

		$service = (new \ReflectionClass(objectOrClass: $class))->newInstanceWithoutConstructor();
		foreach (['config' => $config, 'logger' => $this->logger()] as $prop => $value) {
			(new \ReflectionProperty(class: $class, property: $prop))->setValue($service, $value);
		}

		return $service;

	}//end service()

	/**
	 * A logger that records every line.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		$trace = &$this->trace;
		return new class($trace) extends AbstractLogger {
			/**
			 * Build the logger.
			 *
			 * @param array $trace The shared trace.
			 */
			public function __construct(private array &$trace) {
			}

			/**
			 * Record one line.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array              $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->trace[] = ['log.' . $level, (string) $message, $context];
			}
		};

	}//end logger()

	/**
	 * Compare a case with the golden file, or record it.
	 *
	 * @param string $key   The case.
	 * @param array  $value What the case observed.
	 *
	 * @return void
	 */
	private function check(string $key, array $value): void {
		$json  = json_decode(json: (string) json_encode(value: $value), associative: true);
		$file  = self::golden();
		$saved = [];
		if (file_exists(filename: $file) === true) {
			$saved = json_decode(json: (string) file_get_contents(filename: $file), associative: true);
		}

		if (getenv(name: 'RECORD_GOLDEN') !== false) {
			$saved[$key] = $json;
			file_put_contents(filename: $file, data: json_encode(value: $saved, flags: (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
			$this->addToAssertionCount(count: 1);
			return;
		}

		$this->assertArrayHasKey(key: $key, array: $saved, message: 'No golden for ' . $key);
		$this->assertSame(expected: $saved[$key], actual: $json);

	}//end check()

}//end class
