/*
 * Copyright (c) 2014 Vincent Petry <pvince81@owncloud.com>
 *
 * This file is licensed under the Affero General Public License version 3
 * or later.
 *
 * See the COPYING-README file.
 *
 */

describe('OCA.Files.TagsPlugin tests', function() {
	var fileList;
	var testFiles;

	beforeEach(function() {
		var $content = $('<div id="content"></div>');
		$('#testArea').append($content);
		// dummy file list
		var $div = $(
			'<div>' +
			'<table id="filestable">' +
			'<thead></thead>' +
			'<tbody id="fileList"></tbody>' +
			'</table>' +
			'</div>');
		$('#content').append($div);

		fileList = new OCA.Files.FileList($div);
		OCA.Files.TagsPlugin.attach(fileList);

		testFiles = [{
			id: 1,
			type: 'file',
			name: 'One.txt',
			path: '/subdir',
			mimetype: 'text/plain',
			size: 12,
			permissions: OC.PERMISSION_ALL,
			etag: 'abc',
			shareOwner: 'User One',
			isShareMountPoint: false,
			tags: ['tag1', 'tag2']
		}];
	});
	afterEach(function() {
		fileList.destroy();
		fileList = null;
	});

	describe('Favorites icon', function() {
		it('renders favorite icon and extra data', function() {
			var $action, $tr;
			fileList.setFiles(testFiles);
			$tr = fileList.$el.find('tbody tr:first');
			$action = $tr.find('.action-favorite');
			expect($action.length).toEqual(1);
			expect($action.hasClass('permanent')).toEqual(false);

			expect($tr.attr('data-tags').split('|')).toEqual(['tag1', 'tag2']);
			expect($tr.attr('data-favorite')).not.toBeDefined();
		});
		it('renders permanent favorite icon and extra data', function() {
			var $action, $tr;
			testFiles[0].tags.push(OC.TAG_FAVORITE);
			fileList.setFiles(testFiles);
			$tr = fileList.$el.find('tbody tr:first');
			$action = $tr.find('.action-favorite');
			expect($action.length).toEqual(1);
			expect($action.hasClass('permanent')).toEqual(true);

			expect($tr.attr('data-tags').split('|')).toEqual(['tag1', 'tag2', OC.TAG_FAVORITE]);
			expect($tr.attr('data-favorite')).toEqual('true');
		});
		it('adds has-favorites class on table', function() {
			expect(fileList.$el.hasClass('has-favorites')).toEqual(true);
		});
		it('renders the star as a toggle button', function() {
			fileList.setFiles(testFiles);
			var $action = fileList.findFileEl('One.txt').find('.action-favorite');
			expect($action.prop('tagName')).toEqual('BUTTON');
			expect($action.attr('type')).toEqual('button');
			expect($action.attr('href')).not.toBeDefined();
			expect($action.attr('aria-pressed')).toEqual('false');
			expect($action.find('.hidden-visually').text()).toEqual('Favorite');
			// das Label ist Geschwister des Icons, nicht sein Kind
			expect($action.find('.icon .hidden-visually').length).toEqual(0);
		});
		it('renders a pressed toggle button for favorites, same label', function() {
			testFiles[0].tags.push(OC.TAG_FAVORITE);
			fileList.setFiles(testFiles);
			var $action = fileList.findFileEl('One.txt').find('.action-favorite');
			expect($action.attr('aria-pressed')).toEqual('true');
			expect($action.find('.hidden-visually').text()).toEqual('Favorite');
		});
	});
	describe('Applying tags', function() {
		it('sends request to server and updates icon', function() {
			var request;
			fileList.setFiles(testFiles);
			var $tr = fileList.findFileEl('One.txt');
			var $action = $tr.find('.action-favorite');
			$action.click();

			expect(fakeServer.requests.length).toEqual(1);
			request = fakeServer.requests[0];
			expect(JSON.parse(request.requestBody)).toEqual({
				tags: ['tag1', 'tag2', OC.TAG_FAVORITE]
			});
			request.respond(200, {'Content-Type': 'application/json'}, JSON.stringify({
				tags: ['tag1', 'tag2', 'tag3', OC.TAG_FAVORITE]
			}));

			// re-read the element as it was re-inserted
			$tr = fileList.findFileEl('One.txt');
			$action = $tr.find('.action-favorite');

			expect($tr.attr('data-favorite')).toEqual('true');
			expect($tr.attr('data-tags').split('|')).toEqual(['tag1', 'tag2', 'tag3', OC.TAG_FAVORITE]);
			expect(fileList.files[0].tags).toEqual(['tag1', 'tag2', 'tag3', OC.TAG_FAVORITE]);
			expect($action.find('.icon').hasClass('icon-star')).toEqual(false);
			expect($action.find('.icon').hasClass('icon-starred')).toEqual(true);

			$action.click();

			expect(fakeServer.requests.length).toEqual(2);
			request = fakeServer.requests[1];
			expect(JSON.parse(request.requestBody)).toEqual({
				tags: ['tag1', 'tag2', 'tag3']
			});
			request.respond(200, {'Content-Type': 'application/json'}, JSON.stringify({
				tags: ['tag1', 'tag2', 'tag3']
			}));

			// re-read the element as it was re-inserted
			$tr = fileList.findFileEl('One.txt');
			$action = $tr.find('.action-favorite');

			expect($tr.attr('data-favorite')).toBeFalsy();
			expect($tr.attr('data-tags').split('|')).toEqual(['tag1', 'tag2', 'tag3']);
			expect(fileList.files[0].tags).toEqual(['tag1', 'tag2', 'tag3']);
			expect($action.find('.icon').hasClass('icon-star')).toEqual(true);
			expect($action.find('.icon').hasClass('icon-starred')).toEqual(false);
		});
		it('reports the new state before the server answers', function() {
			fileList.setFiles(testFiles);
			var $action = fileList.findFileEl('One.txt').find('.action-favorite');
			$action.click();

			// no response yet: this is still the element that was clicked
			expect(fakeServer.requests.length).toEqual(1);
			expect($action.attr('aria-pressed')).toEqual('true');
			expect($action.hasClass('permanent')).toEqual(true);
			expect($action.find('.icon').hasClass('icon-starred')).toEqual(true);
			expect($action.find('.icon').hasClass('icon-star')).toEqual(false);
			// the icon classes belong to the icon, not to the button
			expect($action.hasClass('icon-starred')).toEqual(false);
			expect($action.hasClass('icon-star')).toEqual(false);
			expect($action.find('.hidden-visually').text()).toEqual('Favorite');
		});
		it('keeps the focus on the star when the row is rebuilt', function() {
			fileList.setFiles(testFiles);
			var $action = fileList.findFileEl('One.txt').find('.action-favorite');
			$action.focus();
			$action.click();
			fakeServer.requests[0].respond(200, {'Content-Type': 'application/json'}, JSON.stringify({
				tags: ['tag1', 'tag2', OC.TAG_FAVORITE]
			}));

			var $newAction = fileList.findFileEl('One.txt').find('.action-favorite');
			expect($newAction[0]).not.toBe($action[0]);
			expect(document.activeElement).toBe($newAction[0]);
			expect($newAction.attr('aria-pressed')).toEqual('true');
		});
		it('leaves the focus alone when it moved on before the answer', function() {
			var $elsewhere = $('<input type="text">');
			$('#testArea').append($elsewhere);
			fileList.setFiles(testFiles);
			var $action = fileList.findFileEl('One.txt').find('.action-favorite');
			$action.focus();
			$action.click();
			$elsewhere.focus();
			fakeServer.requests[0].respond(200, {'Content-Type': 'application/json'}, JSON.stringify({
				tags: ['tag1', 'tag2', OC.TAG_FAVORITE]
			}));

			expect(document.activeElement).toBe($elsewhere[0]);
			$elsewhere.remove();
		});
		it('restores the previous state when the server refuses', function() {
			var notificationStub = sinon.stub(OC.Notification, 'show');
			fileList.setFiles(testFiles);
			var $action = fileList.findFileEl('One.txt').find('.action-favorite');
			$action.click();
			fakeServer.requests[0].respond(500, {'Content-Type': 'application/json'}, '{}');

			expect(fileList.findFileEl('One.txt').find('.action-favorite')[0]).toBe($action[0]);
			expect($action.attr('aria-pressed')).toEqual('false');
			expect($action.hasClass('permanent')).toEqual(false);
			expect($action.find('.icon').hasClass('icon-star')).toEqual(true);
			expect($action.find('.icon').hasClass('icon-starred')).toEqual(false);
			expect(notificationStub.calledOnce).toEqual(true);
			notificationStub.restore();
		});
	});
	describe('elementToFile', function() {
		it('returns tags', function() {
			fileList.setFiles(testFiles);
			var $tr = fileList.findFileEl('One.txt');
			var data = fileList.elementToFile($tr);
			expect(data.tags).toEqual(['tag1', 'tag2']);
		});
		it('returns empty array when no tags present', function() {
			delete testFiles[0].tags;
			fileList.setFiles(testFiles);
			var $tr = fileList.findFileEl('One.txt');
			var data = fileList.elementToFile($tr);
			expect(data.tags).toEqual([]);
		});
	});
});
