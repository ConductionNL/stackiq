<?php

/**
 * Golden test for OrganisatieService::createOrganisationInOpenRegister().
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Organisation\NewOrganisation;
use OCA\Stackiq\Service\OrganisatieService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

if (class_exists('OCA\\OpenRegister\\Exception\\OrganisationSlugConflictException') === false) {
	require_once __DIR__ . '/../../Stubs/OpenRegister/Exception/OrganisationSlugConflictException.php';
}

/**
 * Pins creating an organisation entity in OpenRegister.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 */
final class OrganisatieServiceCreateGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/organisatie/create.json';

	/**
	 * The cases.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function cases(): array {
		$full = ['id' => 'org-1', 'name' => 'Gemeente A', 'type' => 'Gemeente', 'website' => 'https://a.nl', 'status' => 'actief'];
		return [
			'no id'              => ['data' => ['name' => 'Gemeente A']],
			'empty id'           => ['data' => ['id' => '', 'name' => 'Gemeente A']],
			'zero id'            => ['data' => ['id' => '0', 'name' => 'Gemeente A']],
			'openregister off'   => ['data' => $full, 'enabled' => false],
			'service missing'    => ['data' => $full, 'serviceFails' => true],
			'created'            => ['data' => $full],
			'created no name'    => ['data' => ['id' => 'org-2', 'beoordeling' => 'inactief']],
			'self parent'        => ['data' => $full, 'active' => 'org-1'],
			'active lookup fails' => ['data' => $full, 'activeFails' => true],
			'create fails'       => ['data' => $full, 'createFails' => new \RuntimeException('db down')],
			'slug taken'         => ['data' => $full, 'createFails' => new \OCA\OpenRegister\Exception\OrganisationSlugConflictException(slug: 'gemeente-a')],
		];
	}//end cases()

	/**
	 * Run one case and record the trace.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function record(array $case): array {
		$trace         = [];
		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('getActiveOrganisation')->willReturnCallback(function () use (&$trace, $case): ?Organisation {
			$trace[] = ['getActiveOrganisation'];
			if (($case['activeFails'] ?? false) === true) {
				throw new \RuntimeException('no session');
			}

			if (isset($case['active']) === false) {
				return null;
			}

			$active = $this->createMock(Organisation::class);
			$active->method('getUuid')->willReturn($case['active']);
			return $active;
		});
		$organisations->method('createOrganisation')->willReturnCallback(function (NewOrganisation $details, bool $addCurrentUser) use (&$trace, $case): Organisation {
			$trace[] = ['createOrganisation', [$details->name, $details->description, $addCurrentUser, $details->uuid]];
			if (isset($case['createFails']) === true) {
				throw $case['createFails'];
			}

			$entity = $this->createMock(Organisation::class);
			$entity->method('getId')->willReturn(7);
			$entity->method('getUuid')->willReturn($details->uuid);
			return $entity;
		});

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id) use (&$trace, $case, $organisations) {
			$trace[] = ['container.get', $id];
			if (($case['serviceFails'] ?? false) === true) {
				throw new \RuntimeException('not registered');
			}

			return $organisations;
		});
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(function (string $app) use (&$trace, $case): bool {
			$trace[] = ['isEnabledForUser', $app];
			return ($case['enabled'] ?? true);
		});
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error', 'debug', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context = []) use (&$trace, $level): void {
				$trace[] = [$level, $message, $context];
			});
		}

		$reflection = new ReflectionClass(OrganisatieService::class);
		$service    = $reflection->newInstanceWithoutConstructor();
		foreach (['container' => $container, 'logger' => $logger, 'appManager' => $apps] as $name => $value) {
			$reflection->getProperty($name)->setValue($service, $value);
		}

		try {
			$result  = $service->createOrganisationInOpenRegister($case['data']);
			$outcome = ['result' => ($result === null ? null : $result->getId())];
		} catch (\Throwable $e) {
			$outcome = ['escaped' => get_class($e)];
		}

		return $outcome + ['trace' => $trace];
	}//end record()

	/**
	 * Every case matches the recorded trace.
	 *
	 * @return void
	 */
	public function testCreateMatchesGolden(): void {
		$actual = [];
		$lines  = [];
		foreach (self::cases() as $name => $case) {
			$actual[$name] = $this->record(case: $case);
			$lines[]       = json_encode($name) . ': ' . json_encode($actual[$name], JSON_UNESCAPED_SLASHES);
		}

		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents(self::FIXTURE, "{\n" . implode(",\n", $lines) . "\n}\n");
		}

		$this->assertSame(json_decode((string) file_get_contents(self::FIXTURE), true), json_decode((string) json_encode($actual), true));

	}//end testCreateMatchesGolden()
}//end class
