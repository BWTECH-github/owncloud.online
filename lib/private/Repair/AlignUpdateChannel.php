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

namespace OC\Repair;

use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Point the stored update channel back at the channel this package ships with.
 *
 * OC_Util::loadVersion() lets the app value core/OC_Channel override the
 * channel from version.php. An installation migrated from ownCloud carries the
 * old value - 'stable' or 'production' - and that has two consequences on an
 * owncloud.online package:
 *
 *   - The code integrity check is enforced although this package ships no
 *     signatures, so every admin sees "Signature data not found" for core and
 *     for each app.
 *   - Update checks are aimed at ownCloud's release channel rather than ours,
 *     so the reported "newest version" is not one this installation can use.
 *
 * Only values belonging to someone else's release channels are corrected. A
 * deliberate choice among our own channels is left alone.
 */
class AlignUpdateChannel implements IRepairStep {
	/** Channels that exist upstream but not here. */
	private const FOREIGN_CHANNELS = ['stable', 'production', 'beta', 'daily'];

	/** @var IConfig */
	private $config;

	/** @var string channel of the deployed package */
	private $shippedChannel;

	/**
	 * @param IConfig $config
	 * @param string $shippedChannel
	 */
	public function __construct(IConfig $config, $shippedChannel) {
		$this->config = $config;
		$this->shippedChannel = (string)$shippedChannel;
	}

	/**
	 * @return string
	 */
	public function getName() {
		return 'Align the stored update channel with the deployed package';
	}

	/**
	 * @param IOutput $output
	 */
	public function run(IOutput $output) {
		$stored = $this->config->getAppValue('core', 'OC_Channel', '');
		if ($stored === '') {
			// Nothing stored: version.php already decides.
			return;
		}
		if ($stored === $this->shippedChannel) {
			return;
		}
		if (!\in_array($stored, self::FOREIGN_CHANNELS, true)) {
			// Some other value - deliberately set, leave it.
			return;
		}

		// Delete rather than overwrite, so version.php stays the single source
		// of truth and a later package with a different channel is picked up.
		$this->config->deleteAppValue('core', 'OC_Channel');
		$output->info(
			'Update channel was "' . $stored . '", which belongs to the upstream project, not to this package. '
			. 'Removed the override; the channel from version.php ("' . $this->shippedChannel . '") applies again.'
		);
	}
}
