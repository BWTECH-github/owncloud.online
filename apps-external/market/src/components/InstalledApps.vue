<template lang="pug">
	div
		.uk-position-fixed.uk-position-center(v-show="loading", uk-spinner, uk-icon="icon: spinner")
		.uk-card.uk-card-default.bwt-installed-card(v-if="!failed").uk-animation-slide-top-small
			.uk-card-header
				div(uk-grid)
					.uk-width-expand
						h2.bwt-page-head__title.uk-margin-remove-bottom {{ t('Installed Apps') }}
						p.uk-text-meta.uk-margin-small-top {{ t('Apps currently present in this owncloud.online instance.') }}
					.uk-width-auto
						.bwt-segmented
							button.bwt-segmented__item(:class="{ 'is-active': filter === 'all' }", @click="filter = 'all'") {{ t('All') }}
							button.bwt-segmented__item(:class="{ 'is-active': filter === 'enabled' }", @click="filter = 'enabled'") {{ t('Enabled') }}
							button.bwt-segmented__item(:class="{ 'is-active': filter === 'disabled' }", @click="filter = 'disabled'") {{ t('Disabled') }}

			.uk-card-body
				.bwt-installed-table-wrap(v-if="applications.length")
					table.uk-table.uk-table-hover.uk-table-divider.uk-table-middle.bwt-installed-table
						thead
							tr
								th {{ t('App') }}
								th {{ t('Version') }}
								th {{ t('Author') }}
								th {{ t('State') }}
								th {{ t('Compatibility') }}
								th &nbsp;
						tbody
							tr(v-for="application in applications", :key="application.id")
								td
									router-link.bwt-installed-app-link(:to="{ name: 'details', params: { id: application.id }}")
										strong {{ appName(application) }}
									div.uk-text-meta {{ application.id }}
								td {{ application.version || '-' }}
								td {{ author(application) }}
								td
									span.bwt-badge.bwt-badge--installed(v-if="application.active") {{ t('Enabled') }}
									span.bwt-badge.bwt-badge--disabled(v-else) {{ t('Disabled') }}
								td
									span(v-if="application.canInstall") {{ t('OK') }}
									span.uk-text-danger(v-else) {{ t('Missing dependencies') }}
									ul.uk-list.uk-list-collapse.uk-margin-small-top(v-if="application.missingDependencies && application.missingDependencies.length")
										li(v-for="dependency in application.missingDependencies") {{ dependency }}
								td.uk-text-right
									router-link.uk-button.uk-button-small.uk-button-secondary(
										v-if="catalogApplication(application.id)",
										:to="{ name: 'details', params: { id: application.id }}"
									) {{ t('Details') }}
									span.uk-text-meta(v-else) {{ t('Local only') }}

				.bwt-empty.uk-card-body(v-else-if="!loading")
					p.uk-text-center(v-if="searchQuery") {{ t('No installed apps match "%{query}"', { query: searchQuery }) }}
					p.uk-text-center(v-else) {{ t('No installed apps found') }}
</template>

<script>
	// Modified by BW-Tech GmbH for owncloud.online (PHP 8.4).

	import Mixins from '../mixins.js'

	export default {
		mixins: [Mixins],
		data () {
			return {
				filter: 'all'
			}
		},
		mounted () {
			this.$store.dispatch('FETCH_LOCAL_APPS')
		},
		methods: {
			appName (application) {
				return application.name || application.id
			},
			author (application) {
				if (Array.isArray(application.author)) {
					return application.author.join(', ')
				}
				return application.author || '-'
			},
			catalogApplication (id) {
				return this.$store.getters.application(id)
			}
		},
		computed: {
			loading () {
				return this.$store.state.localApps.loading
			},
			failed () {
				return this.$store.state.localApps.failed
			},
			searchQuery () {
				return this.$store.getters.searchQuery
			},
			applications () {
				const apps = this.$store.getters.localApplications
				if (this.filter === 'enabled') {
					return apps.filter((application) => application.active)
				}
				if (this.filter === 'disabled') {
					return apps.filter((application) => !application.active)
				}
				return apps
			}
		}
	}
</script>

<style lang="scss" scoped>
	@import "../styles/variables-theme";

	// Installierte App per Namensklick zur Detailseite (analog verfuegbare Apps).
	.bwt-installed-app-link {
		color: inherit;
		text-decoration: none;

		&:hover strong,
		&:focus strong {
			text-decoration: underline;
		}
	}

	// AA-Kontrast: UIkit-Standardrot (#f0506e) erreicht nur 3,46:1 auf Weiss.
	// Dunkleres Rot erzwingen (>=4,5:1 fuer Normaltext).
	.uk-text-danger {
		color: var(--bwt-danger) !important;
	}

	.uk-table td {
		vertical-align: top;
	}

	.bwt-installed-card {
		width: 100%;
		overflow: hidden;
	}

	.bwt-installed-table-wrap {
		width: 100%;
		overflow-x: auto;
		background: var(--bwt-surface);
	}

	.bwt-installed-table {
		width: 100%;
		min-width: 760px;
		table-layout: fixed;

		th,
		td {
			white-space: normal;
			word-break: break-word;
		}

		th:nth-child(1),
		td:nth-child(1) {
			width: 24%;
		}

		th:nth-child(2),
		td:nth-child(2) {
			width: 12%;
		}

		th:nth-child(3),
		td:nth-child(3) {
			width: 22%;
		}

		th:nth-child(4),
		td:nth-child(4) {
			width: 12%;
		}

		th:nth-child(5),
		td:nth-child(5) {
			width: 20%;
		}

		th:nth-child(6),
		td:nth-child(6) {
			width: 10%;
		}
	}

	.bwt-segmented {
		display: inline-flex;
		padding: 0.2rem;
		background: var(--bwt-bg);
		border: 1px solid var(--bwt-border);
		border-radius: var(--bwt-radius-pill);
		gap: 0.15rem;
	}

	.bwt-segmented__item {
		appearance: none;
		border: 0;
		background: transparent;
		// WCAG 1.4.3: --bwt-muted (#64748b) auf --bwt-bg (#f6f7fb) ergibt nur 4,45:1.
		// Slate-600 (#475569) hebt den inaktiven Tab-Text auf >=4,5:1 an.
		color: #475569;
		padding: 0.35rem 0.85rem;
		font-size: 0.85rem;
		font-weight: 500;
		border-radius: var(--bwt-radius-pill);
		cursor: pointer;
		transition: background-color var(--bwt-transition), color var(--bwt-transition);

		&:hover {
			color: var(--bwt-text);
		}

		&.is-active {
			background: var(--bwt-surface);
			color: var(--bwt-text);
			box-shadow: var(--bwt-shadow-sm);
		}
	}
</style>
