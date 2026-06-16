<template lang="pug">
	transition(name="fade")
		li(v-if="bundle").uk-animation-slide-top-small.bwt-bundle
			.uk-card.uk-card-default
				.uk-card-header
					h3.bwt-page-head__title.uk-margin-remove-bottom {{ bundle.title }}
					p.uk-text-meta.uk-margin-small-top(v-html="t('Contains <strong>%{no}</strong> Application(s)', { no : this.bundle.products.length })")

				.uk-card-body
					p.bwt-bundle__desc {{ bundle.description }}

					table.uk-table.uk-table-divider.uk-table-middle.uk-table-justify
						thead
							tr
								th {{ t('App') }}
								th {{ t('Version') }}
								th {{ t('Info') }}
						tbody
							tr(v-for="application in bundle.products")
								td
									router-link(:to="{ name: 'details', params: { id: application.id }}") {{ application.title }}
								td
									span {{ (application.release) ? application.release.version : application.installInfo.version }}
								td
									span.bwt-badge.bwt-badge--installed(v-if="isInstalled(application.id) || application.installed") {{ t('installed') }}
									span(v-else-if="isProcessing(application.id)", role="status", :title="t('installing')" uk-tooltip)
										span(uk-spinner, uk-icon="icon: spinner; ratio: 0.8", aria-hidden="true")
										span.uk-hidden-visually {{ t('installing') }}
				.uk-card-footer
					button(v-if="bundle.downloadable && installableApps.length > 0", @click="install").uk-button.uk-button-primary {{ t('install bundle') }}
					a(v-else-if="!bundle.downloadable && installableApps.length === 0", :href="bundle.marketplace", target="_blank").uk-button.uk-button-default {{ t('view in marketplace') }}
</template>

<script>

	import Mixins from '../mixins';
	import Rating from './Rating.vue';
	import Tile from   './Tile.vue';

	export default {
		mixins: [Mixins],
		components: {
			Rating,
			Tile
		},
		props: [
			'bundle'
		],
		filters: {
			cssBackgroundImage (image) {
				return 'background-image:url("' + image + '");';
			}
		},
		computed : {
			installableApps () {
				return _.filter(_.without(this.bundle.products, 'enterprise_key'), function (application) {
					return !application.installed;
				});
			}
		},
		methods: {
			install () {
				if (this.bundle.id === 'enterprise_apps' && this.$store.getters.application('enterprise_key') && !this.$store.getters.application('enterprise_key').installed) {
					this.$store.dispatch('PROCESS_APPLICATION', ['enterprise_key', 'install', { suppressRefetch: true } ])
					.then(() => {
						this.$store.dispatch('INSTALL_BUNDLE', this.installableApps);
					})
					.catch(() => {
						console.warn(this.t('enterprise_key installation failed!'))
					})
				}
				else {
					this.$store.dispatch('INSTALL_BUNDLE', this.installableApps);
				}
			},

			isProcessing (id) {
				return _.contains(this.$store.state.processing, id)
			},

			isInstalled (id) {
				return _.contains(this.$store.state.installed, id)
			},
		}
	}
</script>

<style lang="scss" scoped>
	@import "../styles/variables-theme";

	.bwt-bundle {
		list-style: none;
		display: block;
		margin-bottom: 1.25rem;
	}

	.bwt-bundle__desc {
		margin: 0 0 0.85rem;
		color: var(--bwt-muted);
		line-height: 1.5;
	}
</style>
