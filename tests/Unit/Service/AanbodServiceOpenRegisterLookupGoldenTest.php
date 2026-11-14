<?php

/**
 * Golden test for the OpenRegister lookups of AanbodService::getAanbod.
 *
 * Records, per case, every container, organisation, settings, object service
 * and logger call getAanbod makes, plus its result: OpenRegister missing or
 * failing, no session, no or a failing active organisation, and a search
 * whose results are filtered on the active organisation.
 * Recorded with RECORD_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/aangeboden-gebruik-api/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\Stackiq\Service\AanbodService;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Golden OpenRegister lookups of the aanbod listing.
 *
 * @spec openspec/specs/aangeboden-gebruik-api/spec.md
 */
class AanbodServiceOpenRegisterLookupGoldenTest extends TestCase {

	/**
	 * The active organisation of the caller.
	 */
	private const ACTING_ORG = 'org-acting';

	/**
	 * Everything the doubles saw, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $trace = [];

	/**
	 * The behaviour of the doubles.
	 *
	 * @var array<string, mixed>
	 */
	private array $plan = [];

	/**
	 * The golden file.
	 *
	 * @return string
	 */
	private static function golden(): string {
		return __DIR__ . '/golden/aanbodOpenRegisterLookup.json';

	}//end golden()

	/**
	 * The cases: the behaviour of the doubles.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		return [
			'openregister not installed'   => [['noOpenRegister' => true]],
			'object service lookup throws' => [['objectServiceThrows' => true]],
			'no session'                   => [['noUser' => true]],
			'organisation lookup throws'   => [['orgThrows' => true]],
			'no active organisation'       => [['noActiveOrg' => true]],
			'active organisation'          => [[]],
		];

	}//end cases()

	/**
	 * GetAanbod observes and answers as recorded.
	 *
	 * @param array<string, mixed> $plan The behaviour of the doubles.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testListingMatchesTheRecording(array $plan): void {
		$this->plan  = $plan;
		$this->trace = [];
		$result      = $this->service()->getAanbod(options: ['_limit' => 5]);

		$this->check(key: (string) json_encode(value: $plan), value: ['result' => $result, 'calls' => $this->trace]);

	}//end testListingMatchesTheRecording()

	/**
	 * The service with recording doubles.
	 *
	 * @return AanbodService
	 */
	private function service(): AanbodService {
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(isset($this->plan['noOpenRegister']) === true ? ['files'] : ['openregister']);

		$objectService       = $this->objectService();
		$organisationService = $this->organisationService();
		$container           = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objectService, $organisationService) {
				$this->trace[] = ['container.get', $id];
				if ($id === 'OCA\OpenRegister\Service\OrganisationService') {
					if (isset($this->plan['orgThrows']) === true) {
						throw new \RuntimeException('organisation service down');
					}

					return $organisationService;
				}

				if (isset($this->plan['objectServiceThrows']) === true) {
					throw new \RuntimeException('object service down');
				}

				return $objectService;
			}
		);

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getVoorzieningenConfig')->willReturnCallback(
			function (): array {
				$this->trace[] = ['settings.getVoorzieningenConfig'];
				return ['register' => 'reg-1', 'gebruik_schema' => 'schema-gebruik'];
			}
		);

		$userSession = $this->createMock(originalClassName: IUserSession::class);
		$user        = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('acting-user');
		$userSession->method('getUser')->willReturn(isset($this->plan['noUser']) === true ? null : $user);

		return new AanbodService(
			config: $this->createMock(originalClassName: IAppConfig::class),
			appManager: $appManager,
			container: $container,
			logger: $this->logger(),
			settingsService: $settings,
			userSession: $userSession
		);

	}//end service()

	/**
	 * An organisation service whose active organisation is the acting one.
	 *
	 * @return OrganisationService
	 */
	private function organisationService(): OrganisationService {
		$organisation = new Organisation();
		$organisation->setUuid(self::ACTING_ORG);
		$service = $this->createMock(originalClassName: OrganisationService::class);
		$service->method('getActiveOrganisation')->willReturnCallback(
			function () use ($organisation): ?Organisation {
				$this->trace[] = ['organisations.getActiveOrganisation'];
				return isset($this->plan['noActiveOrg']) === true ? null : $organisation;
			}
		);

		return $service;

	}//end organisationService()

	/**
	 * An object service that records the search and returns one own and one foreign object.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objectService(): ObjectServiceInterface {
		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->method('searchObjectsPaginated')->willReturnCallback(
			function (...$args): array {
				$this->trace[] = ['objects.searchObjectsPaginated', $args[0], $args[1], $args[2]];
				return [
					'results' => [
						['id' => 'own', '@self' => ['organisation' => self::ACTING_ORG]],
						['id' => 'offered', '@self' => ['organisation' => 'org-other']],
					],
				];
			}
		);

		return $objectService;

	}//end objectService()

	/**
	 * A logger that records every line, without stack traces.
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
				unset($context['trace']);
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
