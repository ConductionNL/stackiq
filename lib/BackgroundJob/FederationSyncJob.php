<?php
/**
 * Federation Sync Background Job.
 *
 * Scheduled job that announces this instance to the directory and (once the
 * peer-pull leg lands) pulls subscribed peers. Delegates all work to
 * FederationService, which no-ops cleanly when OpenCatalogi is unavailable or
 * federation is disabled — so the job is always safe to run.
 *
 * @category  BackgroundJob
 * @package   OCA\SoftwareCatalog\BackgroundJob
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/federated-catalog-sync/specs/federated-catalog-sync/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\BackgroundJob;

use OCA\SoftwareCatalog\Service\Federation\FederationConfig;
use OCA\SoftwareCatalog\Service\Federation\FederationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Scheduled federation announce/pull job.
 */
class FederationSyncJob extends TimedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory      $timeFactory The time factory for scheduling.
     * @param FederationService $federation  The federation service.
     * @param FederationConfig  $config      The federation config (interval).
     * @param LoggerInterface   $logger      The logger.
     */
    public function __construct(
        ITimeFactory $timeFactory,
        private readonly FederationService $federation,
        FederationConfig $config,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(time: $timeFactory);
        $this->setInterval(seconds: $config->getSyncInterval());
    }//end __construct()

    /**
     * Run the federation pass (announce; peer-pull lands with the OR predicate fix).
     *
     * @param mixed $argument Job arguments (not used).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @spec                                          openspec/changes/federated-catalog-sync/specs/federated-catalog-sync/spec.md
     */
    protected function run($argument): void
    {
        if ($this->federation->isAvailable() === false) {
            $this->logger->info('[FederationSyncJob] OpenCatalogi unavailable, skipping run');
            return;
        }

        try {
            $result = $this->federation->announce();
            $this->logger->info('[FederationSyncJob] Announce pass complete', ['result' => $result]);

            // Pull subscribed peers (each peer is isolated — one unreachable
            // peer cannot block the rest; failures mark mirrors stale, never
            // silently delete them).
            $pull = $this->federation->pullAllPeers();
            $this->logger->info('[FederationSyncJob] Peer-pull pass complete', ['result' => $pull]);
        } catch (\Throwable $e) {
            $this->logger->error(
                '[FederationSyncJob] Fatal error during federation pass — cron pass protected',
                ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]
            );
        }
    }//end run()
}//end class
