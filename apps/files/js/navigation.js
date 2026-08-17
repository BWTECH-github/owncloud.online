/*
 * Copyright (c) 2014
 *
 * @author Vincent Petry
 * @copyright Copyright (c) 2014 Vincent Petry <pvince81@owncloud.com>
 *
 * This file is licensed under the Affero General Public License version 3
 * or later.
 *
 * See the COPYING-README file.
 *
 */

(function() {

	/**
	 * @class OCA.Files.Navigation
	 * @classdesc Navigation control for the files app sidebar.
	 *
	 * @param $el element containing the navigation
	 */
	var Navigation = function($el) {
		this.initialize($el);
	};

	/**
	 * @memberof OCA.Files
	 */
	Navigation.prototype = {

		/**
		 * Currently selected item in the list
		 */
		_activeItem: null,

		/**
		 * Currently selected container
		 */
		$currentContent: null,

		/**
		 * Initializes the navigation from the given container
		 *
		 * @private
		 * @param $el element containing the navigation
		 */
		initialize: function($el) {
			this.$el = $el;
			this._activeItem = null;
			this.$currentContent = null;
			this._setupEvents();
		},

		/**
		 * Setup UI events
		 */
		_setupEvents: function() {
			this.$el.on('click', 'li a', _.bind(this._onClickItem, this));
		},

		/**
		 * Returns the container of the currently active app.
		 *
		 * @return app container
		 */
		getActiveContainer: function() {
			return this.$currentContent;
		},

		/**
		 * Returns the currently active item
		 * 
		 * @return item ID
		 */
		getActiveItem: function() {
			return this._activeItem;
		},

		/**
		 * Moves the content of the given view container into its inert
		 * <template> child. Parked content lives in a DocumentFragment
		 * outside the document: its ids are invisible to getElementById,
		 * CSS and the accessibility tree. No-op when the container has
		 * no template child or the browser does not support <template> -
		 * the view then simply stays in the document as before.
		 *
		 * @param $container view container (#app-content-<viewid>)
		 */
		_parkContent: function($container) {
			var template = $container.children('template.viewcontent')[0];
			if (!template || !template.content) {
				return;
			}
			while (template.nextSibling) {
				template.content.appendChild(template.nextSibling);
			}
		},

		/**
		 * Counterpart of _parkContent: moves the parked content back into
		 * the container. Element references and event handlers survive
		 * the round trip through the fragment.
		 *
		 * @param $container view container (#app-content-<viewid>)
		 */
		_unparkContent: function($container) {
			var template = $container.children('template.viewcontent')[0];
			if (!template || !template.content) {
				return;
			}
			$container[0].appendChild(template.content);
		},

		/**
		 * Switch the currently selected item, mark it as selected and
		 * make the content container visible, if any.
		 *
		 * @param string itemId id of the navigation item to select
		 * @param array options "silent" to not trigger event
		 */
		setActiveItem: function(itemId, options) {
			var oldItemId = this._activeItem;
			var self = this;
			if (itemId === this._activeItem) {
				if (!options || !options.silent) {
					this.$el.trigger(
						new $.Event('itemChanged', {itemId: itemId, previousItemId: oldItemId})
					);
				}
				return;
			}
			this.$el.find('li').removeClass('active');
			if (this.$currentContent) {
				this.$currentContent.addClass('hidden');
				this.$currentContent.trigger(jQuery.Event('hide'));
			}
			this._activeItem = itemId;
			this.$el.find('li[data-id=' + itemId + ']').addClass('active');
			this.$currentContent = $('#app-content-' + itemId);
			// park every other view so that only one set of list ids exists
			// in the document at any time (OC-WCAG-279). This also covers
			// the very first call, where $currentContent was still null and
			// the hide branch above did not run.
			$('#app-content > .viewcontainer').not(this.$currentContent).each(function() {
				self._parkContent($(this));
			});
			this._unparkContent(this.$currentContent);
			this.$currentContent.removeClass('hidden');
			if (!options || !options.silent) {
				this.$currentContent.trigger(jQuery.Event('show'));
				this.$el.trigger(
					new $.Event('itemChanged', {itemId: itemId, previousItemId: oldItemId})
				);
			}
		},

		/**
		 * Returns whether a given item exists
		 */
		itemExists: function(itemId) {
			return this.$el.find('li[data-id=' + itemId + ']').length;
		},

		/**
		 * Event handler for when clicking on an item.
		 */
		_onClickItem: function(ev) {
			var $target = $(ev.target);
			var itemId = $target.closest('li').attr('data-id');
			this.setActiveItem(itemId);
			ev.preventDefault();
		}
	};

	OCA.Files.Navigation = Navigation;

})();
