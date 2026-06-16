<template lang="pug">
	section.bwt-updates
		.uk-position-fixed.uk-position-center(v-show="loading", uk-spinner, uk-icon="icon: spinner")

		header.bwt-page-head(v-if="!failed")
			div
				h1.bwt-page-head__title {{ t('Updates') }}
				p.bwt-page-head__subtitle(v-if="applications.length") {{ t('Apps with newer versions available.') }}
				p.bwt-page-head__subtitle(v-else) {{ t('All apps are up to date.') }}
			button.uk-button.uk-button-secondary(type="button", @click="refreshUpdates", :disabled="loading")
				span(v-if="loading", uk-spinner, uk-icon="icon: spinner; ratio: 0.7").uk-margin-small-right
				span {{ t('Refresh updates') }}

		.uk-alert-danger(v-if="failed", uk-alert)
			p {{ t('Could not load updates.') }}

		.bwt-updates-card(v-else-if="applications.length")
			.bwt-update-row(v-for="application in applications", :key="application.id")
				.bwt-update-row__main
					router-link.bwt-update-row__title(:to="{ name: 'details', params: { id: application.id }}") {{ application.name }}
					p.bwt-update-row__meta
						span {{ publisherName(application) }}
						span(v-if="application.installInfo && application.installInfo.version")  - {{ t('Installed') }} {{ application.installInfo.version }}
					p.bwt-update-row__changelog(v-if="releaseChangelog(application)") {{ releaseChangelog(application) }}
				.bwt-update-row__versions
					span.bwt-update-row__label {{ t('Available version') }}
					select.uk-select.bwt-update-select(v-if="hasMultipleUpdates(application)", v-model="application.updateTo")
						option(v-for="release in availableReleases(application)", :key="release.version", :value="release.version") {{ release.version }}
					strong(v-else) {{ targetVersion(application) }}
				.bwt-update-row__action
					button.uk-button.uk-button-primary.uk-position-relative(
						type="button",
						@click="update(application, application.updateTo || targetVersion(application))",
						:disabled="processing(application.id)"
					)
						span(v-if="processing(application.id)")
							span.uk-position-small.uk-position-center-left(uk-spinner, uk-icon="icon: spinner; ratio: 0.8")
							span.uk-margin-small-left &nbsp;&nbsp; {{ t('Updating') }}
						span(v-else) {{ t('Update') }}

		.uk-card.uk-card-default.uk-card-body.bwt-empty(v-else-if="!loading")
			p.uk-text-center {{ t('All apps are up to date') }}
</template>

<script>
	import _ from 'underscore'

	export default {
		methods: {
			update (application, version) {
				this.$store.dispatch('PROCESS_APPLICATION', [application.id, 'update', {'version' : version}])
			},
			refreshUpdates () {
				this.$store.dispatch('INVALIDATE_CACHE')
			},
			processing(id) {
				return _.contains(this.$store.state.processing, id)
			},
			t(string) {
				return this.$gettext(string);
			},
			availableReleases (application) {
				return [
					application.minorUpdate,
					application.majorUpdate
				].filter(function (release) {
					return release !== false && release !== undefined && release !== null;
				});
			},
			hasMultipleUpdates (application) {
				return this.availableReleases(application).length > 1;
			},
			targetRelease (application) {
				const releases = this.availableReleases(application);
				if (!releases.length) {
					return null;
				}
				const selected = application.updateTo;
				return releases.find(function (release) {
					return release.version === selected;
				}) || releases[0];
			},
			targetVersion (application) {
				const release = this.targetRelease(application);
				return release ? release.version : '';
			},
			releaseChangelog (application) {
				const release = this.targetRelease(application);
				return release && release.changelog ? release.changelog : '';
			},
			publisherName (application) {
				const publisher = application.publisher || {};
				return publisher.name || application.author || this.t('Unknown developer');
			}
		},
		computed : {
			loading() {
				return this.$store.state.applications.loading
			},
			failed() {
				return this.$store.state.applications.failed
			},
			applications() {
				if (this.loading || this.failed) {
					return []
				} else {
					return this.$store.getters.updateList
				}
			}
		}
	}
</script>

<style lang="scss" scoped>
	@import "../styles/variables-theme";

	.bwt-updates {
		width: 100%;
	}

	.bwt-updates-card {
		display: flex;
		flex-direction: column;
		gap: 0.9rem;
		width: 100%;
	}

	.bwt-update-row {
		display: grid;
		grid-template-columns: minmax(0, 1fr) minmax(180px, 260px) auto;
		gap: 1.25rem;
		align-items: center;
		padding: 1.1rem 1.25rem;
		background: var(--bwt-surface);
		border: 1px solid var(--bwt-border);
		border-radius: var(--bwt-radius);
		box-shadow: var(--bwt-shadow-sm);
	}

	.bwt-update-row__title {
		display: inline-block;
		font-size: 1.05rem;
		font-weight: 700;
		color: var(--bwt-text);
		text-decoration: none;
	}

	.bwt-update-row__meta,
	.bwt-update-row__changelog {
		margin: 0.25rem 0 0;
		color: var(--bwt-muted);
		font-size: 0.9rem;
		line-height: 1.45;
	}

	.bwt-update-row__changelog {
		color: var(--bwt-text);
	}

	.bwt-update-row__versions {
		display: flex;
		flex-direction: column;
		gap: 0.35rem;
		min-width: 0;
	}

	.bwt-update-row__label {
		color: var(--bwt-muted);
		font-size: 0.75rem;
		font-weight: 600;
		text-transform: uppercase;
	}

	.bwt-update-select {
		min-width: 180px;
	}

	@media (max-width: 900px) {
		.bwt-update-row {
			grid-template-columns: 1fr;
			align-items: stretch;
		}

		.bwt-update-row__action .uk-button {
			width: 100%;
		}
	}
</style>
