<?php

/**
 * Golden test for ContactNamesController::contactsUidsOf().
 *
 * Records the exact contact person query (no rbac or multitenancy
 * override), the log lines, and which requested contact persons get a
 * contactsUid; a record OpenRegister returns but was not asked for is
 * never named.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Controller\ContactNamesController;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\StackiqContactSyncService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins which requested contact persons are resolved to a contactsUid.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Controller
 */
final class ContactNamesUidsGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/contact-names/uids.json';

	private const A = 'aaaaaaaa-0000-4000-8000-000000000001';

	private const B = 'bbbbbbbb-0000-4000-8000-000000000002';

	private const C = 'cccccccc-0000-4000-8000-000000000003';

	/**
	 * The cases.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function cases(): array {
		$schemaKey = SettingsService::LEGACY_SCHEMA_KEY['contactPerson'];
		$config    = ['register' => '4', $schemaKey => '12'];
		$entity    = new class (self::B) {
			/**
			 * Keep the id.
			 *
			 * @param string $id The id.
			 */
			public function __construct(private string $id) {
			}

			/**
			 * Serialise.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['@self' => ['id' => strtoupper($this->id)], 'contactsUid' => ' uid-b '];
			}
		};
		return [
			'no register'     => ['config' => [$schemaKey => '12']],
			'no schema'       => ['config' => ['register' => '4']],
			'zero register'   => ['config' => ['register' => '0', $schemaKey => '12']],
			'read fails'      => ['config' => $config, 'fails' => true],
			'no results key'  => ['config' => $config, 'reply' => []],
			'resolved'        => [
				'config' => $config,
				'reply'  => ['results' => [
					['id' => self::A, 'contactsUid' => 'uid-a'],
					$entity,
					['id' => self::C, 'contactsUid' => '   '],
					['id' => 'dddddddd-0000-4000-8000-000000000004', 'contactsUid' => 'uid-d'],
					'junk',
					new \stdClass(),
				]],
			],
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
		$trace    = [];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getVoorzieningenConfig')->willReturn($case['config']);
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('searchObjectsPaginated')->willReturnCallback(function (...$args) use (&$trace, $case): array {
			$trace[] = ['searchObjectsPaginated', $args];
			if (($case['fails'] ?? false) === true) {
				throw new \RuntimeException('register down');
			}

			return $case['reply'];
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context = []) use (&$trace): void {
			$trace[] = ['warning', $message, array_keys($context)];
		});

		$controller = new ContactNamesController(
			appName: 'stackiq',
			request: $this->createMock(IRequest::class),
			userSession: $this->createMock(IUserSession::class),
			l10n: $this->createMock(IL10N::class),
			settingsService: $settings,
			contactSync: $this->createMock(StackiqContactSyncService::class),
			objectService: $objects,
			logger: $logger
		);
		$method     = new \ReflectionMethod($controller, 'contactsUidsOf');
		$method->setAccessible(true);
		$uids = $method->invoke($controller, [self::A, self::B, self::C]);

		return ['uids' => $uids, 'trace' => $trace];
	}//end record()

	/**
	 * Every case matches the recorded outcome.
	 *
	 * @return void
	 */
	public function testUidsMatchGolden(): void {
		$actual = [];
		foreach (self::cases() as $name => $case) {
			$actual[$name] = $this->record(case: $case);
		}

		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$this->assertSame(json_decode((string) file_get_contents(self::FIXTURE), true), json_decode((string) json_encode($actual), true));

	}//end testUidsMatchGolden()
}//end class
