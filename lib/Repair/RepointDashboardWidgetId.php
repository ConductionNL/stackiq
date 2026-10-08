<?php
/**
 * Repair step that re-points dashboards from the renamed widget id to the frozen one.
 *
 * `ConceptOrganisatiesWidget::getId()` is frozen at
 * `softwarecatalog_concept_organisaties_widget`: the Dashboard app stores each
 * user's chosen widgets BY WIDGET ID, so a renamed id silently drops the widget
 * from every dashboard that had it. Between stackiq 0.1.148 and 0.2.0 (stable
 * since 2026-08-30) and on `beta`, the widget nevertheless shipped under the
 * renamed id `stackiq_concept_organisaties_widget`, and restoring the frozen id
 * (#1261) dropped it for everyone who added it in that window — the same silent
 * loss, for the other group of users.
 *
 * The Dashboard app keeps the selection in `oc_preferences` under its own app
 * id `dashboard`, key `layout`, as a comma-separated list of widget ids
 * (`apps/dashboard/lib/Controller/DashboardApiController.php`). That row is an
 * ordinary user preference, so `IConfig` reaches it exactly as
 * `MigrateUserPreferences` reaches this app's own rows. This step rewrites the
 * renamed id to the frozen one for every seen user, so both groups keep the
 * widget.
 *
 * Non-destructive beyond that one id, and idempotent: a layout without the
 * renamed id is left alone, and a layout that already holds the frozen id has
 * the renamed one removed rather than duplicated.
 *
 * All OCP service calls use POSITIONAL arguments (named args are FATAL on
 * `occ upgrade`).
 *
 * @category  Repair
 * @package   OCA\Stackiq\Repair
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/app-id-rename/spec.md#requirement-externally-owned-identifiers-stay-frozen
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Repair;

use OCA\Stackiq\AppInfo\Application;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Rewrites `stackiq_concept_organisaties_widget` to the frozen widget id in every user's dashboard layout.
 *
 * @spec openspec/specs/app-id-rename/spec.md#requirement-externally-owned-identifiers-stay-frozen
 */
class RepointDashboardWidgetId implements IRepairStep {

	/**
	 * The app id the Dashboard app stores its per-user layout under.
	 */
	public const DASHBOARD_APP_ID = 'dashboard';

	/**
	 * The preference key holding the comma-separated widget ids.
	 */
	public const LAYOUT_KEY = 'layout';

	/**
	 * The widget id stackiq 0.1.148 through 0.2.0 registered the widget under.
	 */
	public const RENAMED_WIDGET_ID = 'stackiq_concept_organisaties_widget';

	/**
	 * The frozen, pre-rename widget id `ConceptOrganisatiesWidget::getId()` returns.
	 */
	public const FROZEN_WIDGET_ID = 'softwarecatalog_concept_organisaties_widget';

	/**
	 * Number of users whose layout was rewritten during the current run.
	 *
	 * @var int
	 */
	private int $users = 0;


	/**
	 * Constructor.
	 *
	 * @param IConfig         $config      The user config service.
	 * @param IUserManager    $userManager The user manager.
	 * @param LoggerInterface $logger      The logger.
	 */
	public function __construct(
		private readonly IConfig $config,
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()


	/**
	 * Returns the name of this repair step.
	 *
	 * @return string The repair step name.
	 *
	 * @spec openspec/specs/app-id-rename/spec.md#requirement-externally-owned-identifiers-stay-frozen
	 */
	public function getName(): string {
		return 'Re-point dashboards from the renamed concept organisations widget id to the frozen one';
	}//end getName()


	/**
	 * Rewrite the renamed widget id in every seen user's dashboard layout.
	 *
	 * Reads and writes both sit inside the try: a throw escaping a repair
	 * step aborts `occ upgrade`, and a dashboard row is never worth that.
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-id-rename/spec.md#requirement-externally-owned-identifiers-stay-frozen
	 */
	public function run(IOutput $output): void {
		$this->users = 0;

		try {
			// The callback must return bool|null, not void: IUserManager treats a
			// `false` return as "stop iterating", so null means "keep going".
			$this->userManager->callForSeenUsers(
				function (IUser $user): ?bool {
					try {
						$this->repointUser(user: $user);
					} catch (Throwable $e) {
						// One unreadable user must not stop the walk for the rest.
						$this->logger->warning(
							'Stackiq: could not re-point the dashboard widget id for user ' . $user->getUID() . ': ' . $e->getMessage(),
							[
								'app'       => Application::APP_ID,
								'exception' => $e,
							]
						);
					}

					return null;
				}
			);

			$output->info(
				sprintf(
					'Stackiq: re-pointed the concept organisations widget from "%s" to "%s" on %d dashboard(s)',
					self::RENAMED_WIDGET_ID,
					self::FROZEN_WIDGET_ID,
					$this->users
				)
			);
		} catch (Throwable $e) {
			// Swallowed deliberately — see the docblock.
			$this->logger->error(
				'Stackiq: failed to re-point the dashboard widget id: ' . $e->getMessage(),
				[
					'app'       => Application::APP_ID,
					'exception' => $e,
				]
			);
			$output->warning(
				'Stackiq: dashboard widget re-pointing failed; see the log. '
				. 'Users who added the widget on stackiq 0.1.148 to 0.2.0 may have to add it again.'
			);
		}//end try
	}//end run()


	/**
	 * Rewrite one user's layout when it names the renamed widget id.
	 *
	 * @param IUser $user The user whose dashboard layout to check.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-id-rename/spec.md#requirement-externally-owned-identifiers-stay-frozen
	 */
	protected function repointUser(IUser $user): void {
		$userId = $user->getUID();
		$layout = $this->config->getUserValue($userId, self::DASHBOARD_APP_ID, self::LAYOUT_KEY, '');
		if ($layout === '') {
			return;
		}

		$ids = array_values(
			array_filter(
				array_map('trim', explode(',', $layout)),
				static fn (string $id): bool => $id !== ''
			)
		);
		if (in_array(self::RENAMED_WIDGET_ID, $ids, true) === false) {
			return;
		}

		// Keep the widget in the position the user gave it; never list the
		// frozen id twice when the user somehow holds both.
		$rewritten = [];
		foreach ($ids as $id) {
			if ($id === self::RENAMED_WIDGET_ID) {
				$id = self::FROZEN_WIDGET_ID;
			}

			if (in_array($id, $rewritten, true) === false) {
				$rewritten[] = $id;
			}
		}

		$this->config->setUserValue($userId, self::DASHBOARD_APP_ID, self::LAYOUT_KEY, implode(',', $rewritten));
		$this->users++;
	}//end repointUser()
}//end class
