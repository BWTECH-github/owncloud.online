<?php
/**
 * @author BW-Tech GmbH
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Test\Files;

use OC\Files\Filesystem;
use OC\Files\Storage\Wrapper\ReadOnlyJail;
use OC\Files\View;
use Test\TestCase;
use Test\Traits\UserTrait;

/**
 * Nur-Lese-Hülle, die OC_Util::setupFS() für Gäste und Mitglieder von
 * Nur-Lese-Gruppen um deren Home legt
 *
 * @group DB
 */
class ReadOnlyHomeSetupTest extends TestCase {
	use UserTrait;

	private const PROBE = '/files_external/readonly-home-setup-test.txt';

	/** @var string[] */
	private $guests = [];

	protected function setUp(): void {
		parent::setUp();

		foreach (['guest_a_', 'guest_b_'] as $prefix) {
			$uid = $this->getUniqueID($prefix);
			$this->createUser($uid);
			\OC::$server->getConfig()->setUserValue($uid, 'owncloud', 'isGuest', '1');
			$this->guests[] = $uid;
		}
		self::resetFilesystem();
	}

	protected function tearDown(): void {
		self::resetFilesystem();
		foreach ($this->guests as $uid) {
			\OC::$server->getConfig()->deleteUserValue($uid, 'owncloud', 'isGuest');
		}
		parent::tearDown();
	}

	private static function resetFilesystem(): void {
		\OC_Util::tearDownFS();
		\OC_User::setUserId('');
		// definierter Ausgangszustand, auch wenn ein anderer Test die Hülle hinterlassen hat
		Filesystem::getLoader()->removeStorageWrapper('oc_readonly');
	}

	private static function isReadOnlyJailed(string $mountPoint): bool {
		return Filesystem::getMountManager()->find($mountPoint)->getStorage()
			->instanceOfStorage(ReadOnlyJail::class);
	}

	/**
	 * Schreibprobe dort, wo der Zertifikatsspeicher das Systembündel ablegt
	 */
	private static function canWriteSystemCertificateStore(): bool {
		$view = new View('');
		if (!$view->file_exists('/files_external')) {
			$view->mkdir('/files_external');
		}
		$handle = $view->fopen(self::PROBE, 'w');
		if (!\is_resource($handle)) {
			return false;
		}
		\fclose($handle);
		$view->unlink(self::PROBE);
		return true;
	}

	public function testGuestHomeIsReadOnlyButTheDataDirectoryRootIsNot() {
		\OC_Util::setupFS($this->guests[0]);

		$this->assertTrue(self::isReadOnlyJailed('/' . $this->guests[0] . '/'));
		$this->assertFalse(self::isReadOnlyJailed('/'));
		$this->assertTrue(self::canWriteSystemCertificateStore());
	}

	public function testDataDirectoryRootIsWritableAfterAGuestFilesystemWasTornDown() {
		// Ablauf in occ system:cron: ein Job richtet das Dateisystem eines Gasts ein,
		// die Schleife räumt es ab, der nächste Job braucht das Systembündel
		\OC_Util::setupFS($this->guests[0]);
		\OC_Util::tearDownFS();

		$this->assertTrue(self::canWriteSystemCertificateStore());
	}

	public function testNextGuestInTheSameProcessIsReadOnlyToo() {
		\OC_Util::setupFS($this->guests[0]);
		\OC_Util::tearDownFS();
		\OC_Util::setupFS($this->guests[1]);

		$this->assertTrue(self::isReadOnlyJailed('/' . $this->guests[1] . '/'));
	}
}
