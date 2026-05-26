/**
 * Heartbeat utility for keeping long-running connections alive
 *
 * This utility prevents 504 gateway timeouts by sending periodic
 * keep-alive requests during long operations.
 *
 * @author Conduction B.V. <info@conduction.nl>
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 */

/**
 * Heartbeat class for managing keep-alive requests
 */
class Heartbeat {

	/**
	 * Create a new heartbeat instance
	 *
	 * @param {number} interval - Heartbeat interval in milliseconds (default: 30000 = 30s)
	  * @spec exclude class constructor — DI/initialization only
	 */
	constructor(interval = 30000) {
		this.interval = interval
		this.timer = null
		this.isRunning = false
		this.endpoint = '/index.php/apps/softwarecatalog/api/heartbeat'
	}

	/**
	 * Start sending heartbeat requests
	 *
	 * @return {void}
	  * @spec openspec/changes/retrofit-2026-05-26-fe-stores/tasks.md#task-7
	 */
	start() {
		if (this.isRunning) {
			return
		}

		this.isRunning = true
		console.debug('Starting heartbeat with interval:', this.interval + 'ms')

		// Send first heartbeat immediately
		this.sendHeartbeat()

		// Set up periodic heartbeats
		this.timer = setInterval(() => {
			this.sendHeartbeat()
		}, this.interval)
	}

	/**
	 * Stop sending heartbeat requests
	 *
	 * @return {void}
	  * @spec openspec/changes/retrofit-2026-05-26-fe-stores/tasks.md#task-7
	 */
	stop() {
		if (!this.isRunning) {
			return
		}

		this.isRunning = false

		if (this.timer) {
			clearInterval(this.timer)
			this.timer = null
		}

		console.debug('Heartbeat stopped')
	}

	/**
	 * Send a single heartbeat request
	 *
	 * @private
	 * @return {void}
	  * @spec openspec/changes/retrofit-2026-05-26-fe-stores/tasks.md#task-7
	 */
	async sendHeartbeat() {
		try {
			const response = await fetch(this.endpoint, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-Requested-With': 'XMLHttpRequest',
					requesttoken: OC.requestToken,
				},
				body: JSON.stringify({
					timestamp: Date.now(),
				}),
			})

			if (!response.ok) {
				console.warn('Heartbeat request failed:', response.status, response.statusText)
			} else {
				console.debug('Heartbeat sent successfully')
			}
		} catch (error) {
			console.warn('Heartbeat request error:', error.message)
		}
	}

	/**
	 * Check if heartbeat is currently running
	 *
	 * @return {boolean} True if heartbeat is running
	 */
	get running() {
		return this.isRunning
	}

}

// Export singleton instance for global use
const heartbeat = new Heartbeat()

/**
 * Start heartbeat for long operations
 *
 * @param {number} interval - Optional custom interval in milliseconds
 * @return {void}
  * @spec openspec/changes/retrofit-2026-05-26-fe-stores/tasks.md#task-7
 */
export function startHeartbeat(interval) {
	if (interval) {
		heartbeat.interval = interval
	}
	heartbeat.start()
}

/**
 * Stop heartbeat
 *
 * @return {void}
  * @spec openspec/changes/retrofit-2026-05-26-fe-stores/tasks.md#task-7
 */
export function stopHeartbeat() {
	heartbeat.stop()
}

/**
 * Check if heartbeat is running
 *
 * @return {boolean} True if running
  * @spec openspec/changes/retrofit-2026-05-26-fe-stores/tasks.md#task-7
 */
export function isHeartbeatRunning() {
	return heartbeat.running
}

/**
 * Convenience function to wrap a long-running operation with heartbeat
 *
 * @param {Function} operation - Async function to execute
 * @param {number} interval - Optional heartbeat interval in milliseconds
 * @return {Promise} Promise that resolves with the operation result
  * @spec openspec/changes/retrofit-2026-05-26-fe-stores/tasks.md#task-7
 */
export async function withHeartbeat(operation, interval = 30000) {
	try {
		startHeartbeat(interval)
		return await operation()
	} finally {
		stopHeartbeat()
	}
}

export default heartbeat
