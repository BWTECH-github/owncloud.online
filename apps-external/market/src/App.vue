<template lang="pug">
	#market-app(v-if="config")
		.bwt-shell
			.bwt-shell__notice(v-if="showNotice").uk-animation-slide-top-small
				.uk-alert-primary(uk-alert)
					span.uk-alert-close(uk-close, @click.prevent="noticeDismissed = true")
					h1.uk-h5 {{ t("Installing and updating Apps is not supported!") }}
					p {{ t("This is a clustered setup or the web server has no permissions to write to the apps folder.") }}
			aside.bwt-shell__sidebar
				navigation
			main.bwt-shell__main
				router-view
</template>

<script>
	// Modified by BW-Tech GmbH for owncloud.online (PHP 8.4).

	import Mixins from './mixins.js'
	import Navigation from './components/Navigation.vue'

	export default {
		mixins: [Mixins],
		data () {
			return {
				"noticeDismissed" : false
			}
		},
		mounted () {
			this.$store.dispatch('FETCH_CONFIG');
			this.$store.dispatch('REFRESH_MARKET');

			this.$store.watch(
				(state)  => {
					return state.installed;
				},
				() => {
					this.$store.dispatch('REBUILD_NAVIGATION');
				}
			);
		},
		computed: {
			config () {
				return this.$store.getters.config
			},
			showNotice() {
				return this.noticeDismissed === false && this.config.canInstall === false
			}
		},
		methods: { },
		components: {
			Navigation
		}
	}
</script>

<style lang="scss" scoped>
	#market-app {
		min-height: 100%;
	}
</style>
