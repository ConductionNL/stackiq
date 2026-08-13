<?php

/**
 * Auth-posture regression tests for the endpoints hardened alongside #492.
 *
 * WHY THIS TEST IS A SOURCE PARSER AND NOT A REQUEST TEST
 * ------------------------------------------------------
 * The thing being pinned is a DECLARATION, not a behaviour. Nextcloud's
 * middleware decides admin-vs-not by reading the docblock before the controller
 * body ever runs, so the property "this endpoint is admin-only" lives in the
 * annotation set and nowhere else. A request-level test would need the whole
 * framework to observe what one regex decides.
 *
 * THE REGEX IS COPIED FROM NEXTCLOUD ON PURPOSE
 * ---------------------------------------------
 * `OC\AppFramework\Utility\ControllerMethodReflector::reflect()` uses
 *
 *     /^\h+\*\h+@(?P<annotation>[A-Z]\w+)((?P<parameter>.*))?$/m
 *
 * and this file uses the same one, because the near-miss it exists to catch is
 * invisible to a looser check. While writing the fix these methods documented
 * their own hardening with the sentence "the endpoint must not declare
 * @NoAdminRequired" — and that token, sitting at the start of a comment line,
 * MATCHES. Nextcloud would have gone on treating the endpoint as
 * non-admin-required: the sentence explaining the removal would have undone the
 * removal, and the change would have read as a security fix while being a no-op.
 *
 * A test that searched for the attribute form, or that stripped comments first,
 * would pass over exactly that mistake. So the assertion is deliberately made
 * against the same bytes the framework reads.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\SoftwareCatalog\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class AdminAuthPostureTest extends TestCase {
	/**
	 * Nextcloud's own annotation regex, byte for byte.
	 *
	 * @var string
	 */
	private const NC_ANNOTATION_RE = '/^\h+\*\h+@(?P<annotation>[A-Z]\w+)((?P<parameter>.*))?$/m';

	/**
	 * Absolute path to a controller source file.
	 *
	 * @param string $name Class file name without extension.
	 *
	 * @return string
	 */
	private function controllerPath(string $name): string {
		return __DIR__ . '/../../../lib/Controller/' . $name . '.php';
	}

	/**
	 * The raw docblock immediately preceding a method declaration.
	 *
	 * Returns the comment block only — the search starts from the method's
	 * `public function <name>(` and walks back to the nearest `/**`.
	 *
	 * @param string $source The whole file.
	 * @param string $method The method name.
	 *
	 * @return string|null The docblock, or null when the method is absent.
	 */
	private function docblockFor(string $source, string $method): ?string {
		$declPos = strpos($source, 'public function ' . $method . '(');
		if ($declPos === false) {
			return null;
		}

		$open = strrpos(substr($source, 0, $declPos), '/**');
		if ($open === false) {
			return null;
		}

		return substr($source, $open, ($declPos - $open));
	}

	/**
	 * Annotation names Nextcloud would parse out of a method's docblock.
	 *
	 * @param string $source The whole file.
	 * @param string $method The method name.
	 *
	 * @return array<int, string>
	 */
	private function annotationsFor(string $source, string $method): array {
		$doc = $this->docblockFor($source, $method);
		self::assertNotNull($doc, "Method $method not found — this test's target moved");

		$matches = [];
		preg_match_all(self::NC_ANNOTATION_RE, $doc, $matches);

		return $matches['annotation'];
	}

	/**
	 * The body text of a method, up to the next method declaration.
	 *
	 * @param string $source The whole file.
	 * @param string $method The method name.
	 *
	 * @return string
	 */
	private function bodyFor(string $source, string $method): string {
		$declPos = strpos($source, 'public function ' . $method . '(');
		self::assertNotFalse($declPos, "Method $method not found — this test's target moved");

		$next = strpos($source, "\n\tpublic function ", ($declPos + 10));
		$next = $next === false ? strlen($source) : $next;

		return substr($source, $declPos, ($next - $declPos));
	}

	/**
	 * POSITIVE CONTROL — the parser can find the annotation when it IS there.
	 *
	 * Without this, a typo in the regex or a broken docblock walk would make
	 * every "must not be present" assertion below pass over an empty array, and
	 * the whole file would be green while asserting nothing. `getSyncStatus`
	 * legitimately keeps the annotation (it takes no object reference), so it is
	 * the natural control and it lives in the same file as the subjects.
	 *
	 * @return void
	 */
	public function testTheParserCanActuallyFindTheAnnotation(): void {
		$source = (string)file_get_contents($this->controllerPath('SettingsController'));

		self::assertContains(
			'NoAdminRequired',
			$this->annotationsFor($source, 'getSyncStatus'),
			'The control failed: the parser cannot see an annotation that IS present, '
			. 'so every absence assertion in this file would be vacuous'
		);
	}

	/**
	 * The four admin actions must NOT be reachable by an ordinary user.
	 *
	 * Each one either writes app configuration, drives an outbound connection to
	 * a caller-named host, or triggers a register-wide sync.
	 *
	 * @return void
	 */
	public function testAdminActionsDoNotDeclareNoAdminRequired(): void {
		$source = (string)file_get_contents($this->controllerPath('SettingsController'));

		$adminOnly = [
			'testEmailConnection' => 'opens an outbound connection to a caller-supplied host and port',
			'updateEmailTemplate' => 'writes app configuration rendered into outbound mail',
			'syncOrganisations' => 'triggers a register-wide write sync',
		];

		foreach ($adminOnly as $method => $why) {
			self::assertNotContains(
				'NoAdminRequired',
				$this->annotationsFor($source, $method),
				"$method $why, so it must not be reachable without admin. "
				. 'Note this also fails if the annotation appears only inside PROSE at the '
				. 'start of a docblock line — which is exactly how it was nearly reinstated.'
			);
		}
	}

	/**
	 * The mail-template reads move with the write they belong to.
	 *
	 * They expose admin-authored templates and have no non-admin consumer: no
	 * frontend code calls these routes at all.
	 *
	 * @return void
	 */
	public function testEmailTemplateReadsAreAdminOnly(): void {
		$source = (string)file_get_contents($this->controllerPath('SettingsController'));

		foreach (['getEmailTemplate', 'getEmailTemplateDefault', 'getEmailTemplateVariables'] as $method) {
			self::assertNotContains(
				'NoAdminRequired',
				$this->annotationsFor($source, $method),
				"$method reads admin-authored mail templates and must not be public to any authenticated user"
			);
		}
	}

	/**
	 * exportArchiMate KEEPS the annotation and gains the permission helper.
	 *
	 * Both halves matter and they pull in opposite directions. The helper grants
	 * organisation-admins as well as admins, which is the tier the admin UI
	 * relies on — so removing the annotation would have been the WRONG fix here,
	 * even though it was the right fix for its neighbours. Its sibling
	 * exportOrgArchiMate has carried the same helper all along; this endpoint
	 * exports the whole register and was the unguarded one.
	 *
	 * @return void
	 */
	public function testExportArchiMateIsGuardedRatherThanClosed(): void {
		$source = (string)file_get_contents($this->controllerPath('SettingsController'));

		self::assertContains(
			'NoAdminRequired',
			$this->annotationsFor($source, 'exportArchiMate'),
			'exportArchiMate must stay reachable by organisation-admins; the guard is the helper, not the annotation'
		);

		self::assertStringContainsString(
			'verifyOrgExportPermission',
			$this->bodyFor($source, 'exportArchiMate'),
			'exportArchiMate exports the WHOLE register and must run its sibling\'s permission check'
		);
	}

	/**
	 * The SBOM import-status read is scoped to the caller.
	 *
	 * `SbomImportService::getStatus()` resolves with RBAC and multitenancy both
	 * off, so without this guard a caller-supplied moduleVersieUuid reached
	 * storage unscoped.
	 *
	 * @return void
	 */
	public function testSbomImportStatusIsGuarded(): void {
		$source = (string)file_get_contents($this->controllerPath('SbomController'));
		$body = $this->bodyFor($source, 'getSbomImportStatus');

		// Anchored on the CALL, `$this->authorizeRead(`, not the bare name.
		// The first draft asserted on the bare token and failed — because the
		// comment in the controller EXPLAINING the ordering names the method,
		// and that mention sits above the `try`. A positional assertion over a
		// corpus that includes prose measures the prose. Same family as the
		// docblock trap this whole file exists to pin, one level down.
		self::assertStringContainsString(
			'$this->authorizeRead(',
			$body,
			'getSbomImportStatus takes a caller-supplied uuid to a lookup that bypasses RBAC; it needs its read guard'
		);

		// The guard must sit INSIDE the try. authorizeRead() reaches
		// ObjectService::find(), which RE-THROWS DoesNotExistException for a
		// well-formed but unknown uuid — guarding ahead of the try turns this
		// endpoint's clean 404 into a 500 for exactly the callers the guard was
		// added for.
		self::assertLessThan(
			strpos($body, '$this->authorizeRead('),
			strpos($body, 'try {'),
			'The read guard must sit inside the try block, or an unknown uuid 500s instead of 404ing'
		);
	}
}
