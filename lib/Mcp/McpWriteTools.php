<?php

/**
 * StackIQ McpWriteTools
 *
 * The curated write tools beyond the contract seam (mcp-full-action-surface
 * tasks 5.2 to 5.5). Each follows the same ladder: validate the arguments,
 * prove the caller may act (the guard its REST twin runs), then call the
 * existing workflow service. A refusal at any step means no service call.
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
 * @spec openspec/specs/mcp-tool-surface/spec.md#requirement-every-write-tool-must-delegate-to-the-existing-workflow-service-behind-its-existing-guard
 */

declare(strict_types=1);

namespace OCA\Stackiq\Mcp;

use Exception;
use OCA\Stackiq\Kernel\Contracts\AanbodServiceInterface;
use OCA\Stackiq\Kernel\Contracts\AangebodenGebruikServiceInterface;
use OCA\Stackiq\Kernel\Contracts\EolSyncServiceInterface;
use OCA\Stackiq\Kernel\Contracts\IntakeServiceInterface;
use OCA\Stackiq\Kernel\Contracts\MergeOrganisatieServiceInterface;
use OCA\Stackiq\Kernel\Contracts\ModerationServiceInterface;
use OCA\Stackiq\Kernel\Contracts\PublicationServiceInterface;
use OCA\Stackiq\Kernel\Contracts\ReviewServiceInterface;
use OCA\Stackiq\Kernel\Contracts\SbomImportServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Handlers of the curated write tools.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) McpArgumentValidator::error() is the one pure builder of the structured MCP error.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One handler per write tool, each on its own service.
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) The constructor takes one service per tool family.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)   One public method per tool.
 *
 * @spec openspec/specs/mcp-tool-surface/spec.md#requirement-every-write-tool-must-delegate-to-the-existing-workflow-service-behind-its-existing-guard
 */
class McpWriteTools {

	/**
	 * The record types the moderation tools act on.
	 *
	 * @var array<int, string>
	 */
	public const MODERATION_TYPES = [ModerationServiceInterface::MODERATED_TYPE, 'software-review'];

	/**
	 * What a review can be about, as ReviewService accepts.
	 *
	 * @var array<int, string>
	 */
	public const REVIEW_SUBJECTS = ['module', 'catalogService'];

	/**
	 * The largest inline SBOM, the same bound as an uploaded file.
	 */
	public const MAX_SBOM_BYTES = 10485760;

	/**
	 * Constructor.
	 *
	 * @param McpAdminGate                      $session          The caller and the admin check.
	 * @param McpArgumentValidator              $validator        The argument checks.
	 * @param McpPublicationGate                $publicationGate  The publication guard.
	 * @param McpSbomGate                       $sbomGate         The SBOM import guard.
	 * @param PublicationServiceInterface       $publications     Publish and depublish.
	 * @param ModerationServiceInterface        $moderation       Approve and reject.
	 * @param ReviewServiceInterface            $reviews          Review submission.
	 * @param IntakeServiceInterface            $intake           Organisation registration.
	 * @param AanbodServiceInterface            $offers           Accept and decline offers.
	 * @param AangebodenGebruikServiceInterface $usages           Claim and decline usages.
	 * @param MergeOrganisatieServiceInterface  $merges           Organisation merge.
	 * @param SbomImportServiceInterface        $sbom             SBOM import.
	 * @param EolSyncServiceInterface           $eolSync          The end-of-life sync.
	 */
	public function __construct(
		private readonly McpAdminGate $session,
		private readonly McpArgumentValidator $validator,
		private readonly McpPublicationGate $publicationGate,
		private readonly McpSbomGate $sbomGate,
		private readonly PublicationServiceInterface $publications,
		private readonly ModerationServiceInterface $moderation,
		private readonly ReviewServiceInterface $reviews,
		private readonly IntakeServiceInterface $intake,
		private readonly AanbodServiceInterface $offers,
		private readonly AangebodenGebruikServiceInterface $usages,
		private readonly MergeOrganisatieServiceInterface $merges,
		private readonly SbomImportServiceInterface $sbom,
		private readonly EolSyncServiceInterface $eolSync,
	) {
	}//end __construct()

	/**
	 * Publish a catalogue entry, now or from a date.
	 *
	 * @param array<string, mixed> $args `objectType`, `uuid`, optional `when`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-11-25-mcp-full-action-surface/tasks.md#5.2
	 */
	public function publishObject(array $args): array {
		$refusal = ($this->publicationArgs(args: $args)
			?? $this->validator->string(args: $args, name: 'when', max: 64, required: false)
			?? $this->publicationGate->authorise(objectType: (string)$args['objectType'], uuid: (string)$args['uuid']));
		if ($refusal !== null) {
			return $refusal;
		}

		$when = null;
		if (isset($args['when']) === true) {
			$when = (string)$args['when'];
		}

		return $this->guarded(
			step: fn (): array => self::okOrError(result: $this->publications->publish((string)$args['objectType'], (string)$args['uuid'], $when))
		);
	}//end publishObject()

	/**
	 * Withdraw a catalogue entry from the public catalogue.
	 *
	 * @param array<string, mixed> $args `objectType` and `uuid`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-11-25-mcp-full-action-surface/tasks.md#5.2
	 */
	public function depublishObject(array $args): array {
		$refusal = ($this->publicationArgs(args: $args)
			?? $this->publicationGate->authorise(objectType: (string)$args['objectType'], uuid: (string)$args['uuid']));
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->guarded(
			step: fn (): array => self::okOrError(result: $this->publications->depublish((string)$args['objectType'], (string)$args['uuid']))
		);
	}//end depublishObject()

	/**
	 * Admit a pending organisation registration or review (administrators).
	 *
	 * @param array<string, mixed> $args `uuid`, optional `type`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-11-25-mcp-full-action-surface/tasks.md#5.3
	 */
	public function approveRegistration(array $args): array {
		return $this->moderate(args: $args, approve: true);
	}//end approveRegistration()

	/**
	 * Reject a pending organisation registration or review (administrators).
	 *
	 * @param array<string, mixed> $args `uuid`, optional `type`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-11-25-mcp-full-action-surface/tasks.md#5.3
	 */
	public function rejectRegistration(array $args): array {
		return $this->moderate(args: $args, approve: false);
	}//end rejectRegistration()

	/**
	 * Submit a review of an application or service; ReviewService always stores it as pending.
	 *
	 * @param array<string, mixed> $args `subjectType`, `subjectId` and `review`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/mcp-tool-surface/spec.md#scenario-review-submission-cannot-bypass-moderation
	 */
	public function submitReview(array $args): array {
		$refusal = ($this->validator->oneOf(args: $args, name: 'subjectType', allowed: self::REVIEW_SUBJECTS)
			?? $this->validator->string(args: $args, name: 'subjectId', max: 255)
			?? $this->validator->object(args: $args, name: 'review')
			?? $this->session->requireUser());
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->guarded(
			step: function () use ($args): array {
				$result = $this->reviews->submit(payload: $args['review'], subjectType: (string)$args['subjectType'], subjectId: (string)$args['subjectId']);
				if (($result['ok'] ?? false) === false) {
					return McpArgumentValidator::error(code: 'validation_error', message: (string)($result['reason'] ?? 'The review was refused.'));
				}

				return ['uuid' => $result['uuid'], 'status' => $result['status'], 'message' => 'Review received and queued for moderation'];
			}
		);
	}//end submitReview()

	/**
	 * Register an organisation; the registration waits for moderation.
	 *
	 * @param array<string, mixed> $args `organisation`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-11-25-mcp-full-action-surface/tasks.md#5.3
	 */
	public function registerOrganisation(array $args): array {
		$refusal = ($this->validator->object(args: $args, name: 'organisation') ?? $this->session->requireUser());
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->guarded(
			step: function () use ($args): array {
				$result = $this->intake->submit($args['organisation']);
				if (($result['ok'] ?? false) === false) {
					return McpArgumentValidator::error(code: 'validation_error', message: (string)($result['reason'] ?? 'The registration was refused.'));
				}

				return ['uuid' => ($result['uuid'] ?? null), 'status' => ($result['status'] ?? null)];
			}
		);
	}//end registerOrganisation()

	/**
	 * Accept an offer made to the caller's active organisation.
	 *
	 * @param array<string, mixed> $args `offerUuid`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-11-25-mcp-full-action-surface/tasks.md#5.4
	 */
	public function acceptOffer(array $args): array {
		return $this->objectAction(
			args: $args,
			name: 'offerUuid',
			perform: fn (string $uuid): array => $this->offers->acceptAanbod(aanbodId: $uuid, options: [])
		);
	}//end acceptOffer()

	/**
	 * Decline an offer made to the caller's active organisation.
	 *
	 * @param array<string, mixed> $args `offerUuid`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-11-25-mcp-full-action-surface/tasks.md#5.4
	 */
	public function declineOffer(array $args): array {
		return $this->objectAction(
			args: $args,
			name: 'offerUuid',
			perform: fn (string $uuid): array => $this->offers->denyAanbod(aanbodId: $uuid, options: [])
		);
	}//end declineOffer()

	/**
	 * Take an offered usage record over as the caller's active organisation.
	 *
	 * @param array<string, mixed> $args `usageUuid`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-11-25-mcp-full-action-surface/tasks.md#5.4
	 */
	public function claimUsage(array $args): array {
		return $this->objectAction(
			args: $args,
			name: 'usageUuid',
			perform: fn (string $uuid): array => $this->usages->setGebruikSelfToActiveOrg(gebruikId: $uuid, options: [])
		);
	}//end claimUsage()

	/**
	 * Decline an offered usage record as its consumer.
	 *
	 * @param array<string, mixed> $args `usageUuid`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-11-25-mcp-full-action-surface/tasks.md#5.4
	 */
	public function declineUsage(array $args): array {
		return $this->objectAction(
			args: $args,
			name: 'usageUuid',
			perform: fn (string $uuid): array => $this->usages->deleteGebruikAsAfnemer(gebruikId: $uuid, options: [])
		);
	}//end declineUsage()

	/**
	 * Merge one organisation into another (administrators); the source is tombstoned.
	 *
	 * @param array<string, mixed> $args `sourceUuid` and `targetUuid`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-11-25-mcp-full-action-surface/tasks.md#5.4
	 */
	public function mergeOrganisations(array $args): array {
		$refusal = ($this->validator->uuid(args: $args, name: 'sourceUuid')
			?? $this->validator->uuid(args: $args, name: 'targetUuid')
			?? $this->session->requireAdmin());
		if ($refusal !== null) {
			return $refusal;
		}

		$actorUid = $this->session->currentUser()?->getUID();
		return $this->guarded(
			step: fn (): array => self::okOrError(
				result: $this->merges->execute(sourceUuid: (string)$args['sourceUuid'], targetUuid: (string)$args['targetUuid'], actorUid: $actorUid)
			)
		);
	}//end mergeOrganisations()

	/**
	 * Import an SBOM, passed inline as JSON text, for an application version.
	 *
	 * @param array<string, mixed> $args `moduleVersionUuid`, `content`, optional `format` and `fileName`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-11-25-mcp-full-action-surface/tasks.md#5.5
	 */
	public function importSbom(array $args): array {
		$refusal = ($this->validator->uuid(args: $args, name: 'moduleVersionUuid')
			?? $this->validator->string(args: $args, name: 'content', max: self::MAX_SBOM_BYTES)
			?? $this->validator->oneOf(args: $args, name: 'format', allowed: SbomImportServiceInterface::SUPPORTED_FORMATS, required: false)
			?? $this->validator->string(args: $args, name: 'fileName', max: 255, required: false));
		if ($refusal !== null) {
			return $refusal;
		}

		$uuid = (string)$args['moduleVersionUuid'];
		$content = (string)$args['content'];
		if (strlen($content) > self::MAX_SBOM_BYTES || json_validate($content) === false) {
			return McpArgumentValidator::error(code: 'validation_error', message: 'Argument content must be SBOM JSON of at most 10 MB.');
		}

		return $this->guarded(
			step: fn (): array => ($this->sbomGate->authoriseImport(moduleVersionUuid: $uuid)
				?? $this->sbom->importForModuleVersie(
					moduleVersieUuid: $uuid,
					rawJson: $content,
					format: (string)($args['format'] ?? SbomImportServiceInterface::SUPPORTED_FORMATS[0]),
					fileName: (string)($args['fileName'] ?? 'sbom.json')
				))
		);
	}//end importSbom()

	/**
	 * Run the end-of-life sync now (administrators, as its REST twin); it calls endoflife.date.
	 *
	 * @param array<string, mixed> $args No arguments.
	 *
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Every handler takes the arguments.
	 *
	 * @spec openspec/changes/archive/2026-11-25-mcp-full-action-surface/tasks.md#5.5
	 */
	public function triggerEolSync(array $args): array {
		$refusal = $this->session->requireAdmin();
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->guarded(step: fn (): array => ['status' => $this->eolSync->run()]);
	}//end triggerEolSync()

	/**
	 * Validate the publication tools' `objectType` and `uuid`.
	 *
	 * @param array<string, mixed> $args The arguments.
	 *
	 * @return array<string, mixed>|null The refusal, or null.
	 */
	private function publicationArgs(array $args): ?array {
		return ($this->validator->string(args: $args, name: 'objectType', max: 64)
			?? $this->validator->uuid(args: $args, name: 'uuid'));
	}//end publicationArgs()

	/**
	 * The approve and reject ladder.
	 *
	 * @param array<string, mixed> $args    `uuid`, optional `type`.
	 * @param bool                 $approve Approve, or reject.
	 *
	 * @return array<string, mixed>
	 */
	private function moderate(array $args, bool $approve): array {
		$refusal = ($this->validator->uuid(args: $args, name: 'uuid')
			?? $this->validator->oneOf(args: $args, name: 'type', allowed: self::MODERATION_TYPES, required: false)
			?? $this->session->requireAdmin());
		if ($refusal !== null) {
			return $refusal;
		}

		$uuid = (string)$args['uuid'];
		$type = (string)($args['type'] ?? ModerationServiceInterface::MODERATED_TYPE);
		if ($approve === true) {
			return $this->guarded(step: fn (): array => self::okOrError(result: $this->moderation->approve($uuid, type: $type)));
		}

		return $this->guarded(step: fn (): array => self::okOrError(result: $this->moderation->reject($uuid, type: $type)));
	}//end moderate()

	/**
	 * The ladder of the offer and usage actions: a uuid, a signed-in caller,
	 * then the service, which checks the active organisation itself as it
	 * does for the REST twin.
	 *
	 * @param array<string, mixed>                 $args    The arguments.
	 * @param string                               $name    The uuid argument.
	 * @param callable(string): array<string, mixed> $perform The service call.
	 *
	 * @return array<string, mixed>
	 */
	private function objectAction(array $args, string $name, callable $perform): array {
		$refusal = ($this->validator->uuid(args: $args, name: $name) ?? $this->session->requireUser());
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->guarded(
			step: function () use ($args, $name, $perform): array {
				$result = $perform((string)$args[$name]);
				if (($result['success'] ?? false) === true) {
					return $result;
				}

				$message = (string)($result['error'] ?? 'The action failed.');
				$code = 'failed';
				if (str_contains($message, 'Operation not allowed') === true) {
					$code = 'forbidden';
				}

				return McpArgumentValidator::error(code: $code, message: $message);
			}
		);
	}//end objectAction()

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
	 * Run a step, answering a failure as a structured error.
	 *
	 * @param callable(): array<string, mixed> $step The step.
	 *
	 * @return array<string, mixed>
	 */
	private function guarded(callable $step): array {
		try {
			return $step();
		} catch (DoesNotExistException) {
			return McpArgumentValidator::error(code: 'not_found', message: 'Not found.');
		} catch (Exception $e) {
			return McpArgumentValidator::error(code: 'unavailable', message: $e->getMessage());
		}
	}//end guarded()
}//end class
