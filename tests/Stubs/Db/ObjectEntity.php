<?php

/**
 * Test stub for OCA\OpenRegister\Db\ObjectEntity.
 *
 * The real ObjectEntity is an NC AppFramework Entity whose getters are
 * `__call` magic methods, so PHPUnit cannot configure them on a mock. This
 * stub declares the getters/setters the unit tests stub explicitly. Resolved
 * via the `OCA\OpenRegister\ => tests/Stubs/` autoload-dev mapping.
 *
 * ⚠️ KNOWN UNFAITHFULNESS — read before adding a declaration here.
 * Every accessor below is magic on the REAL ObjectEntity, so declaring it here
 * makes `method_exists()` TRUE in the suite and FALSE in production. A test
 * built on this stub therefore CANNOT detect a `method_exists()` probe against
 * an OpenRegister entity — that is exactly how softwarecatalog#490 (the
 * organisation merge re-pointing nothing while still tombstoning the source)
 * stayed green for its entire life. `getOrganisation()`/`setOrganisation()`
 * were removed from this stub for that reason.
 *
 * If your subject probes for an accessor, do NOT add it here. Declare the
 * attribute as a `protected` PROPERTY instead (as `organisation` is below) and
 * build the double as a concrete subclass of this stub rather than a
 * `createMock()`, so `__call()` serves the accessor exactly as it does in
 * production. `tests/Unit/Service/MergeOrganisatieServiceTest::entity()` is
 * the worked example.
 *
 * A faithful double must be a SUBCLASS of this stub, not of some other base:
 * `ObjectService::find()` declares `?ObjectEntity`, and an incompatible return
 * raises a `TypeError` that `MergeOrganisatieService::findOrganisatie()`
 * swallows in a `catch (\Throwable)`, turning a wiring mistake into a
 * plausible-looking `source-not-found` blocker.
 *
 * ⚠️ The `__call`/`getter`/`setter` triple below MIRRORS
 * `OCP\AppFramework\Db\Entity` (`:159`, `:175`) rather than inheriting it, and
 * that is deliberate. `tests/bootstrap.php` `require_once`s every file in
 * `tests/Stubs/` BEFORE Nextcloud's `lib/base.php`, precisely so this stub wins
 * over the real OpenRegister class during mock generation — so at load time no
 * `OCP\` class is resolvable yet, and extending one makes the whole suite die
 * in the bootstrap with `Class "OCP\AppFramework\Db\Entity" not found`.
 * Keeping the stub free-standing is what lets it load under BOTH
 * `tests/bootstrap.php` and `tests/bootstrap-unit.php`. The semantics that
 * matter are reproduced exactly: `get*`/`set*` resolve through
 * `property_exists()`, anything else raises `BadFunctionCallException`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\SoftwareCatalog\Tests\Stubs\Db
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use BadFunctionCallException;

/**
 * Stub for ObjectEntity with the surface used by SoftwareCatalog tests.
 */
abstract class ObjectEntity {

	/**
	 * The system-level owning organisation (`@self.organisation`).
	 *
	 * A PROPERTY, not a declared accessor — on the real ObjectEntity this is
	 * `protected ?string $organisation` reached through `Entity::__call()`, so
	 * `method_exists($entity, 'getOrganisation')` is FALSE and
	 * `property_exists($entity, 'organisation')` is TRUE. Declaring it this way
	 * is what lets a test tell the two apart. See softwarecatalog#490.
	 *
	 * @var string|null
	 */
	protected ?string $organisation = null;

	/**
	 * Magic accessor dispatch, mirroring `OCP\AppFramework\Db\Entity::__call()`.
	 *
	 * @param string $method The called method name.
	 * @param array<mixed> $args The call arguments.
	 *
	 * @return mixed
	 *
	 * @throws BadFunctionCallException When the name maps to no attribute.
	 */
	public function __call(string $method, array $args) {
		if (str_starts_with($method, 'get') === true) {
			return $this->getter(lcfirst(substr($method, 3)));
		}

		if (str_starts_with($method, 'set') === true) {
			$this->setter(lcfirst(substr($method, 3)), $args);
			return $this;
		}

		throw new BadFunctionCallException($method . ' does not exist');
	}//end __call()

	/**
	 * Generic attribute read, mirroring `Entity::getter()`.
	 *
	 * @param string $name The attribute name.
	 *
	 * @return mixed
	 *
	 * @throws BadFunctionCallException When no such property exists.
	 */
	protected function getter(string $name) {
		if (property_exists($this, $name) === false) {
			throw new BadFunctionCallException($name . ' is not a valid attribute');
		}

		return $this->$name;
	}//end getter()

	/**
	 * Generic attribute write, mirroring `Entity::setter()`.
	 *
	 * @param string $name The attribute name.
	 * @param array<mixed> $args The call arguments.
	 *
	 * @return void
	 *
	 * @throws BadFunctionCallException When no such property exists.
	 */
	protected function setter(string $name, array $args): void {
		if (property_exists($this, $name) === false) {
			throw new BadFunctionCallException($name . ' is not a valid attribute');
		}

		$this->$name = ($args[0] ?? null);
	}//end setter()

	/** @return int */
	abstract public function getId();

	/** @return string */
	abstract public function getUuid();

	/** @return array<string,mixed> */
	abstract public function getObject();

	/** @return mixed */
	abstract public function getRegister();

	/** @return mixed */
	abstract public function getSchema();

	/**
	 * @param array<string,mixed>|null $object
	 * @return self
	 */
	abstract public function setObject($object = null);

	/** @return array<string,mixed> */
	abstract public function jsonSerialize();

}//end class
