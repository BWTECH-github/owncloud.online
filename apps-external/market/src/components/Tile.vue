<template lang="pug">
	transition(name="fade")
		li(v-if="application").bwt-app-grid__item.uk-animation-slide-top-small
			.uk-card.uk-card-default.bwt-tile
				//- WCAG 2.4.4/4.1.2: Voll-Karten-Overlay-Link aus dem Tab-Flow genommen;
				//- der Titel-Link bleibt der einzige zugaengliche Einstieg.
				router-link.bwt-tile__overlay(
					:to="{ name: 'details', params: { id: application.id }}",
					tabindex="-1",
					aria-hidden="true"
				)
				span.bwt-tile__icon(aria-hidden="true")
					span.bwt-tile__icon-fallback {{ initial }}
					img(v-if="iconUrl", :src="iconUrl", alt="", loading="lazy")
				.bwt-tile__content
					.bwt-tile__labels
						span.bwt-tile__label(v-if="primaryCategory") {{ primaryCategoryLabel }}
						span.bwt-tile__label.bwt-tile__label--installed(v-if="application.installed && !application.updateInfo") {{ t('Installed') }}
						span.bwt-tile__label.bwt-tile__label--update(v-if="application.updateInfo") {{ t('Update') }}

					h3.bwt-tile__title
						router-link(:to="{ name: 'details', params: { id: application.id }}") {{ application.name }}

					p.bwt-tile__summary(v-if="application.summary || application.description") {{ truncatedSummary }}

					.bwt-tile__footer
						rating(v-if="application.rating", :rating="application.rating")
						span(v-else) &nbsp;
						strong.bwt-tile__details {{ t('Details') }} →
</template>

<script>
	import Rating from './Rating.vue';
	import Mixins from '../mixins';

	export default {
		mixins: [Mixins],
		components: {
			Rating
		},
		props: [
			'application'
		],
		computed: {
			iconUrl () {
				// Small marketplace app icon (shown in the compact list, storefront style)
				return (this.application && this.application.icon) ? this.application.icon : null;
			},
			initial () {
				// Up to two initials (storefront style)
				const source = String((this.application && (this.application.name || this.application.id)) || '').trim();
				const ini = source.split(/\s+/).slice(0, 2).map(w => w.charAt(0)).join('').toUpperCase();
				return ini || '?';
			},
			primaryCategory () {
				const cats = this.application && this.application.categories;
				return Array.isArray(cats) && cats.length ? cats[0] : '';
			},
			primaryCategoryLabel () {
				const category = this.$store.getters.category(this.primaryCategory) || { id: this.primaryCategory };
				return this.categoryLabel(category);
			},
			truncatedSummary () {
				const text = this.application.summary || this.application.description || '';
				const stripped = String(text).replace(/[#*_`]/g, '').trim();
				return stripped.length > 130 ? stripped.slice(0, 127) + '...' : stripped;
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
				return String(label).replace(/[-_]+/g, ' ');
			}
		}
	}
</script>

<style lang="scss" scoped>
	@import "../styles/variables-theme";

	.bwt-tile__label {
		text-transform: capitalize;
	}
</style>
