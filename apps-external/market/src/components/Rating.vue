<template lang="pug">
	ul.uk-padding-remove.uk-margin-remove.uk-inline-block(
		uk-tooltip,
		:title="ratingLabel",
		role="img",
		:aria-label="ratingLabel"
	)
		li(v-for="n in stars", aria-hidden="true").uk-inline-block
			span(:class="(n <= overall) ? '-on' : '-off'", uk-icon="icon: star; ratio: 0.8").star
</template>

<script>
	import Mixins from '../mixins';

	export default {
		mixins: [Mixins],
		props: [
			'rating'
		],
		data () {
			return {
				classOn: "on",
				classOff: "off",
				// Lokale Apps liefern kein rating-Objekt — auf 0 zurückfallen,
				// statt an rating.mean von undefined zu scheitern.
				overall: (this.rating && this.rating.mean) || 0,
				stars: 5
			}
		},
		computed: {
			// WCAG 1.1.1 / 4.1.2: Die Bewertung steckte nur im Tooltip eines nicht
			// fokussierbaren <ul>. Eine echte Textalternative (role=img + aria-label)
			// macht den Wert fuer Screenreader/Tastatur zugaenglich. Die fruehere,
			// kaputte Entity "&Oslash " (ohne Semikolon) entfaellt.
			ratingLabel () {
				const value = Math.round(this.overall * 100) / 100;
				return this.t('Rated %{n} of 5 stars', { n: value });
			}
		}
	}
</script>

<style lang="scss" scoped>
	@import "../styles/variables-theme";

	.star {
		margin-right: 3px;

		&.-on {
			color: $global-link-color;
		}

		&.-off {
			opacity: 0.25;
		}
	}
</style>
