/**
* ownCloud
*
* @author Kai Schröer
* @copyright 2018 Kai Schröer <git@schroeer.co>
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
*
*/

describe('App Settings tests', function() {
	var apps = [
		{
			"id" : "app1",
			"author": "Author 1, Author 2",
			"types": [
				"logging",
				"dav"
			]
		},
		{
			"id" : "app2",
			"author": [
				"Author 1",
				"Author 2",
				{
					"@attributes": {
						"email": "author3@owncloud.com"
					},
					"@value": "Author 3"
				}
			],
			"types": [
				"filesystem"
			]
		},
		{
			"id" : "theme-custom",
			"author": "Front End",
			"types": [
				"theme"
			]
		}
	];

	it('should parse the author info', function() {
		var author = OC.Settings.Apps._parseAppAuthor(apps[0].author);
		expect(author).toEqual('Author 1, Author 2');

		author = OC.Settings.Apps._parseAppAuthor(apps[1].author);
		expect(author).toEqual('Author 1, Author 2, Author 3');
	});

	it('should check the app type', function() {
		var isFilesystem = OC.Settings.Apps.isType(apps[0], 'filesystem');
		expect(isFilesystem).toEqual(false);

		isFilesystem = OC.Settings.Apps.isType(apps[1], 'filesystem');
		expect(isFilesystem).toEqual(true);
	});

	it('should protect app themes from enabling for groups', function () {
		var isProtected = OC.Settings.Apps.isProtected(apps[2]);
		expect(isProtected).toEqual(true);
	});

	describe('reloading after a theme was switched', function() {
		var clock;
		var reloadStub;
		var infoStub;
		var reloadMessageStub;
		var rebuildNavigationStub;
		var healthStub;

		/**
		 * Beantwortet die offene Anfrage an enableapp.php bzw. disableapp.php
		 */
		function respondToAppRequest(body) {
			expect(fakeServer.requests.length).toEqual(1);
			fakeServer.requests[0].respond(
				200,
				{'Content-Type': 'application/json'},
				JSON.stringify(body)
			);
		}

		function buttonOf(appId) {
			return $('#app-' + appId + ' input.enable');
		}

		beforeEach(function() {
			clock = sinon.useFakeTimers();
			reloadStub = sinon.stub(OC, 'reload');
			infoStub = sinon.stub(OC.dialogs, 'info');
			reloadMessageStub = sinon.stub(OC.Settings.Apps, 'showReloadMessage');
			rebuildNavigationStub = sinon.stub(OC.Settings.Apps, 'rebuildNavigation');
			healthStub = sinon.stub(OC.Settings.Apps, '_checkServerHealth')
				.returns($.Deferred().resolve().promise());

			OC.Settings.Apps.State.apps = {
				'theme-custom': {id: 'theme-custom', types: ['theme'], active: false, groups: []},
				'app1': {id: 'app1', types: ['logging', 'dav'], active: false, groups: []}
			};
			$('#testArea').append(
				'<div id="app-theme-custom" class="section">' +
				'<input class="enable" type="submit" data-appid="theme-custom" value="Enable">' +
				'<div class="warning hidden"></div></div>' +
				'<div id="app-app1" class="section">' +
				'<input class="enable" type="submit" data-appid="app1" value="Enable">' +
				'<div class="warning hidden"></div></div>'
			);
		});

		afterEach(function() {
			clock.restore();
			reloadStub.restore();
			infoStub.restore();
			reloadMessageStub.restore();
			rebuildNavigationStub.restore();
			healthStub.restore();
			OC.Settings.Apps.State.apps = null;
		});

		it('announces the new appearance and reloads after enabling a theme', function() {
			OC.Settings.Apps.enableApp('theme-custom', false, buttonOf('theme-custom'));
			expect(fakeServer.requests[0].url).toContain('enableapp.php');
			respondToAppRequest({status: 'success', data: {update_required: false}});

			expect(infoStub.calledOnce).toEqual(true);
			expect(infoStub.getCall(0).args[0]).toEqual('Updating the appearance …');
			expect(infoStub.getCall(0).args[3]).toEqual(true);
			expect(reloadStub.notCalled).toEqual(true);

			clock.tick(OC.Settings.Apps._themeReloadDelay - 1);
			expect(reloadStub.notCalled).toEqual(true);
			clock.tick(1);
			expect(reloadStub.calledOnce).toEqual(true);
		});

		it('announces the new appearance and reloads after disabling a theme', function() {
			OC.Settings.Apps.State.apps['theme-custom'].active = true;
			OC.Settings.Apps.enableApp('theme-custom', true, buttonOf('theme-custom'));
			expect(fakeServer.requests[0].url).toContain('disableapp.php');
			respondToAppRequest({status: 'success'});

			expect(infoStub.calledOnce).toEqual(true);
			expect(reloadStub.notCalled).toEqual(true);

			clock.tick(OC.Settings.Apps._themeReloadDelay);
			expect(reloadStub.calledOnce).toEqual(true);
		});

		it('reloads only once when the dialog is confirmed before the delay ran out', function() {
			OC.Settings.Apps.enableApp('theme-custom', false, buttonOf('theme-custom'));
			respondToAppRequest({status: 'success', data: {update_required: false}});

			// OK im Dialog
			infoStub.getCall(0).args[2]();
			expect(reloadStub.calledOnce).toEqual(true);

			clock.tick(OC.Settings.Apps._themeReloadDelay);
			expect(reloadStub.calledOnce).toEqual(true);
		});

		it('leaves the reload to the update redirect when the theme needs an update', function() {
			OC.Settings.Apps.enableApp('theme-custom', false, buttonOf('theme-custom'));
			respondToAppRequest({status: 'success', data: {update_required: true}});

			expect(reloadMessageStub.calledOnce).toEqual(true);
			expect(infoStub.notCalled).toEqual(true);

			// Nur bis kurz vor der Weiterleitung nach 5 Sekunden vorspulen, die
			// ruft location.reload() direkt auf und lüde den Testlauf neu
			clock.tick(4000);
			expect(reloadStub.notCalled).toEqual(true);
		});

		it('does not reload when enabling the theme failed', function() {
			OC.Settings.Apps.enableApp('theme-custom', false, buttonOf('theme-custom'));
			respondToAppRequest({
				status: 'error',
				data: {message: 'theme-custom can\'t be enabled until other-theme is disabled.'}
			});

			clock.tick(10000);
			expect(infoStub.notCalled).toEqual(true);
			expect(reloadStub.notCalled).toEqual(true);
		});

		it('does not reload after enabling an app that is no theme', function() {
			OC.Settings.Apps.enableApp('app1', false, buttonOf('app1'));
			respondToAppRequest({status: 'success', data: {update_required: false}});

			clock.tick(10000);
			expect(infoStub.notCalled).toEqual(true);
			expect(reloadStub.notCalled).toEqual(true);
		});

		it('does not reload after disabling an app that is no theme', function() {
			OC.Settings.Apps.State.apps.app1.active = true;
			OC.Settings.Apps.enableApp('app1', true, buttonOf('app1'));
			respondToAppRequest({status: 'success'});

			clock.tick(10000);
			expect(infoStub.notCalled).toEqual(true);
			expect(reloadStub.notCalled).toEqual(true);
		});
	});
});
