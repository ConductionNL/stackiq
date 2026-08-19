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
 * stayed green for its entire life.
 *
 * ⚠️ THAT WARNING NO LONGER APPLIES TO THE SIX CONTRACT ACCESSORS.
 * ADR-084 §5 made `getUuid()/getObject()/getRegister()/getSchema()/
 * getOrganisation()/getOwner()` DECLARED methods on the real ObjectEntity
 * (`openregister/lib/Db/ObjectEntity.php:767-842`), because `implements`
 * cannot be satisfied by a `@method` annotation over `Entity::__call()`. For
 * those six, stub and production now agree that `method_exists()` is TRUE.
 * `getOrganisation()` is declared below again for that reason — it was removed
 * when it was magic in production, and the reason has since gone away.
 * The warning still stands for every OTHER accessor.
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
abstract class ObjectEntity implements \OCA\OpenRegister\Contract\ObjectEntityInterface {
	/**
	 * The system-level owning organisation.
	 *
	 * DECLARED, not magic — and that is no longer the unfaithfulness the class
	 * docblock warns about. ADR-084 §5 made the real ObjectEntity's contract
	 * getters explicit (`openregister/lib/Db/ObjectEntity.php:833`), because
	 * `implements` cannot be satisfied by a `@method` annotation over
	 * `Entity::__call()`. So `method_exists($entity, 'getOrganisation')` is now
	 * TRUE in production as well as here, and the divergence that let
	 * softwarecatalog#490 stay green is closed at the source rather than
	 * papered over in the double.
	 *
	 * @return ?string The organisation UUID, or null.
	 */
	public function getOrganisation(): ?string {
		return $this->organisation ?? null;
	}//end getOrganisation()

	/**
	 * The owning user.
	 *
	 * Declared for the same reason as {@see getOrganisation()}.
	 *
	 * @return ?string The owner UID, or null.
	 */
	public function getOwner(): ?string {
		return $this->owner ?? null;
	}//end getOwner()


	/**
	 * The system-level owning organisation (`@self.organisation`).
	 *
	 * Kept as a declared PROPERTY so `property_exists($entity, 'organisation')`
	 * is TRUE and the `__call()` route below serves `setOrganisation()` exactly
	 * as `Entity::__call()` does in production. (The accessor above is now also
	 * declared, because ADR-084 §5 declared it on the real entity too — see the
	 * class docblock; that is a change in production, not a divergence here.)
	 *
	 * @var string|null
	 */
	protected ?string $organisation = null;

	/**
	 * The owning Nextcloud user (`@self.owner`).
	 *
	 * Declared for the same reason as {@see $organisation}: without it,
	 * `getOwner()` would read an undeclared property and `setOwner()` would
	 * raise `BadFunctionCallException` from `setter()`, neither of which is
	 * what the real entity does.
	 *
	 * @var string|null
	 */
	protected ?string $owner = null;

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

	// ⚠️ The five accessors below and jsonSerialize() carry NATIVE return types
	// on purpose, copied from OCA\OpenRegister\Contract\ObjectEntityInterface
	// (ADR-084). PHP return types are COVARIANT, so "no declared type" is WIDER
	// than the interface's and is a fatal at class-declaration time, not a
	// warning — `Declaration of ObjectEntity::getUuid() must be compatible with
	// ObjectEntityInterface::getUuid(): ?string`. That single incompatibility
	// killed all six PHPUnit matrix cells the moment this stub started
	// implementing the interface.
	//
	// If the published contract ever changes one of these, change it here to
	// MATCH — never widen it back. `getId()` and `setObject()` are deliberately
	// untyped because they are NOT on the contract; see the class docblock.

	/** @return int */
	abstract public function getId();

	abstract public function getUuid(): ?string;

	/** @return array<string,mixed> */
	abstract public function getObject(): array;

	abstract public function getRegister(): ?string;

	abstract public function getSchema(): ?string;

	/**
	 * @param array<string,mixed>|null $object
	 * @return self
	 */
	abstract public function setObject($object = null);

	abstract public function jsonSerialize(): mixed;

}//end class
