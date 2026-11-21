<?php

/**
 * Golden test for the model objects ArchiMateImportService builds.
 *
 * Records, per case, the object createModelObject and createModelObjectDirect
 * build from the model metadata (name as XML value, plain string or other,
 * the xml copy of name, documentation, properties and the property
 * definition map) and every organisation and log call. The publication
 * time is checked for its format and then masked.
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\Stackiq\Service\ArchiMateImportService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Golden model objects.
 *
 * @spec openspec/specs/archimate-import/spec.md
 */
class ArchiMateImportModelObjectGoldenTest extends TestCase {

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
		return __DIR__ . '/golden/archiMateImportModelObject.json';

	}//end golden()

	/**
	 * The cases: builder method and model metadata.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function cases(): array {
		$full     = [
			'identifier' => 'id-model',
			'name' => ['_value' => 'Model A', 'lang' => 'nl'],
			'documentation' => ['_value' => 'Doc'],
			'properties' => ['property' => [['ref' => 'p1']]],
			'propertyDefinitionMap' => ['p1' => 'Owner'],
			'extra' => 'kept',
		];
		$metadata = [
			'full'               => $full,
			'string name'        => ['identifier' => 'id-model', 'name' => 'Model B'],
			'name without value' => ['identifier' => 'id-model', 'name' => ['lang' => 'nl']],
			'numeric name'       => ['identifier' => 'id-model', 'name' => 7],
			'documentation only' => ['identifier' => 'id-model', 'documentation' => 'Doc'],
			'properties only'    => ['identifier' => 'id-model', 'properties' => ['p' => 1], 'propertyDefinitionMap' => []],
		];
		$cases    = [];
		foreach (['createModelObject', 'createModelObjectDirect'] as $method) {
			foreach ($metadata as $name => $value) {
				$cases[$method . ' ' . $name] = [$method, $value];
			}
		}

		return $cases;

	}//end cases()

	/**
	 * The builder answers and observes as recorded.
	 *
	 * @param string               $method   The builder.
	 * @param array<string, mixed> $metadata The model metadata.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testModelObjectMatchesTheRecording(string $method, array $metadata): void {
		$this->trace = [];
		$builder     = new \ReflectionMethod(objectOrMethod: ArchiMateImportService::class, method: $method);
		$object      = $builder->invoke($this->service(), $metadata, 'model-1');

		$this->assertMatchesRegularExpression(pattern: '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', string: $object['@self']['published']);
		$object['@self']['published'] = 'masked';
		$this->check(key: (string) $this->dataName(), value: ['result' => $object, 'calls' => $this->trace]);

	}//end testModelObjectMatchesTheRecording()

	/**
	 * The service with its cached configuration, organisation service and logger.
	 *
	 * @return ArchiMateImportService
	 */
	private function service(): ArchiMateImportService {
		$organisation = new Organisation();
		$organisation->setUuid('org-default');
		$organisations = $this->createMock(originalClassName: OrganisationService::class);
		$organisations->method('ensureDefaultOrganisation')->willReturnCallback(
			function () use ($organisation): Organisation {
				$this->trace[] = ['organisations.ensureDefaultOrganisation'];
				return $organisation;
			}
		);

		$service = (new \ReflectionClass(objectOrClass: ArchiMateImportService::class))->newInstanceWithoutConstructor();
		$values  = [
			'organisationService' => $organisations,
			'logger' => $this->logger(),
			'cachedConfig' => ['registerId' => 5, 'schemaIds' => ['model' => 9], 'userId' => 'admin', 'organisation' => 'org-cached'],
		];
		foreach ($values as $prop => $value) {
			(new \ReflectionProperty(class: ArchiMateImportService::class, property: $prop))->setValue($service, $value);
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
