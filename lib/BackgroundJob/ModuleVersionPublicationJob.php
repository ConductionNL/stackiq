<?php

/**
 * Module Version Publication Job.
 *
 * Copies a module's publication onto its versions, queued by
 * ModuleVersionPublicationService when a module's publication changes or the
 * module is deleted, so the one save per version runs off the request that
 * saved the module. The module is read when the job runs, so the versions
 * follow the module as it is then, not as it was when the job was queued.
 * A queued job is removed before it runs, so a copy that fails is queued
 * again, a few minutes later, up to MAX_ATTEMPTS times.
 *
 * @category  BackgroundJob
 * @package   OCA\Stackiq\BackgroundJob
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/publication-field-rules/spec.md#requirement-req-pfr-002-a-module-version-is-public-only-while-its-application-is
 */

declare(strict_types=1);

namespace OCA\Stackiq\BackgroundJob;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\ModuleVersionPublicationService;
use OCA\Stackiq\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * One copy of one module's publication onto its versions.
 *
 * @spec openspec/specs/publication-field-rules/spec.md#requirement-req-pfr-002-a-module-version-is-public-only-while-its-application-is
 */
class ModuleVersionPublicationJob extends QueuedJob {

	/**
	 * How many times one copy is tried before it is given up and logged as critical.
	 */
	public const MAX_ATTEMPTS = 3;

	/**
	 * Seconds between a failed copy and the next try.
	 */
	public const RETRY_DELAY = 300;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory                    $time            The time factory.
	 * @param ModuleVersionPublicationService $publication     The mirror.
	 * @param SettingsService                 $settingsService Resolves the module register and schema.
	 * @param ContainerInterface              $container       Resolves OpenRegister's object service.
	 * @param LoggerInterface                 $logger          The logger.
	 * @param IJobList                        $jobList         Queues the job again after a failed copy.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ModuleVersionPublicationService $publication,
		private readonly SettingsService $settingsService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly IJobList $jobList,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Copy the publication of the module in the argument onto its versions.
	 *
	 * A deleted module, or one that no longer exists, takes its versions out
	 * of public view. A module that cannot be read, or a version that cannot be
	 * written, is tried again later.
	 *
	 * @param mixed $argument `{module, deleted, attempt?}`: the module's id, whether it was deleted, and the try.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/publication-field-rules/spec.md#requirement-req-pfr-002-a-module-version-is-public-only-while-its-application-is
	 */
	protected function run($argument): void {
		$moduleUuid = '';
		if (is_array($argument) === true && is_string($argument['module'] ?? null) === true) {
			$moduleUuid = $argument['module'];
		}

		if ($moduleUuid === '') {
			return;
		}

		if (($argument['deleted'] ?? false) === true) {
			$this->retryOnFailure(argument: $argument, failed: $this->publication->clearVersions(moduleUuid: $moduleUuid)['failed']);
			return;
		}

		try {
			$module = $this->findModule(moduleUuid: $moduleUuid);
		} catch (DoesNotExistException $e) {
			$module = null;
		} catch (Throwable $e) {
			$this->logger->error(
				'ModuleVersionPublicationJob: could not read the module; its versions are tried again later',
				['module' => $moduleUuid, 'error' => $e->getMessage()]
			);
			$this->retryOnFailure(argument: $argument, failed: 1);
			return;
		}

		$result = ['failed' => 0];
		if ($module !== null) {
			$result = $this->publication->backfillModule(module: $module);
		}

		if ($module === null) {
			$result = $this->publication->clearVersions(moduleUuid: $moduleUuid);
		}

		$this->retryOnFailure(argument: $argument, failed: $result['failed']);
	}//end run()

	/**
	 * Queue the copy again after a failure, until MAX_ATTEMPTS tries were made.
	 *
	 * @param array<string, mixed> $argument The job's argument.
	 * @param int                  $failed   The versions or reads that failed in this try.
	 *
	 * @return void
	 */
	private function retryOnFailure(array $argument, int $failed): void {
		if ($failed === 0) {
			return;
		}

		$attempt = ((int) ($argument['attempt'] ?? 1));
		if ($attempt >= self::MAX_ATTEMPTS) {
			$this->logger->critical(
				'ModuleVersionPublicationJob: gave up copying the publication onto the versions; save the module again to retry',
				['module' => $argument['module'], 'attempts' => $attempt, 'failed' => $failed]
			);
			return;
		}

		$argument['attempt'] = ($attempt + 1);
		$this->jobList->scheduleAfter(self::class, $this->time->getTime() + self::RETRY_DELAY, $argument);
	}//end retryOnFailure()

	/**
	 * Read the module as it is now.
	 *
	 * @param string $moduleUuid The module.
	 *
	 * @return ObjectEntityInterface|null The module, or null when it no longer exists.
	 *
	 * @throws DoesNotExistException When the module no longer exists (OpenRegister's find() throws rather than returning null).
	 * @throws Throwable When OpenRegister is absent or the read fails.
	 */
	private function findModule(string $moduleUuid): ?ObjectEntityInterface {
		$objects = $this->container->get(ObjectServiceInterface::class);
		if (($objects instanceof ObjectServiceInterface) === false) {
			throw new RuntimeException('OpenRegister\'s object service is not available');
		}

		return $objects->find(
			id: $moduleUuid,
			register: $this->settingsService->getRegisterIdForObjectType('module'),
			schema: $this->settingsService->getSchemaIdForObjectType('module'),
			_rbac: false,
			_multitenancy: false
		);
	}//end findModule()
}//end class
