<?php
/**
 * @author Joas Schilling <coding@schilljs.com>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH
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
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 *
 * Modified by BW-Tech GmbH on 2026-06-24.
 * Changes:
 *   - set the iMIP calendar body content type correctly
 *   - update stale expectations for PHP 8.4 and fork rebrand
 *   - PHP 8.4 compatibility and owncloud.online design integration
 *   - php8.3 (#41449)
 */

namespace OCA\DAV\Tests\unit\CalDAV\Schedule;

use OC\Mail\Mailer;
use OCA\DAV\CalDAV\Schedule\IMipPlugin;
use OCP\IConfig;
use OCP\ILogger;
use OCP\IRequest;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\ITip\Message;
use Test\TestCase;
use OC\Log;

class IMipPluginTest extends TestCase {
	public function testDelivery() {
		$mailMessage = new \OC\Mail\Message(new \Symfony\Component\Mime\Email());
		/** @var Mailer | \PHPUnit\Framework\MockObject\MockObject $mailer */
		$mailer = $this->createMock(Mailer::class);
		$mailer->method('createMessage')->willReturn($mailMessage);
		$mailer->expects($this->once())->method('send');
		/** @var ILogger | \PHPUnit\Framework\MockObject\MockObject $logger */
		$logger = $this->createMock(Log::class);
		/** @var IRequest| \PHPUnit\Framework\MockObject\MockObject $request */
		$request = $this->createMock(IRequest::class);

		$plugin = new IMipPlugin($mailer, $logger, $request);
		$message = new Message();
		$message->method = 'REQUEST';
		$message->message = new VCalendar();
		$message->message->add('VEVENT', [
			'UID' => $message->uid,
			'SEQUENCE' => $message->sequence,
			'SUMMARY' => 'Fellowship meeting',
		]);
		$message->sender = 'mailto:gandalf@wiz.ard';
		$message->recipient = 'mailto:frodo@hobb.it';

		$plugin->schedule($message);
		$this->assertEquals('1.1', $message->getScheduleStatus());
		$this->assertEquals('Fellowship meeting', $mailMessage->getSubject());
		$this->assertEquals('frodo@hobb.it', $mailMessage->getTo()[0]->getAddress());
		$this->assertEquals('gandalf@wiz.ard', $mailMessage->getReplyTo()[0]->getAddress());
		$contentType = $mailMessage->getMessage()->getBody()->getPreparedHeaders()->get('Content-Type');
		$this->assertSame('text/calendar', $contentType->getValue());
		$this->assertSame('REQUEST', $contentType->getParameter('method'));
	}

	public function testFailedDeliveryWithException() {
		$mailMessage = new \OC\Mail\Message(new \Symfony\Component\Mime\Email());
		/** @var Mailer | \PHPUnit\Framework\MockObject\MockObject $mailer */
		$mailer = $this->createMock(Mailer::class);
		$mailer->method('createMessage')->willReturn($mailMessage);
		$mailer->method('send')->willThrowException(new \Exception());
		/** @var ILogger | \PHPUnit\Framework\MockObject\MockObject $logger */
		$logger = $this->createMock(Log::class);
		/** @var IRequest| \PHPUnit\Framework\MockObject\MockObject $request */
		$request = $this->createMock(IRequest::class);

		$plugin = new IMipPlugin($mailer, $logger, $request);
		$message = new Message();
		$message->method = 'REQUEST';
		$message->message = new VCalendar();
		$message->message->add('VEVENT', [
			'UID' => $message->uid,
			'SEQUENCE' => $message->sequence,
			'SUMMARY' => 'Fellowship meeting',
		]);
		$message->sender = 'mailto:gandalf@wiz.ard';
		$message->recipient = 'mailto:frodo@hobb.it';

		$plugin->schedule($message);
		$this->assertEquals('5.0', $message->getScheduleStatus());
		$this->assertEquals('Fellowship meeting', $mailMessage->getSubject());
		$this->assertEquals('frodo@hobb.it', $mailMessage->getTo()[0]->getAddress());
		$this->assertEquals('gandalf@wiz.ard', $mailMessage->getReplyTo()[0]->getAddress());
		$contentType = $mailMessage->getMessage()->getBody()->getPreparedHeaders()->get('Content-Type');
		$this->assertSame('text/calendar', $contentType->getValue());
		$this->assertSame('REQUEST', $contentType->getParameter('method'));
	}

	public function testFailedDelivery() {
		$mailMessage = new \OC\Mail\Message(new \Symfony\Component\Mime\Email());
		/** @var Mailer | \PHPUnit\Framework\MockObject\MockObject $mailer */
		$mailer = $this->createMock(Mailer::class);
		$mailer->method('createMessage')->willReturn($mailMessage);
		$mailer->method('send')->willReturn(['foo@example.net']);
		/** @var ILogger | \PHPUnit\Framework\MockObject\MockObject $logger */
		$logger = $this->createMock(Log::class);
		$logger->expects(self::once())->method('error')->with('Unable to deliver message to {failed}', ['app' => 'dav', 'failed' => 'foo@example.net']);
		/** @var IRequest| \PHPUnit\Framework\MockObject\MockObject $request */
		$request = $this->createMock(IRequest::class);

		$plugin = new IMipPlugin($mailer, $logger, $request);
		$message = new Message();
		$message->method = 'REQUEST';
		$message->message = new VCalendar();
		$message->message->add('VEVENT', [
			'UID' => $message->uid,
			'SEQUENCE' => $message->sequence,
			'SUMMARY' => 'Fellowship meeting',
		]);
		$message->sender = 'mailto:gandalf@wiz.ard';
		$message->recipient = 'mailto:frodo@hobb.it';

		$plugin->schedule($message);
		$this->assertEquals('5.0', $message->getScheduleStatus());
		$this->assertEquals('Fellowship meeting', $mailMessage->getSubject());
		$this->assertEquals('frodo@hobb.it', $mailMessage->getTo()[0]->getAddress());
		$this->assertEquals('gandalf@wiz.ard', $mailMessage->getReplyTo()[0]->getAddress());
		$contentType = $mailMessage->getMessage()->getBody()->getPreparedHeaders()->get('Content-Type');
		$this->assertSame('text/calendar', $contentType->getValue());
		$this->assertSame('REQUEST', $contentType->getParameter('method'));
	}

	public function testDeliveryOfCancel() {
		$mailMessage = new \OC\Mail\Message(new \Symfony\Component\Mime\Email());
		/** @var Mailer | \PHPUnit\Framework\MockObject\MockObject $mailer */
		$mailer = $this->createMock(Mailer::class);
		$mailer->method('createMessage')->willReturn($mailMessage);
		$mailer->expects($this->once())->method('send');
		/** @var ILogger | \PHPUnit\Framework\MockObject\MockObject $logger */
		$logger = $this->createMock(Log::class);
		/** @var IRequest| \PHPUnit\Framework\MockObject\MockObject $request */
		$request = $this->createMock(IRequest::class);

		$plugin = new IMipPlugin($mailer, $logger, $request);
		$message = new Message();
		$message->method = 'CANCEL';
		$message->message = new VCalendar();
		$message->message->add('VEVENT', [
			'UID' => $message->uid,
			'SEQUENCE' => $message->sequence,
			'SUMMARY' => 'Fellowship meeting',
		]);
		$message->sender = 'mailto:gandalf@wiz.ard';
		$message->recipient = 'mailto:frodo@hobb.it';

		$plugin->schedule($message);
		$this->assertEquals('1.1', $message->getScheduleStatus());
		$this->assertEquals('Cancelled: Fellowship meeting', $mailMessage->getSubject());
		$this->assertEquals('frodo@hobb.it', $mailMessage->getTo()[0]->getAddress());
		$this->assertEquals('gandalf@wiz.ard', $mailMessage->getReplyTo()[0]->getAddress());
		$contentType = $mailMessage->getMessage()->getBody()->getPreparedHeaders()->get('Content-Type');
		$this->assertSame('text/calendar', $contentType->getValue());
		$this->assertSame('CANCEL', $contentType->getParameter('method'));
		$this->assertEquals('CANCELLED', $message->message->VEVENT->STATUS->getValue());
	}

	/**
	 * Builds a REQUEST from $sender to $recipient.
	 *
	 * @param string $sender
	 * @param string $recipient
	 * @return Message
	 */
	private function buildRequest($sender, $recipient) {
		$message = new Message();
		$message->method = 'REQUEST';
		$message->message = new VCalendar();
		$message->message->add('VEVENT', [
			'UID' => $message->uid,
			'SEQUENCE' => $message->sequence,
			'SUMMARY' => 'Fellowship meeting',
		]);
		$message->sender = $sender;
		$message->recipient = $recipient;
		return $message;
	}

	/**
	 * A mailer that hands out $mailMessage and does not send, but keeps its
	 * real validateMailAddress(): a check stricter than the mail layer would
	 * show up in the tests below.
	 *
	 * @param \OC\Mail\Message $mailMessage
	 * @return Mailer | \PHPUnit\Framework\MockObject\MockObject
	 */
	private function buildMailerWithRealValidation($mailMessage) {
		$mailer = $this->getMockBuilder(Mailer::class)
			->setConstructorArgs([
				$this->createMock(IConfig::class),
				$this->createMock(ILogger::class),
				$this->createMock(\OC_Defaults::class),
			])
			->onlyMethods(['createMessage', 'send'])
			->getMock();
		$mailer->method('createMessage')->willReturn($mailMessage);
		return $mailer;
	}

	/**
	 * An attendee address no mail can go to ('MAILTO:hausmeister') used to
	 * throw out of schedule() and fail the whole CalDAV PUT with HTTP 500.
	 * Only the invitation may be left out, with SCHEDULE-STATUS 3.7, and a
	 * warning an admin sees with the default log level.
	 *
	 * @dataProvider providesUnusableRecipients
	 * @param string $recipient
	 */
	public function testInvalidRecipientSkipsOnlyTheInvitation($recipient) {
		$mailMessage = new \OC\Mail\Message(new \Symfony\Component\Mime\Email());
		$mailer = $this->buildMailerWithRealValidation($mailMessage);
		$mailer->expects($this->never())->method('send');
		/** @var ILogger | \PHPUnit\Framework\MockObject\MockObject $logger */
		$logger = $this->createMock(Log::class);
		$logger->expects($this->never())->method('logException');
		$logger->expects($this->never())->method('error');
		$logger->expects($this->once())->method('warning')
			->with($this->stringContains('invitation'), ['app' => 'dav', 'recipient' => $recipient]);
		/** @var IRequest| \PHPUnit\Framework\MockObject\MockObject $request */
		$request = $this->createMock(IRequest::class);

		$plugin = new IMipPlugin($mailer, $logger, $request);
		$message = $this->buildRequest('mailto:gandalf@wiz.ard', 'mailto:' . $recipient);

		$plugin->schedule($message);
		$this->assertEquals('3.7', $message->getScheduleStatus());
	}

	public function providesUnusableRecipients() {
		return [
			'no domain' => ['hausmeister'],
			'user name only' => ['lehrer1'],
			'empty domain' => ['frodo@'],
			'empty' => [''],
		];
	}

	/**
	 * The check for an unusable attendee address must not be stricter than
	 * the mail layer: addresses it sends to (a domain with an underscore, a
	 * blank the mail layer trims) still get their invitation, as they did
	 * before the check existed.
	 *
	 * @dataProvider providesRecipientsTheMailLayerAccepts
	 * @param string $recipient
	 * @param string $expectedTo
	 */
	public function testRecipientTheMailLayerAcceptsGetsTheInvitation($recipient, $expectedTo) {
		$mailMessage = new \OC\Mail\Message(new \Symfony\Component\Mime\Email());
		$mailer = $this->buildMailerWithRealValidation($mailMessage);
		$mailer->expects($this->once())->method('send')->willReturn([]);
		/** @var ILogger | \PHPUnit\Framework\MockObject\MockObject $logger */
		$logger = $this->createMock(Log::class);
		$logger->expects($this->never())->method('logException');
		$logger->expects($this->never())->method('warning');
		/** @var IRequest| \PHPUnit\Framework\MockObject\MockObject $request */
		$request = $this->createMock(IRequest::class);

		$plugin = new IMipPlugin($mailer, $logger, $request);
		$message = $this->buildRequest('mailto:gandalf@wiz.ard', 'mailto:' . $recipient);

		$plugin->schedule($message);
		$this->assertEquals('1.1', $message->getScheduleStatus());
		$this->assertEquals($expectedTo, $mailMessage->getTo()[0]->getAddress());
	}

	public function providesRecipientsTheMailLayerAccepts() {
		return [
			'underscore in domain' => ['user@foo_bar.example', 'user@foo_bar.example'],
			'leading blank' => [' foo@example.com', 'foo@example.com'],
			'trailing blank' => ['foo@example.com ', 'foo@example.com'],
			'idn domain' => ["frodo@hobb\u{00ED}t.example", 'frodo@xn--hobbt-2sa.example'],
		];
	}

	/**
	 * A sender (organizer) address the mail layer rejects must not fail the
	 * PUT either: the mail is not sent and the status says so.
	 */
	public function testInvalidSenderFailsOnlyTheInvitation() {
		$mailMessage = new \OC\Mail\Message(new \Symfony\Component\Mime\Email());
		/** @var Mailer | \PHPUnit\Framework\MockObject\MockObject $mailer */
		$mailer = $this->createMock(Mailer::class);
		$mailer->method('createMessage')->willReturn($mailMessage);
		$mailer->expects($this->never())->method('send');
		/** @var ILogger | \PHPUnit\Framework\MockObject\MockObject $logger */
		$logger = $this->createMock(Log::class);
		/** @var IRequest| \PHPUnit\Framework\MockObject\MockObject $request */
		$request = $this->createMock(IRequest::class);

		$plugin = new IMipPlugin($mailer, $logger, $request);
		$message = $this->buildRequest('mailto:gandalf', 'mailto:frodo@hobb.it');

		$plugin->schedule($message);
		$this->assertEquals('5.0', $message->getScheduleStatus());
	}
}
