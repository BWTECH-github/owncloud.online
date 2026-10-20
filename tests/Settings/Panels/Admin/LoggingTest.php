<?php
/**
 * @author Tom Needham
 * @copyright Copyright (c) 2016 Tom Needham tom@owncloud.com
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace Tests\Settings\Panels\Admin;

use OC\Settings\Panels\Admin\Logging;
use OC\Settings\Panels\Helper;
use OCP\IConfig;
use OCP\IURLGenerator;

/**
 * @package Tests\Settings\Panels\Admin
 */
class LoggingTest extends \Test\TestCase {
	/** @var Logging */
	private $panel;
	/** @var IConfig */
	private $config;
	/** @var  IURLGenerator */
	private $urlGenerator;
	/** @var Helper */
	private $helper;

	public function setUp(): void {
		parent::setUp();
		$this->urlGenerator = $this->getMockBuilder(IURLGenerator::class)->getMock();
		$this->config = $this->getMockBuilder(IConfig::class)->getMock();
		$this->helper = $this->getMockBuilder(Helper::class)->getMock();
		$this->panel = new Logging($this->config, $this->urlGenerator, $this->helper);
	}

	public function testGetSection() {
		$this->assertEquals('general', $this->panel->getSectionID());
	}

	public function testGetPriority() {
		$this->assertIsInt($this->panel->getPriority());
		$this->assertGreaterThan(-100, $this->panel->getPriority());
		$this->assertLessThan(100, $this->panel->getPriority());
	}

	public function testGetPanelWithNoLogFile() {
		$this->helper
			->expects($this->once())
			->method('getLogFilePath')
			->willReturn('/var/log/file/doesnt/exist.log');
		$templateHtml = $this->panel->getPanel()->fetchPage();
		$this->assertStringNotContainsString('<div class="section">', $templateHtml);
		$this->assertStringNotContainsString('id="log-viewer"', $templateHtml);
	}

	public function testGetPanelWithLogFile() {
		$this->helper
			->expects($this->once())
			->method('getLogFilePath')
			->willReturn(__FILE__);
		$this->config->expects($this->exactly(2))->method('getSystemValue')->will($this->returnValueMap([
			['loglevel', 2, 2],
			['log_type', 'owncloud', 'owncloud'],
		]));
		$templateHtml = $this->panel->getPanel()->fetchPage();
		$this->assertStringContainsString('<div class="section">', $templateHtml);
		$this->assertStringContainsString('id="downloadLog"', $templateHtml);

		// Protokollansicht: beschriftete Filter, Live-Region, Tabelle, „Mehr laden“
		$this->assertStringContainsString('<div id="log-viewer" class="log-viewer">', $templateHtml);
		foreach (['log-viewer-level', 'log-viewer-app', 'log-viewer-search'] as $field) {
			$this->assertStringContainsString('<label for="' . $field . '">', $templateHtml);
			$this->assertMatchesRegularExpression('/<(select|input)[^>]* id="' . $field . '"/', $templateHtml);
		}
		$this->assertStringContainsString('id="log-viewer-status" class="log-viewer-status" role="status" aria-live="polite"', $templateHtml);
		// Rollen ausdrücklich: Die Kartenansicht für schmale Breiten setzt display: block
		$this->assertStringContainsString('<table id="log-viewer-table" class="grid log-viewer-table" role="table" aria-labelledby="log-viewer-heading">', $templateHtml);
		$this->assertSame(2, \substr_count($templateHtml, 'role="rowgroup"'));
		$this->assertSame(6, \substr_count($templateHtml, '<th scope="col" role="columnheader"'));
		// Fehler stehen getrennt vom Status und werden sofort angesagt
		$this->assertStringContainsString('<p id="log-viewer-error" class="log-viewer-error" role="alert"></p>', $templateHtml);
		$this->assertStringContainsString('<button type="button" id="log-viewer-more" class="hidden">', $templateHtml);
	}
}
