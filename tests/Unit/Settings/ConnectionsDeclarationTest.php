<?php

/**
 * The connection declaration integriq reads.
 *
 * `lib/Settings/connections.json` is static JSON that integriq turns into the
 * rows of stackiq's Integrations page. Nothing in stackiq reads it at runtime,
 * so a broken file fails nowhere in this repo: integriq skips it whole and the
 * page goes empty on some other instance. Every assertion here is a way that
 * file could go wrong without a sound.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-stackiq-conn-001-stackiq-declares-its-outside-connections-in-one-static-file
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use OCA\Stackiq\Service\ConnectionReportService;
use PHPUnit\Framework\TestCase;

/**
 * Guards lib/Settings/connections.json against design D2 of connection-registry.
 *
 * The rules mirror integriq's `lib/Settings/connections.schema.json` on
 * `development` field for field, including the hydra#673 amendments
 * (`adapter.jsonPath`, `adapter.simulatedValues`, `reportedOnly`). That schema
 * is not a dependency of this repo, so the rules are restated here. The file
 * was also validated against the schema itself, fetched from integriq
 * `development` with `gh api`, when this test was written.
 *
 * @coversNothing
 */
class ConnectionsDeclarationTest extends TestCase {

	/**
	 * The fields the schema allows on one connection, with their JSON type.
	 *
	 * @var array<string, string>
	 */
	private const FIELD_TYPES = [
		'key'                 => 'string',
		'title'               => 'string',
		'description'         => 'string',
		'order'               => 'integer',
		'settingsUrl'         => 'string',
		'requiredConfig'      => 'array',
		'adapter'             => 'array',
		'reportedOnly'        => 'boolean',
		'available'           => 'boolean',
		'unavailableMessage'  => 'string',
		'unconfiguredMessage' => 'string',
		'sourceTemplate'      => 'string',
	];

	/**
	 * The fields the schema allows inside `adapter`, with their JSON type.
	 *
	 * @var array<string, string>
	 */
	private const ADAPTER_FIELD_TYPES = [
		'configKey'        => 'string',
		'jsonPath'         => 'string',
		'simulatedValues'  => 'array',
		'simulatedMessage' => 'string',
	];

	/**
	 * The three connections, in page order.
	 *
	 * @var array<int, string>
	 */
	private const KEYS = ['email', 'federation', 'eol-feed'];

	/**
	 * The repository root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * The raw declaration file.
	 *
	 * @return string
	 */
	private function raw(): string {
		$raw = file_get_contents($this->root() . '/lib/Settings/connections.json');
		$this->assertIsString(actual: $raw, message: 'lib/Settings/connections.json must exist');

		return $raw;
	}//end raw()

	/**
	 * The decoded declaration.
	 *
	 * @return array<string, mixed>
	 */
	private function declaration(): array {
		$decoded = json_decode($this->raw(), true, 512, JSON_THROW_ON_ERROR);
		$this->assertIsArray(actual: $decoded);

		return $decoded;
	}//end declaration()

	/**
	 * The declared connections, keyed by connection key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function connectionsByKey(): array {
		$byKey = [];
		foreach ($this->declaration()['connections'] as $connection) {
			$byKey[(string) $connection['key']] = $connection;
		}

		return $byKey;
	}//end connectionsByKey()

	/**
	 * The file names the app it ships in, and nothing else at the top level.
	 *
	 * Integriq refuses a file whose `app` differs from the app it was read from.
	 *
	 * @return void
	 */
	public function testTheFileNamesThisApp(): void {
		$declaration = $this->declaration();
		$infoXml     = simplexml_load_file($this->root() . '/appinfo/info.xml');

		$this->assertNotFalse(condition: $infoXml);
		$this->assertSame(expected: (string) $infoXml->id, actual: $declaration['app']);
		$this->assertSame(expected: 'stackiq', actual: $declaration['app']);
		$this->assertSame(expected: ['app', 'connections'], actual: array_keys($declaration));
	}//end testTheFileNamesThisApp()

	/**
	 * Every key is unique, well formed, and the one the report service sends.
	 *
	 * A row is keyed by app and key. A report for a key the file does not
	 * declare is refused by integriq with only a warning in its log.
	 *
	 * @return void
	 */
	public function testTheKeysAreUniqueAndTheReportedOnes(): void {
		$keys = array_column($this->declaration()['connections'], 'key');

		$this->assertSame(expected: array_values(array_unique($keys)), actual: $keys);
		$this->assertSame(expected: self::KEYS, actual: $keys);
		$this->assertSame(
			expected: self::KEYS,
			actual: [ConnectionReportService::KEY_EMAIL, ConnectionReportService::KEY_FEDERATION, ConnectionReportService::KEY_EOL]
		);
	}//end testTheKeysAreUniqueAndTheReportedOnes()

	/**
	 * Every entry uses only schema fields with the schema's types, and a rising order.
	 *
	 * @return void
	 */
	public function testEveryEntryHasTheShapeIntegriqValidates(): void {
		$previousOrder = 0;
		foreach ($this->declaration()['connections'] as $connection) {
			$key = (string) $connection['key'];

			$this->assertSame(
				expected: [],
				actual: array_diff(array_keys($connection), array_keys(self::FIELD_TYPES)),
				message: $key . ' carries a field the schema does not allow'
			);
			foreach ($connection as $field => $value) {
				$this->assertSame(expected: self::FIELD_TYPES[$field], actual: $this->jsonType(value: $value), message: $key . '.' . $field);
			}

			foreach (($connection['adapter'] ?? []) as $field => $value) {
				$this->assertArrayHasKey(key: $field, array: self::ADAPTER_FIELD_TYPES, message: $key . '.adapter.' . $field . ' is not a schema field');
				$this->assertSame(expected: self::ADAPTER_FIELD_TYPES[$field], actual: $this->jsonType(value: $value), message: $key . '.adapter.' . $field);
			}

			$this->assertMatchesRegularExpression(pattern: '/^[a-z0-9]+(-[a-z0-9]+)*$/', string: $key);
			$this->assertNotSame(expected: '', actual: trim((string) ($connection['title'] ?? '')), message: $key . ' has no title');
			$this->assertStringStartsWith(prefix: '/', string: (string) ($connection['settingsUrl'] ?? '/'), message: $key);
			$this->assertGreaterThan(expected: $previousOrder, actual: $connection['order'], message: $key . ' breaks the page order');
			$previousOrder = $connection['order'];
		}
	}//end testEveryEntryHasTheShapeIntegriqValidates()

	/**
	 * No text a reader sees carries an em-dash or a Title Case title (voice rule 8).
	 *
	 * @return void
	 */
	public function testNoTextBreaksTheVoiceRules(): void {
		$this->assertStringNotContainsString(needle: "\u{2014}", haystack: $this->raw());
		$this->assertStringNotContainsString(needle: '--', haystack: $this->raw());

		foreach ($this->declaration()['connections'] as $connection) {
			$words = explode(' ', (string) $connection['title']);
			foreach (array_slice($words, 1) as $word) {
				$this->assertSame(expected: mb_strtolower($word), actual: $word, message: $connection['key'] . ' title is not sentence case');
			}
		}
	}//end testNoTextBreaksTheVoiceRules()

	/**
	 * Every settings link points at the stackiq admin section, at an id a section component defines.
	 *
	 * The admin section is `stackiq` (StackiqAdmin::getSection()). An anchor
	 * that no element carries opens the settings page at the top.
	 *
	 * @return void
	 */
	public function testEverySettingsLinkPointsAtASectionThatExists(): void {
		$sections = '';
		foreach (glob($this->root() . '/src/views/settings/sections/*.vue') as $file) {
			$sections .= (string) file_get_contents($file);
		}

		$admin = (string) file_get_contents($this->root() . '/lib/Settings/StackiqAdmin.php');
		$this->assertStringContainsString(needle: "\$sectionName = 'stackiq';", haystack: $admin);

		foreach ($this->declaration()['connections'] as $connection) {
			$url = (string) ($connection['settingsUrl'] ?? '');
			$this->assertMatchesRegularExpression(
				pattern: '~^/settings/admin/stackiq#section-[a-z0-9-]+$~',
				string: $url,
				message: $connection['key'] . ' links somewhere other than a stackiq admin section'
			);

			$anchor = substr($url, (strpos($url, '#') + 1));
			$this->assertSame(
				expected: 1,
				actual: substr_count($sections, 'id="' . $anchor . '"'),
				message: $connection['key'] . ' links to #' . $anchor . ', and no section component carries that id once'
			);
		}
	}//end testEverySettingsLinkPointsAtASectionThatExists()

	/**
	 * The null transport reads simulated, and an empty or real transport does not.
	 *
	 * This applies the declared values the way integriq's ConnectionConfigReader
	 * does: trimmed, case-insensitive, against `simulatedValues`, which default
	 * to one empty string when absent. An empty `email_transport_type` sends
	 * mail through SMTP (SymfonyEmailService::createTransport()), so reading it
	 * as simulated would be a false Simulated.
	 *
	 * @return void
	 */
	public function testOnlyTheNullTransportReadsSimulated(): void {
		$adapter = $this->connectionsByKey()['email']['adapter'];

		$this->assertSame(expected: 'email_transport_type', actual: $adapter['configKey']);
		$this->assertArrayNotHasKey(key: 'jsonPath', array: $adapter);
		$this->assertTrue(condition: $this->readsSimulated(adapter: $adapter, value: 'null'));
		$this->assertTrue(condition: $this->readsSimulated(adapter: $adapter, value: ' NULL '));
		foreach (['', 'smtp', 'sendmail', 'native', 'sendgrid', 'mailgun', 'postmark', 'ses', 'mailjet'] as $real) {
			$this->assertFalse(condition: $this->readsSimulated(adapter: $adapter, value: $real), message: '"' . $real . '" must not read simulated');
		}

		$emailService = (string) file_get_contents($this->root() . '/lib/Service/SymfonyEmailService.php');
		$this->assertStringContainsString(needle: "'null' => 'Null (No Emails)'", haystack: $emailService);
		$this->assertMatchesRegularExpression(pattern: "/case 'null':\s+return Transport::fromDsn\('null:\/\/null'\);/", string: $emailService);
		$this->assertMatchesRegularExpression(pattern: '/default:.*?return \$this->createSmtpTransport/s', string: $emailService);
	}//end testOnlyTheNullTransportReadsSimulated()

	/**
	 * Federation and the end-of-life feed are reported only, and neither guesses from settings.
	 *
	 * `federation_enabled` is a boolean key, which integriq's reader counts as
	 * filled whatever it holds, and `eol_sync_config` is filled after any save.
	 *
	 * @return void
	 */
	public function testFederationAndTheFeedAreReportedOnly(): void {
		$byKey = $this->connectionsByKey();

		foreach (['federation', 'eol-feed'] as $key) {
			$this->assertTrue(condition: $byKey[$key]['reportedOnly'], message: $key);
			$this->assertArrayNotHasKey(key: 'requiredConfig', array: $byKey[$key], message: $key);
			$this->assertArrayNotHasKey(key: 'adapter', array: $byKey[$key], message: $key);
			$this->assertStringStartsWith(prefix: 'Not checked yet.', string: $byKey[$key]['unconfiguredMessage'], message: $key);
		}

		$this->assertArrayNotHasKey(key: 'reportedOnly', array: $byKey['email']);
		$this->assertArrayNotHasKey(key: 'requiredConfig', array: $byKey['email']);
	}//end testFederationAndTheFeedAreReportedOnly()

	/**
	 * The end-of-life feed offers integriq's endoflife.date source.
	 *
	 * The slug is the one integriq seeds in
	 * `lib/Settings/register.d/endoflife-date-source.json`, and the defaults
	 * stackiq reads the feed from are integriq's register and schemas.
	 *
	 * @return void
	 */
	public function testTheFeedOffersTheEndoflifeDateSource(): void {
		$this->assertSame(expected: 'endoflife-date', actual: $this->connectionsByKey()['eol-feed']['sourceTemplate']);

		$settings = (string) file_get_contents($this->root() . '/lib/Service/SettingsService.php');
		$this->assertStringContainsString(needle: "EOL_DEFAULT_REGISTER = 'integriq'", haystack: $settings);
	}//end testTheFeedOffersTheEndoflifeDateSource()

	/**
	 * Whether integriq's rule 3 reads a value as simulated for this adapter.
	 *
	 * @param array<string, mixed> $adapter The declared adapter block.
	 * @param string               $value   The app-config value.
	 *
	 * @return bool
	 */
	private function readsSimulated(array $adapter, string $value): bool {
		$values = ($adapter['simulatedValues'] ?? ['']);
		$needle = mb_strtolower(trim($value));
		foreach ($values as $candidate) {
			if (mb_strtolower(trim((string) $candidate)) === $needle) {
				return true;
			}
		}

		return false;
	}//end readsSimulated()

	/**
	 * The JSON type name of a decoded value.
	 *
	 * @param mixed $value The decoded value.
	 *
	 * @return string
	 */
	private function jsonType(mixed $value): string {
		return match (true) {
			is_bool($value) => 'boolean',
			is_int($value) => 'integer',
			is_string($value) => 'string',
			is_array($value) => 'array',
			default => get_debug_type($value),
		};
	}//end jsonType()
}//end class
