<?php

/**
 * StackIQ McpReadTools
 *
 * The curated read tools (mcp-full-action-surface task 4.1). Each validates
 * its arguments, runs the guard its REST twin runs, and then calls only a
 * read path of the existing service; none of them persists anything.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Mcp
 * @package  OCA\Stackiq\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/mcp-tool-surface/spec.md#requirement-read-tools-must-be-side-effect-free-and-separated-from-write-tools
 */

declare(strict_types=1);

namespace OCA\Stackiq\Mcp;

use Exception;
use OCA\Stackiq\Kernel\Contracts\AanbodServiceInterface;
use OCA\Stackiq\Kernel\Contracts\AangebodenGebruikServiceInterface;
use OCA\Stackiq\Kernel\Contracts\ContactpersonenControllerInterface;
use OCA\Stackiq\Kernel\Contracts\EolSyncServiceInterface;
use OCA\Stackiq\Kernel\Contracts\MergeOrganisatieServiceInterface;
use OCA\Stackiq\Kernel\Contracts\ModerationServiceInterface;
use OCA\Stackiq\Kernel\Contracts\OrganisationReportAccessInterface;
use OCA\Stackiq\Kernel\Contracts\PortfolioReportServiceInterface;
use OCA\Stackiq\Kernel\Contracts\ReviewAggregateServiceInterface;
use OCA\Stackiq\Kernel\Contracts\SbomImportServiceInterface;
use OCA\Stackiq\Kernel\Contracts\ViewServiceInterface;
use OCP\AppFramework\Http;

/**
 * Handlers of the curated read tools.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) McpArgumentValidator::error() is the one pure builder of the structured MCP error.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One handler per read tool, each on its own service.
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) The constructor takes one service per tool.
 *
 * @spec openspec/changes/archive/2026-11-25-mcp-full-action-surface/tasks.md#4.1
 */
class McpReadTools {

	/**
	 * Constructor.
	 *
	 * @param McpAdminGate                      $session     The caller and the admin check.
	 * @param McpArgumentValidator              $validator   The argument checks.
	 * @param McpSbomGate                       $sbomGate    The SBOM read guard.
	 * @param ContactpersonenControllerInterface $contacts    The `/api/me` resolution.
	 * @param AanbodServiceInterface            $offers      The offers.
	 * @param AangebodenGebruikServiceInterface $usages      The offered and shared usages.
	 * @param PortfolioReportServiceInterface   $portfolio   The portfolio report.
	 * @param OrganisationReportAccessInterface $reportAccess Who may read an organisation's report.
	 * @param ModerationServiceInterface        $moderation  The moderation queue.
	 * @param ReviewAggregateServiceInterface   $reviews     The review aggregate.
	 * @param SbomImportServiceInterface        $sbom        The SBOM import status.
	 * @param ViewServiceInterface              $views       The architecture views.
	 * @param MergeOrganisatieServiceInterface  $merges      The merge preview.
	 * @param EolSyncServiceInterface           $eolSync     The end-of-life sync status.
	 */
	public function __construct(
		private readonly McpAdminGate $session,
		private readonly McpArgumentValidator $validator,
		private readonly McpSbomGate $sbomGate,
		private readonly ContactpersonenControllerInterface $contacts,
		private readonly AanbodServiceInterface $offers,
		private readonly AangebodenGebruikServiceInterface $usages,
		private readonly PortfolioReportServiceInterface $portfolio,
		private readonly OrganisationReportAccessInterface $reportAccess,
		private readonly ModerationServiceInterface $moderation,
		private readonly ReviewAggregateServiceInterface $reviews,
		private readonly SbomImportServiceInterface $sbom,
		private readonly ViewServiceInterface $views,
		private readonly MergeOrganisatieServiceInterface $merges,
		private readonly EolSyncServiceInterface $eolSync,
	) {
	}//end __construct()

	/**
	 * The caller's own contact person and organisations, as `/api/me` answers.
	 *
	 * @param array<string, mixed> $args No arguments.
	 *
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Every handler takes the arguments.
	 *
	 * @spec openspec/changes/archive/2026-11-25-mcp-full-action-surface/tasks.md#4.1
	 */
	public function getMyContactProfile(array $args): array {
		$refusal = $this->session->requireUser();
		if ($refusal !== null) {
			return $refusal;
		}

		$response = $this->contacts->getMe();
		$data = $response->getData();
		if (is_array($data) === false) {
			$data = [];
		}

		if ($response->getStatus() !== Http::STATUS_OK) {
			return McpArgumentValidator::error(code: 'unavailable', message: (string)($data['message'] ?? 'The profile could not be read.'));
		}

		return $data;
	}//end getMyContactProfile()

	/**
	 * The offers pending for the caller's active organisation.
	 *
	 * @param array<string, mixed> $args Optional `limit` and `page`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-11-25-mcp-full-action-surface/tasks.md#4.1
	 */
	public function listOffers(array $args): array {
		$refusal = ($this->pagingRefusal(args: $args) ?? $this->session->requireUser());
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->guarded(read: fn (): array => $this->offers->getAanbod(self::pagingOptions(args: $args)));
	}//end listOffers()

	/**
	 * The usage records offered to the caller's organisation (`as: consumer`)
	 * or shared with it as a participant (`as: participant`).
	 *
	 * @param array<string, mixed> $args `as`, optional `limit` and `page`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-11-25-mcp-full-action-surface/tasks.md#4.1
	 */
	public function listOfferedUsages(array $args): array {
		$refusal = ($this->validator->oneOf(args: $args, name: 'as', allowed: ['consumer', 'participant'])
			?? $this->pagingRefusal(args: $args)
			?? $this->session->requireUser());
		if ($refusal !== null) {
			return $refusal;
		}

		$options = self::pagingOptions(args: $args);
		if ($args['as'] === 'consumer') {
			return $this->guarded(read: fn (): array => $this->usages->getGebruiksWhereAfnemer($options));
		}

		return $this->guarded(read: fn (): array => $this->usages->getGebruiksWhereDeelnemers($options));
	}//end listOfferedUsages()

	/**
	 * The portfolio report of one organisation the caller may read.
	 *
	 * @param array<string, mixed> $args `organisationUuid`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-11-25-mcp-full-action-surface/tasks.md#4.1
	 */
	public function getPortfolioReport(array $args): array {
		$refusal = $this->validator->uuid(args: $args, name: 'organisationUuid');
		if ($refusal !== null) {
			return $refusal;
		}

		$user = $this->session->currentUser();
		if ($user === null) {
			return McpArgumentValidator::error(code: 'unauthenticated', message: 'This tool needs a signed-in user.');
		}

		$organisation = (string)$args['organisationUuid'];
		if ($this->reportAccess->mayRead(user: $user, organisationUuid: $organisation) === false) {
			return McpArgumentValidator::error(code: 'forbidden', message: 'Not authorised for this organisation.');
		}

		return $this->guarded(read: fn (): array => $this->portfolio->buildReport(organisationUuid: $organisation));
	}//end getPortfolioReport()

	/**
	 * The registrations or reviews waiting for moderation (administrators).
	 *
	 * @param array<string, mixed> $args Optional `type` (`organization` or `software-review`).
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-11-25-mcp-full-action-surface/tasks.md#4.1
	 */
	public function listPendingModerations(array $args): array {
		$refusal = ($this->validator->oneOf(args: $args, name: 'type', allowed: McpWriteTools::MODERATION_TYPES, required: false)
			?? $this->session->requireAdmin());
		if ($refusal !== null) {
			return $refusal;
		}

		$type = (string)($args['type'] ?? ModerationServiceInterface::MODERATED_TYPE);
		return $this->guarded(read: fn (): array => self::okOrError(result: $this->moderation->listPending(type: $type)));
	}//end listPendingModerations()

	/**
	 * The average rating and approved reviews of an application or service.
	 *
	 * @param array<string, mixed> $args `subjectType` and `subjectId`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-11-25-mcp-full-action-surface/tasks.md#4.1
	 */
	public function getReviewAggregate(array $args): array {
		$refusal = ($this->validator->oneOf(args: $args, name: 'subjectType', allowed: McpWriteTools::REVIEW_SUBJECTS)
			?? $this->validator->string(args: $args, name: 'subjectId', max: 255)
			?? $this->session->requireUser());
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->guarded(
			read: function () use ($args): array {
				$result = $this->reviews->getAggregate(subjectType: (string)$args['subjectType'], subjectId: (string)$args['subjectId']);
				if (($result['ok'] ?? false) === false) {
					return McpArgumentValidator::error(code: 'validation_error', message: (string)($result['reason'] ?? 'The aggregate could not be read.'));
				}

				return ['average' => ($result['average'] ?? null), 'count' => ($result['count'] ?? 0), 'items' => ($result['items'] ?? [])];
			}
		);
	}//end getReviewAggregate()

	/**
	 * The SBOM import status of an application version.
	 *
	 * @param array<string, mixed> $args `moduleVersionUuid`, optional `operationId`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-11-25-mcp-full-action-surface/tasks.md#4.1
	 */
	public function getSbomImportStatus(array $args): array {
		$refusal = ($this->validator->uuid(args: $args, name: 'moduleVersionUuid')
			?? $this->validator->string(args: $args, name: 'operationId', max: 128, required: false));
		if ($refusal !== null) {
			return $refusal;
		}

		$uuid = (string)$args['moduleVersionUuid'];
		$operationId = null;
		if (isset($args['operationId']) === true) {
			$operationId = (string)$args['operationId'];
		}

		return $this->guarded(
			read: fn (): array => ($this->sbomGate->authoriseRead(moduleVersionUuid: $uuid)
				?? $this->sbom->getStatus(moduleVersieUuid: $uuid, operationId: $operationId))
		);
	}//end getSbomImportStatus()

	/**
	 * Every architecture view.
	 *
	 * @param array<string, mixed> $args No arguments.
	 *
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Every handler takes the arguments.
	 *
	 * @spec openspec/changes/archive/2026-11-25-mcp-full-action-surface/tasks.md#4.1
	 */
	public function listViews(array $args): array {
		$refusal = $this->session->requireUser();
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->guarded(read: fn (): array => $this->views->getAllViews([]));
	}//end listViews()

	/**
	 * One architecture view.
	 *
	 * @param array<string, mixed> $args `viewId`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-11-25-mcp-full-action-surface/tasks.md#4.1
	 */
	public function getView(array $args): array {
		$refusal = ($this->validator->string(args: $args, name: 'viewId', max: 255) ?? $this->session->requireUser());
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->guarded(read: fn (): array => $this->views->getView(viewId: (string)$args['viewId'], options: []));
	}//end getView()

	/**
	 * What merging one organisation into another would change (administrators); never merges.
	 *
	 * @param array<string, mixed> $args `sourceUuid` and `targetUuid`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/mcp-tool-surface/spec.md#scenario-merge-preview-never-mutates
	 */
	public function previewOrganisationMerge(array $args): array {
		$refusal = ($this->validator->uuid(args: $args, name: 'sourceUuid')
			?? $this->validator->uuid(args: $args, name: 'targetUuid')
			?? $this->session->requireAdmin());
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->guarded(
			read: fn (): array => $this->merges->dryRun(sourceUuid: (string)$args['sourceUuid'], targetUuid: (string)$args['targetUuid'])
		);
	}//end previewOrganisationMerge()

	/**
	 * The last end-of-life sync run (administrators, as its REST twin).
	 *
	 * @param array<string, mixed> $args No arguments.
	 *
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Every handler takes the arguments.
	 *
	 * @spec openspec/changes/archive/2026-11-25-mcp-full-action-surface/tasks.md#4.1
	 */
	public function getEolSyncStatus(array $args): array {
		$refusal = $this->session->requireAdmin();
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->guarded(read: fn (): array => ['status' => $this->eolSync->getStatus()]);
	}//end getEolSyncStatus()

	/**
	 * Refuse a malformed `limit` or `page`.
	 *
	 * @param array<string, mixed> $args The arguments.
	 *
	 * @return array<string, mixed>|null The refusal, or null.
	 */
	private function pagingRefusal(array $args): ?array {
		return ($this->validator->optionalInt(args: $args, name: 'limit', min: 1, max: 100)
			?? $this->validator->optionalInt(args: $args, name: 'page', min: 1, max: 10000));
	}//end pagingRefusal()

	/**
	 * The listing options the REST twins build from `_limit` and `_page`, read from the database.
	 *
	 * @param array<string, mixed> $args The arguments.
	 *
	 * @return array<string, mixed>
	 */
	private static function pagingOptions(array $args): array {
		$options = ['_source' => 'database'];
		if (isset($args['limit']) === true) {
			$options['_limit'] = (int)$args['limit'];
			$options['limit']  = (int)$args['limit'];
		}

		if (isset($args['page']) === true) {
			$options['_page'] = (int)$args['page'];
		}

		return $options;
	}//end pagingOptions()

	/**
	 * A service result with `ok: false` as a structured error.
	 *
	 * @param array<string, mixed> $result The service result.
	 *
	 * @return array<string, mixed>
	 */
	private static function okOrError(array $result): array {
		if (($result['ok'] ?? true) === false) {
			return McpArgumentValidator::error(code: 'validation_error', message: (string)($result['reason'] ?? 'The request was refused.'));
		}

		return $result;
	}//end okOrError()

	/**
	 * Run a read, answering a failure as a structured error.
	 *
	 * @param callable(): array<string, mixed> $read The read.
	 *
	 * @return array<string, mixed>
	 */
	private function guarded(callable $read): array {
		try {
			return $read();
		} catch (Exception $e) {
			return McpArgumentValidator::error(code: 'unavailable', message: $e->getMessage());
		}
	}//end guarded()
}//end class
