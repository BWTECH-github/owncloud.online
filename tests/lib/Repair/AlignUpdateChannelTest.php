<?php
/**
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace Test\Repair;

use OC\Repair\AlignUpdateChannel;
use OCP\IConfig;
use OCP\Migration\IOutput;
use Test\TestCase;

/**
 * An installation migrated from ownCloud carries core/OC_Channel in its
 * database. On an owncloud.online package that value arms the signature check
 * against a package which ships no signatures, and points update checks at
 * someone else's release channel.
 *
 * @group DB
 */
class AlignUpdateChannelTest extends TestCase {
	/** @var IConfig|\PHPUnit\Framework\MockObject\MockObject */
	private $config;

	/** @var IOutput|\PHPUnit\Framework\MockObject\MockObject */
	private $output;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->output = $this->createMock(IOutput::class);
	}

	public function foreignChannelProvider() {
		return [
			['stable'],
			['production'],
			['beta'],
			['daily'],
		];
	}

	/**
	 * @dataProvider foreignChannelProvider
	 */
	public function testForeignChannelIsRemoved($stored) {
		$this->config->method('getAppValue')
			->with('core', 'OC_Channel', '')
			->willReturn($stored);
		$this->config->expects($this->once())
			->method('deleteAppValue')
			->with('core', 'OC_Channel');
		$this->output->expects($this->once())
			->method('info')
			->with($this->stringContains($stored));

		(new AlignUpdateChannel($this->config, 'bwtech'))->run($this->output);
	}

	public function testOwnChannelIsLeftAlone() {
		$this->config->method('getAppValue')
			->with('core', 'OC_Channel', '')
			->willReturn('bwtech');
		$this->config->expects($this->never())->method('deleteAppValue');

		(new AlignUpdateChannel($this->config, 'bwtech'))->run($this->output);
	}

	public function testNothingStoredIsLeftAlone() {
		$this->config->method('getAppValue')
			->with('core', 'OC_Channel', '')
			->willReturn('');
		$this->config->expects($this->never())->method('deleteAppValue');

		(new AlignUpdateChannel($this->config, 'bwtech'))->run($this->output);
	}

	/**
	 * A value nobody recognises was set on purpose - do not touch it.
	 */
	public function testUnknownChannelIsLeftAlone() {
		$this->config->method('getAppValue')
			->with('core', 'OC_Channel', '')
			->willReturn('kundeneigen');
		$this->config->expects($this->never())->method('deleteAppValue');

		(new AlignUpdateChannel($this->config, 'bwtech'))->run($this->output);
	}
}
