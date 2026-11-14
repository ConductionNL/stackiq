<?php

/**
 * Unit tests for ContactPersonEmail.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service\Stackiq
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Stackiq;

use OCA\Stackiq\Kernel\Contracts\StackiqContactSyncServiceInterface;
use OCA\Stackiq\Service\Stackiq\ContactPersonEmail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The email address a contact person's account is made for.
 */
#[CoversClass(ContactPersonEmail::class)]
class ContactPersonEmailTest extends TestCase {

	/**
	 * The Nextcloud Contacts lookups.
	 *
	 * @var StackiqContactSyncServiceInterface&MockObject
	 */
	private StackiqContactSyncServiceInterface&MockObject $contacts;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->contacts = $this->createMock(StackiqContactSyncServiceInterface::class);
	}//end setUp()

	/**
	 * A contact made with the current form takes the address of its linked contact.
	 *
	 * @return void
	 */
	public function testTheAddressComesFromTheLinkedContact(): void {
		$this->contacts->expects($this->once())->method('findContactByUid')->with('uid-anna')
			->willReturn(['UID' => 'uid-anna', 'EMAIL' => ['anna@leverancier.nl']]);

		$email = (new ContactPersonEmail($this->contacts))->of(['contactsUid' => 'uid-anna', 'organisation' => 'org-1']);

		$this->assertSame('anna@leverancier.nl', $email);
	}//end testTheAddressComesFromTheLinkedContact()

	/**
	 * The linked contact wins over an address the record still carries.
	 *
	 * @return void
	 */
	public function testTheLinkedContactWinsOverTheRecord(): void {
		$this->contacts->method('findContactByUid')->willReturn(['EMAIL' => [['type' => 'WORK', 'value' => ' anna@leverancier.nl ']]]);

		$email = (new ContactPersonEmail($this->contacts))->of(['contactsUid' => 'uid-anna', 'e-mailadres' => 'oud@leverancier.nl']);

		$this->assertSame('anna@leverancier.nl', $email);
	}//end testTheLinkedContactWinsOverTheRecord()

	/**
	 * A single address, not a list, is read too; empty entries are skipped.
	 *
	 * @return void
	 */
	public function testAPlainAddressAndEmptyEntries(): void {
		$this->contacts->method('findContactByUid')->willReturnOnConsecutiveCalls(
			['EMAIL' => 'piet@leverancier.nl'],
			['EMAIL' => ['', ['value' => ''], 42, 'kees@leverancier.nl']]
		);
		$resolver = new ContactPersonEmail($this->contacts);

		$this->assertSame('piet@leverancier.nl', $resolver->of(['contactsUid' => 'uid-piet']));
		$this->assertSame('kees@leverancier.nl', $resolver->of(['contactsUid' => 'uid-kees']));
	}//end testAPlainAddressAndEmptyEntries()

	/**
	 * Without a linked address the record's own address is used, `email` before `e-mailadres`.
	 *
	 * @return void
	 */
	public function testTheRecordsAddressIsTheFallback(): void {
		$this->contacts->method('findContactByUid')->willReturn(null);
		$resolver = new ContactPersonEmail($this->contacts);

		$this->assertSame('a@b.nl', $resolver->of(['contactsUid' => 'gone', 'email' => ' a@b.nl ', 'e-mailadres' => 'c@d.nl']));
		$this->assertSame('c@d.nl', $resolver->of(['contactsUid' => 'gone', 'email' => '  ', 'e-mailadres' => 'c@d.nl']));
	}//end testTheRecordsAddressIsTheFallback()

	/**
	 * A record without a contactsUid is not looked up.
	 *
	 * @return void
	 */
	public function testNoUidNoLookup(): void {
		$this->contacts->expects($this->never())->method('findContactByUid');
		$resolver = new ContactPersonEmail($this->contacts);

		$this->assertSame('a@b.nl', $resolver->of(['email' => 'a@b.nl']));
		$this->assertSame('', $resolver->of(['contactsUid' => '  ']));
		$this->assertSame('', $resolver->of(['contactsUid' => ['not', 'a', 'string']]));
	}//end testNoUidNoLookup()

	/**
	 * A linked contact without an address and a record without one give no address.
	 *
	 * @return void
	 */
	public function testNoAddressAnywhereIsEmpty(): void {
		$this->contacts->method('findContactByUid')->willReturn(['UID' => 'uid-x', 'FN' => 'X']);

		$this->assertSame('', (new ContactPersonEmail($this->contacts))->of(['contactsUid' => 'uid-x']));
	}//end testNoAddressAnywhereIsEmpty()
}//end class
