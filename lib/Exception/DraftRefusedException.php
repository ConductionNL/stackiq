<?php

/**
 * DraftRefusedException
 *
 * OpenRegister refused a write of an assistant-drafted view: the caller may
 * not create the view, an element or a relation.
 *
 * @category  Exception
 * @package   OCA\Stackiq\Exception
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/architecture-assistant-views/spec.md#requirement-req-aav-005-the-draft-tools-shall-run-with-the-callers-rights-and-shall-keep-drafts-out-of-shared-readers
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Exception;

use RuntimeException;

/**
 * The caller's rights refused a draft write; the tool answers forbidden.
 *
 * @spec openspec/specs/architecture-assistant-views/spec.md#requirement-req-aav-005-the-draft-tools-shall-run-with-the-callers-rights-and-shall-keep-drafts-out-of-shared-readers
 */
class DraftRefusedException extends RuntimeException {
}//end class
