<?php

/**
 * Marks a compliance claim verified for a reviewer outside the supplier.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/module-compliance-assessment/spec.md#requirement-a-claim-reads-verified-only-after-a-reviewer-outside-the-supplier-checks-it-req-clr-022
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The verify action. Every refusal is decided before the write; the write
 * itself runs with RBAC off because a reviewer of another organisation has no
 * update right on the supplier's record, and only the verification fields
 * change. ComplianceVerificationGuard refuses those fields on every other
 * write, so this is the only way to set them.
 *
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/module-compliance-assessment/spec.md#requirement-a-claim-reads-verified-only-after-a-reviewer-outside-the-supplier-checks-it-req-clr-022
 * @SuppressWarnings(PHPMD.StaticAccess) ComplianceVerification holds the pure rules; RelationRefs is a pure reader of relation values.
*/
class ComplianceVerificationService {

	/**
	 * Whether this service is writing the verification fields right now.
	 *
	 * @var bool
	 */
	private static bool $writing = false;

	/**
	 * Constructor.
	 *
	 * @param OpenRegisterLookup         $lookup The OpenRegister object service.
	 * @param ObjectTypeLocatorInterface $types  The configured registers and schemas.
	 * @param LoggerInterface            $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly OpenRegisterLookup $lookup,
		private readonly ObjectTypeLocatorInterface $types,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the current save is this service's own verify write.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/module-compliance-assessment/spec.md#requirement-a-claim-reads-verified-only-after-a-reviewer-outside-the-supplier-checks-it-req-clr-022
	 */
	public static function isWriting(): bool {
		return self::$writing;
	}//end isWriting()

	/**
	 * Mark a claim verified.
	 *
	 * @param string       $id           The compliancy uuid.
	 * @param string       $reviewer     The reviewer's display name.
	 * @param list<string> $groups       The reviewer's groups.
	 * @param string       $organisation The reviewer's active organisation uuid.
	 * @param string       $now          The ISO-8601 time to record.
	 *
	 * @return array{status: int, body: array<string, mixed>}
	 *
	 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/module-compliance-assessment/spec.md#requirement-a-claim-reads-verified-only-after-a-reviewer-outside-the-supplier-checks-it-req-clr-022
	 */
	public function verify(string $id, string $reviewer, array $groups, string $organisation, string $now): array {
		$compliancy = $this->target(objectType: 'compliancy');
		$module     = $this->target(objectType: 'module');
		if ($compliancy === null || $module === null) {
			return ['status' => 503, 'body' => ['message' => 'The compliance register is not configured']];
		}

		try {
			$objects = $this->lookup->objectService();
			$entity  = $objects->find(id: $id, options: new ObjectFindOptions(register: $compliancy['register'], schema: $compliancy['schema']));
			if ($entity === null) {
				return ['status' => 404, 'body' => ['message' => 'Claim not found']];
			}

			$record   = (array)$entity->getObject();
			$supplier = $this->supplierOf(record: $record, target: $module);
			$refusal  = ComplianceVerification::refusal(groups: $groups, organisation: $organisation, supplier: $supplier, record: $record);
			if ($refusal !== null) {
				return ['status' => $refusal[0], 'body' => ['message' => $refusal[1]]];
			}

			$stamp = ['verifiedBy' => $reviewer, 'verifiedAt' => $now, 'verifiedByOrganisation' => $organisation];
			self::$writing = true;
			try {
				$objects->saveObject(
					object: array_merge($record, $stamp),
					options: new ObjectSaveOptions(
						target: new ObjectFindTarget(register: $compliancy['register'], schema: $compliancy['schema']),
						uuid: $id,
						extend: []
					),
					_rbac: false,
					_multitenancy: false
				);
			} finally {
				self::$writing = false;
			}
		} catch (Throwable $exception) {
			$this->logger->error('ComplianceVerificationService: could not mark the claim verified', ['id' => $id, 'exception' => $exception->getMessage()]);
			return ['status' => 500, 'body' => ['message' => 'Could not mark the claim verified']];
		}//end try

		return ['status' => 200, 'body' => $stamp];
	}//end verify()

	/**
	 * The supplier organisation of a claim: its module's provider, or null when unknown.
	 *
	 * @param array<string, mixed>              $record The compliancy record.
	 * @param array{register: int, schema: int} $target The module register and schema.
	 *
	 * @return string|null
	 */
	private function supplierOf(array $record, array $target): ?string {
		$moduleId = RelationRefs::ref(value: ($record['module'] ?? null));
		if ($moduleId === null) {
			return null;
		}

		$module = $this->lookup->objectService()->find(
			id: $moduleId,
			options: new ObjectFindOptions(register: $target['register'], schema: $target['schema'], render: false, audit: false),
			_rbac: false,
			_multitenancy: false
		);
		if ($module === null) {
			return null;
		}

		return RelationRefs::ref(value: (((array)$module->getObject())['provider'] ?? null));
	}//end supplierOf()

	/**
	 * The configured register and schema of an object type, or null when either is missing.
	 *
	 * @param string $objectType The object type.
	 *
	 * @return array{register: int, schema: int}|null
	 */
	private function target(string $objectType): ?array {
		$register = $this->types->getRegisterIdForObjectType($objectType);
		$schema   = $this->types->getSchemaIdForObjectType($objectType);
		if ($register === null || $schema === null) {
			return null;
		}

		return ['register' => $register, 'schema' => $schema];
	}//end target()
}//end class
