<template lang="pug">
	div
		.uk-position-fixed.uk-position-center(v-show="loading", uk-spinner, uk-icon="icon: spinner")

		header.bwt-page-head(v-if="!loading && !failed")
			div
				h1.bwt-page-head__title {{ pageTitle }}
				p.bwt-page-head__subtitle(v-if="searchQuery") {{ t('Showing results for "%{query}"', { query: searchQuery }) }}
				p.bwt-page-head__subtitle(v-else-if="category") {{ t('Apps in %{category}', { category: categoryDisplayName }) }}
				p.bwt-page-head__subtitle(v-else) {{ t('Browse and install apps for your owncloud.online') }}

		ul.bwt-app-grid(v-if="!loading && !failed && applications.length")
			Tile(v-for="application in applications", :application="application", :key="application.id")

		transition(name="fade")
			.uk-card.uk-card-default.uk-card-body.bwt-empty(v-if="applications.length === 0 && !loading && !failed")
				p.uk-text-center(v-if="searchQuery") {{ t('No apps match "%{query}"', { query: searchQuery }) }}
				p.uk-text-center(v-else) {{ t('No apps in %{category}', { category: categoryDisplayName }) }}
</template>

<script>
	import Mixins from '../mixins';
	import Tile from './Tile.vue';

	export default {
		mixins: [Mixins],
		components: {
			Tile
		},
		computed: {
			loading() {
				return this.$store.state.applications.loading
			},
			failed() {
				return this.$store.state.applications.failed
			},
			applications() {
				if (this.loading || this.failed) {
					return []
				}
				return this.$store.getters.applications(this.category)
			},
			category() {
				return this.$route.params.category
			},
			searchQuery () {
				return this.$store.getters.searchQuery
			},
			pageTitle () {
				if (this.searchQuery) {
					return this.t('Search')
				}
				if (this.category) {
					return this.categoryDisplayName
				}
				return this.t('Discover')
			},
			categoryRecord () {
				return this.category ? this.$store.getters.category(this.category) : null
			},
			categoryDisplayName () {
				return this.categoryLabel(this.categoryRecord || { id: this.category })
			}
		},
		methods: {
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
				const label = category.name || category.displayName || category.label || category.id || '';
				return String(label)
					.replace(/[-_]+/g, ' ')
					.replace(/\b\w/g, function (character) {
						return character.toUpperCase();
					});
			}
		}
	}
</script>

<style lang="scss" scoped>
	@import "../styles/variables-theme";
</style>
