import "core-js/stable";
require('./styles/theme.scss');

// -------------------------------------------------------- Uikit components ---
import UIkit from 'uikit';
import Icons from 'uikit/dist/js/uikit-icons';

// Modified by BW-Tech GmbH for owncloud.online (PHP 8.4).

UIkit.use(Icons);

// ------------------------------------------------------------- Vue plugins ---

import Vue       from 'vue'
import VueRouter from 'vue-router';

Vue.use(VueRouter);

import GetTextPlugin from 'vue-gettext'
import translations from '../l10n/translations.json'

Vue.use(GetTextPlugin, {translations: translations})
Vue.config.language = OC.getLocale()

// TODO: Write plugin for global t() method

// --------------------------------------------------------------- Vue setup ---

import App         from './App.vue'
import Details     from './components/Details.vue'
import List        from './components/List.vue'
import BundlesList from './components/BundlesList.vue'
import UpdateList  from './components/UpdateList.vue'
import InstalledApps from './components/InstalledApps.vue'

// Store
import store from './store'

// Routing
const routes = [
    {
        path: '/app/:id',
        component: Details,
        name: 'details'
    }, {
        path: '/by/category/:category',
        component: List,
        name: 'byCategory'
    }, {
        path: '/installed',
        component: InstalledApps,
        name: 'InstalledApps'
    }, {
        path: '/bundles',
        component: BundlesList,
        name: 'Bundles'
    }, {
        path: '/updates',
        component: UpdateList,
        name: 'UpdateList'
    }, {
        path: '/',
        component: List,
        name: 'index'
    }
];

const router = new VueRouter({
    routes
});

// The App itself
const MarketApp = new Vue({
    router,
    store,
    render: h => h(App)
});

// --------------------------------------------------------------- Vue mount ---

// Need to wait for window to load
window.onload = () => MarketApp.$mount('.app-market');
