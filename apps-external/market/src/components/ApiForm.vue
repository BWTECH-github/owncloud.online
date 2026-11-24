<template lang="pug">
	li
		a(href="#", @click.prevent="open") {{ t('Support contract') }}

		#support-contract(uk-modal='center: true')
			.uk-modal-dialog
				button.uk-modal-close-default(type='button', uk-close='', :aria-label="t('Close')")
				.uk-modal-header
					h2.uk-modal-title {{ t('Support contract') }}
				.uk-modal-body
					template(v-if="changeable")
						label.bwt-contract__label(for="support-contract-key") {{ t('Support contract key') }}
						input#support-contract-key.uk-input.-monospace(
							v-model="newKey",
							:class="{ 'uk-form-danger': rejected }",
							:aria-invalid="rejected ? 'true' : 'false'",
							autocomplete="off",
							spellcheck="false",
							@keydown.enter.prevent="save"
						)
					p.uk-margin-remove(v-else) {{ t('The key is set in config.php.') }}

					.bwt-contract(v-if="status", role="status", aria-live="polite")
						p.bwt-contract__state(:class="'bwt-contract__state--' + tone")
							span.bwt-contract__dot(aria-hidden="true")
							span {{ status.label || t('Status unknown') }}
						p.bwt-contract__message(v-if="status.message || status.failed") {{ status.message || t('Could not check the status.') }}
						dl.bwt-contract__rows(v-if="status.rows && status.rows.length")
							.bwt-contract__row(v-for="row in status.rows", :key="row.label")
								dt {{ row.label }}
								dd {{ row.value }}
						a.bwt-contract__request(v-if="offerRequest", :href="mailto") {{ t('Request a support contract') }}

				.uk-modal-footer.bwt-contract__footer
					button.uk-button.uk-button-default(type='button', @click="check", :disabled="!canCheck")
						span.uk-margin-small-right(v-if="checking", uk-spinner="ratio: 0.6", aria-hidden="true")
						| {{ t('Check status') }}
					button.uk-button.uk-button-default.uk-modal-close(type='button') {{ t('Close') }}
					button.uk-button.uk-button-primary(v-if="changeable", type='button', @click="save", :disabled="loading || !dirty")
						| {{ loading ? t('saving') : t('Save') }}
</template>

<script>

	// Modified by BW-Tech GmbH for owncloud.online (PHP 8.4).

	import Mixins from '../mixins.js'

	// Farbe des Zustandspunkts; der Zustand steht immer auch als Text daneben.
	const TONES = {
		active: 'ok',
		expiring: 'warn',
		pending: 'warn',
		blocked: 'warn',
		update: 'warn',
		expired: 'bad',
		invalid: 'bad',
		system_blocked: 'bad'
	};

	export default {
		mixins: [Mixins],
		data () {
			return {
				newKey : ''
			}
		},
		mounted () {
			this.$store.dispatch('FETCH_APIKEY');
		},
		watch: {
			storedKey: {
				immediate: true,
				handler (value) {
					this.newKey = value;
				}
			}
		},
		methods : {
			open () {
				UIkit.modal('#support-contract').show();
				this.$store.dispatch('FETCH_SUPPORT');
			},
			save () {
				if (!this.dirty || this.loading) {
					return;
				}
				this.$store.dispatch('WRITE_APIKEY', this.newKey.trim());
			},
			check () {
				this.$store.dispatch('FETCH_KEY_STATUS');
			}
		},
		computed : {
			storedKey () {
				return this.$store.state.apikey.key || '';
			},
			changeable () {
				return this.$store.state.apikey.changeable;
			},
			hasKey () {
				return this.$store.state.apikey.hasKey;
			},
			loading () {
				return this.$store.state.apikey.loading;
			},
			dirty () {
				return this.changeable && this.newKey.trim() !== this.storedKey;
			},
			// Der zuletzt abgelehnte Schlüssel steht noch im Feld.
			rejected () {
				return this.$store.state.apikey.valid === false && this.dirty;
			},
			status () {
				return this.$store.state.keyStatus.data;
			},
			checking () {
				return this.$store.state.keyStatus.loading;
			},
			// Geprüft wird der gespeicherte Schlüssel, nicht der im Feld.
			canCheck () {
				return this.hasKey && !this.dirty && !this.checking;
			},
			tone () {
				return TONES[this.status && this.status.state] || 'neutral';
			},
			offerRequest () {
				return !!this.status && ['none', 'invalid', 'expired'].indexOf(this.status.state) !== -1;
			},
			mailto () {
				return this.supportMailto(this.$store.state.support, this.t('Support contract'));
			}
		}
	}
</script>

<style lang="scss" scoped>
	.-monospace {
		font-family: "Lucida Console", "Lucida Sans Typewriter", "DejaVu Sans Mono", monospace;
	}

	// Der Dialog hängt am body, außerhalb von #market-app: Farben deshalb
	// hier setzen, nicht über die Regeln der Hülle.
	.bwt-contract__label {
		display: block;
		margin-bottom: 0.4rem;
		color: var(--bwt-text);
		font-weight: 500;
	}

	.bwt-contract {
		margin-top: 1.25rem;
		padding-top: 1rem;
		border-top: 1px solid var(--bwt-border);
	}

	.bwt-contract__state {
		display: flex;
		align-items: center;
		gap: 0.5rem;
		margin: 0;
		font-weight: 600;
		color: var(--bwt-text);
	}

	.bwt-contract__dot {
		flex: none;
		width: 0.6rem;
		height: 0.6rem;
		border-radius: var(--bwt-radius-pill);
		background: var(--bwt-muted);
	}

	.bwt-contract__state--ok .bwt-contract__dot { background: var(--bwt-success); }
	.bwt-contract__state--warn .bwt-contract__dot { background: var(--bwt-warning); }
	.bwt-contract__state--bad .bwt-contract__dot { background: var(--bwt-danger); }

	.bwt-contract__message {
		margin: 0.35rem 0 0;
		color: var(--bwt-text);
	}

	.bwt-contract__rows {
		margin: 0.85rem 0 0;
	}

	.bwt-contract__row {
		display: flex;
		justify-content: space-between;
		gap: 1rem;
		padding: 0.4rem 0;
		border-top: 1px solid var(--bwt-border-soft);

		dt {
			color: var(--bwt-muted);
			font-weight: 400;
		}

		dd {
			margin: 0;
			text-align: right;
			overflow-wrap: anywhere;
		}
	}

	.bwt-contract__request {
		display: inline-block;
		margin-top: 0.85rem;
	}

	.bwt-contract__footer {
		display: flex;
		flex-wrap: wrap;
		justify-content: flex-end;
		gap: 0.5rem;
	}
</style>
