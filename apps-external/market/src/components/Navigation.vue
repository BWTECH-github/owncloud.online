<template lang="pug">
	nav.bwt-sidebar(aria-label="Market navigation")
		.bwt-search
			input(
				type="search",
				:value="searchQuery",
				:placeholder="t('Search apps...')",
				:aria-label="t('Search apps')",
				@input="onSearch"
			)

		ul.bwt-sidebar__nav
			li
				router-link(:to="{ name: 'index' }", exact)
					span {{ t('Discover') }}
			li
				router-link(:to="{ name: 'InstalledApps' }")
					span {{ t('Installed Apps') }}
					span.bwt-sidebar__count(v-if="localAppCount > 0") {{ localAppCount }}
			li
				router-link(:to="{ name: 'Bundles' }")
					span {{ t('App Bundles') }}
			li
				router-link(:to="{ name: 'UpdateList' }")
					span {{ t('Updates') }}
					span.bwt-sidebar__count(v-if="updateList.length > 0") {{ updateList.length }}

			li.bwt-sidebar__section(v-if="!loading && !failed && categories.length") {{ t('Categories') }}

			li(v-for="category in categories", :key="category.id")
				router-link(:to="{ name: 'byCategory', params: { category: category.id }}")
					span {{ categoryLabel(category) }}

			li.bwt-sidebar__section {{ t('Settings') }}

			apiform

			li
				//- WCAG 4.1.2: leeres href="" zeigt auf die aktuelle URL und kann bei
				//- ausbleibendem preventDefault einen Reload ausloesen. href="#" + @click.prevent
				//- haelt die vorhandene Sidebar-Link-Optik, ohne ein gueltiges Ziel vorzutaeuschen.
				a(href="#", @click.prevent="invalidateCache")
					span {{ t('Clear cache') }}
</template>

<script>
	// Modified by BW-Tech GmbH for owncloud.online (PHP 8.4).

	import Apiform from './ApiForm.vue'

	export default {
		mounted () {
			this.$store.dispatch('FETCH_CATEGORIES')
		},
		methods: {
			t(string) {
				return this.$gettext(string);
			},
			invalidateCache () {
				this.$store.dispatch('INVALIDATE_CACHE')
			},
			onSearch (event) {
				this.$store.dispatch('UPDATE_SEARCH', event.target.value);
			},
			categoryLabel (category) {
				if (!category) {
					return ''
				}
				if (category.translations) {
					const locale = (typeof OC !== 'undefined' && OC.getLocale) ? OC.getLocale().slice(0, 2) : 'en';
					const translated = category.translations[locale] || category.translations.en;
					if (translated && translated.name) {
						return translated.name;
					}
				}
				return category.name || category.displayName || category.label || this.formatCategory(category.id);
			},
			formatCategory (id) {
				if (!id) {
					return ''
				}
				return String(id)
					.replace(/[-_]+/g, ' ')
					.replace(/\b\w/g, function (character) {
						return character.toUpperCase();
					});
			}
		},
		computed: {
			loading() {
				return this.$store.state.categories.loading
			},
			failed() {
				return this.$store.state.categories.failed
			},
			categories() {
				if (this.loading || this.failed) {
					return []
				}
				const records = this.$store.state.categories.records || []
				return Array.isArray(records) ? records : Object.keys(records).map((key) => records[key])
			},
			updateList() {
				return this.$store.getters.updateList
			},
			localAppCount() {
				return this.$store.getters.localApplications.length
			},
			searchQuery() {
				return this.$store.getters.searchQuery
			}
		},
		components: {
			Apiform
		}
	}
</script>

<style lang="scss" scoped>
	@import "../styles/variables-theme";

	.bwt-sidebar__section {
		display: block;
	}
</style>
