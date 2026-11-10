<?php

/**
 * Golden test for AangebodenGebruikService::getKoppelingenGebruikByUuid.
 *
 * Records, per case, every object service, settings, organisation and logger
 * call the lookup makes, plus its result. The acting organisation comes from
 * the session; the doubles only grant ownership to that organisation.
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
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

require_once __DIR__ . '/../Support/FindCallShape.php';

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\Stackiq\Service\AangebodenGebruikService;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Tests\Unit\Support\FindCallShape;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Golden cases for the koppelingen and gebruik lookup by uuid.
 *
 * @covers \OCA\Stackiq\Service\AangebodenGebruikService
 *
 * @spec openspec/specs/aangeboden-gebruik-api/spec.md
 */
class AangebodenGebruikKoppelingenGoldenTest extends TestCase {

	/**
	 * The organisation of the signed-in user.
	 */
	private const ACTING_ORG = 'org-acting';

	/**
	 * Everything the doubles saw, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $trace = [];

	/**
	 * Per-case behaviour of the doubles.
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
		return __DIR__ . '/golden/aangebodenGebruikKoppelingen.json';

	}//end golden()

	/**
	 * The cases: uuid, options, ambtenaar flag and the behaviour of the doubles.
	 *
	 * @return array<string, array{0: string, 1: array, 2: bool, 3: array<string, mixed>}>
	 */
	public static function cases(): array {
		$module = ['owner' => self::ACTING_ORG, 'schema' => 'schema-module'];
		$org    = ['owner' => self::ACTING_ORG, 'schema' => 'schema-organisatie'];
		return [
			'empty uuid'                         => ['', [], true, []],
			'missing koppeling schema'           => ['uuid-1', [], true, ['config' => ['register' => 'reg-1', 'gebruik_schema' => 'schema-gebruik']]],
			'openregister not installed'         => ['uuid-1', [], true, ['noOpenRegister' => true]],
			'ambtenaar module with filter'       => ['uuid-mod', ['organisation' => 'org-x', '_limit' => 5], true, ['found' => ['owner' => 'org-other', 'schema' => 'schema-module']]],
			'ambtenaar module without filter'    => ['uuid-mod', ['_page' => 2], true, ['found' => ['owner' => 'org-other', 'schema' => 'schema-module']]],
			'ambtenaar organisation with filter' => ['uuid-org', ['organisation' => 'org-x'], true, ['found' => ['owner' => 'org-other', 'schema' => 'schema-organisatie']]],
			'ambtenaar organisation default id'  => ['uuid-org', [], true, ['found' => ['owner' => 'org-other', 'schema' => '15'], 'config' => ['register' => 'reg-1', 'gebruik_schema' => 'schema-gebruik', 'koppeling_schema' => 'schema-koppeling']]],
			'ambtenaar find throws'              => ['uuid-mod', [], true, ['findThrows' => true]],
			'ambtenaar find returns null'        => ['uuid-mod', [], true, ['found' => null]],
			'ambtenaar without a session'        => ['uuid-mod', [], true, ['noUser' => true, 'found' => $module]],
			'owner module, forged filter'        => ['uuid-mod', ['organisation' => 'org-forged'], false, ['found' => $module]],
			'owner organisation'                 => ['uuid-org', [], false, ['found' => $org]],
			'not the owner'                      => ['uuid-mod', [], false, ['found' => ['owner' => 'org-other', 'schema' => 'schema-module']]],
			'no owner on the object'             => ['uuid-mod', [], false, ['found' => ['owner' => null, 'schema' => 'schema-module']]],
			'no session'                         => ['uuid-mod', [], false, ['noUser' => true, 'found' => $module]],
			'no active organisation'             => ['uuid-mod', [], false, ['noActiveOrg' => true, 'found' => $module]],
			'organisation lookup throws'         => ['uuid-mod', [], false, ['orgThrows' => true, 'found' => $module]],
			'ownership lookup throws'            => ['uuid-mod', [], false, ['findThrows' => true]],
			'ownership object missing'           => ['uuid-mod', [], false, ['found' => null]],
			'search throws'                      => ['uuid-mod', [], true, ['found' => $module, 'searchThrows' => true]],
			'not the owner, filter names the owner' => ['uuid-mod', ['organisation' => 'org-other'], false, ['found' => ['owner' => 'org-other', 'schema' => 'schema-module']]],
			'owner in another case'              => ['uuid-mod', [], false, ['found' => ['owner' => 'ORG-ACTING', 'schema' => 'schema-module']]],
			'owner with trailing space'          => ['uuid-mod', [], false, ['found' => ['owner' => self::ACTING_ORG . ' ', 'schema' => 'schema-module']]],
			'forged @self and ambtenaar options' => ['uuid-mod', ['@self' => ['organisation' => self::ACTING_ORG, 'register' => 'reg-forged'], 'isAmbtenaar' => true, 'organisation' => 'org-other'], false, ['found' => ['owner' => 'org-other', 'schema' => 'schema-module']]],
			'owner, forged @self in options'     => ['uuid-mod', ['@self' => ['register' => 'reg-forged', 'schema' => 'schema-forged']], false, ['found' => $module]],
		];

	}//end cases()

	/**
	 * Each case matches its recording.
	 *
	 * @param string               $uuid        The uuid looked up.
	 * @param array                $options     The query options.
	 * @param bool                 $isAmbtenaar Whether the caller is an ambtenaar.
	 * @param array<string, mixed> $plan        The behaviour of the doubles.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testLookupMatchesTheRecording(string $uuid, array $options, bool $isAmbtenaar, array $plan): void {
		$this->plan  = $plan;
		$this->trace = [];
		try {
			$result = $this->service()->getKoppelingenGebruikByUuid(uuid: $uuid, options: $options, isAmbtenaar: $isAmbtenaar);
		} catch (\Throwable $e) {
			$result = ['escaped' => get_class($e), 'message' => $e->getMessage()];
		}

		$key = json_encode([$uuid, $options, $isAmbtenaar, $plan]);
		$this->check(key: $key, value: ['result' => $result, 'calls' => $this->trace]);

	}//end testLookupMatchesTheRecording()

	/**
	 * The service with recording doubles.
	 *
	 * @return AangebodenGebruikService
	 */
	private function service(): AangebodenGebruikService {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(isset($this->plan['noOpenRegister']) === true ? ['files'] : ['openregister']);

		$objectService       = $this->objectService();
		$organisationService = $this->organisationService();
		$container           = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objectService, $organisationService) {
				$this->trace[] = ['container.get', $id];
				if ($id === 'OCA\OpenRegister\Service\OrganisationService') {
					if (isset($this->plan['orgThrows']) === true) {
						throw new \RuntimeException('organisation service down');
					}

					return $organisationService;
				}

				return $objectService;
			}
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getVoorzieningenConfig')->willReturnCallback(
			function (): array {
				$this->trace[] = ['settings.getVoorzieningenConfig'];
				return $this->plan['config'] ?? [
					'register'           => 'reg-1',
					'gebruik_schema'     => 'schema-gebruik',
					'koppeling_schema'   => 'schema-koppeling',
					'organisatie_schema' => 'schema-organisatie',
				];
			}
		);

		$userSession = $this->createMock(IUserSession::class);
		$user        = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('acting-user');
		$userSession->method('getUser')->willReturn(isset($this->plan['noUser']) === true ? null : $user);

		return new AangebodenGebruikService(
			config: $this->createMock(IAppConfig::class),
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
		$service = $this->createMock(OrganisationService::class);
		$service->method('getActiveOrganisation')->willReturnCallback(
			function () use ($organisation): ?Organisation {
				$this->trace[] = ['organisations.getActiveOrganisation'];
				return isset($this->plan['noActiveOrg']) === true ? null : $organisation;
			}
		);

		return $service;

	}//end organisationService()

	/**
	 * An object service that records the lookups and the search.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objectService(): ObjectServiceInterface {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (...$args) {
				$flat          = FindCallShape::find(args: $args);
				$this->trace[] = ['objects.find', $flat[0], $flat[5], $flat[6]];
				if (isset($this->plan['findThrows']) === true) {
					throw new \RuntimeException('find failed');
				}

				$found = $this->plan['found'] ?? null;
				if ($found === null) {
					return null;
				}

				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('getObject')->willReturn(['@self' => ['organisation' => $found['owner'], 'schema' => $found['schema']]]);
				return $entity;
			}
		);
		$objectService->method('buildSearchQuery')->willReturnCallback(
			function (array $requestParams): array {
				$this->trace[] = ['objects.buildSearchQuery', $requestParams];
				// Like OpenRegister, a caller's @self filters are carried into the query.
				return ['@self' => ['seed' => true] + ($requestParams['@self'] ?? []), '_limit' => $requestParams['_limit'] ?? 20];
			}
		);
		$objectService->method('searchObjectsPaginated')->willReturnCallback(
			function (...$args): array {
				$this->trace[] = [
					'objects.searchObjectsPaginated',
					[
						'query'         => $args[0],
						'_rbac'         => $args[1],
						'_multitenancy' => $args[2],
						'deleted'       => $args[3],
						'uses'          => $args[5] ?? null,
					],
				];
				if (isset($this->plan['searchThrows']) === true) {
					throw new \RuntimeException('search failed');
				}

				return ['results' => [['id' => 'g-1']], 'total' => 1, 'page' => 1, 'pages' => 1];
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
		$json  = json_decode(json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR), true);
		$file  = self::golden();
		$saved = [];
		if (file_exists($file) === true) {
			$saved = json_decode((string) file_get_contents($file), true);
		}

		if (getenv('RECORD_GOLDEN') !== false) {
			$saved[$key] = $json;
			file_put_contents($file, json_encode($saved, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
			$this->addToAssertionCount(count: 1);
			return;
		}

		$this->assertArrayHasKey(key: $key, array: $saved, message: 'No golden for ' . $key);
		$this->assertSame(expected: $saved[$key], actual: $json);

	}//end check()

}//end class
