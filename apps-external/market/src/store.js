import Vue from "vue";
import Vuex from "vuex";
import Axios from "axios";
import _ from "underscore";

// Modified by BW-Tech GmbH for owncloud.online (PHP 8.4).

Vue.use(Vuex);

const state = {

    config: {},

    categories: {
        loading: false,
        failed: false,
        records: {}
    },

    applications: {
        loading: false,
        failed: false,
        records: {}
    },

    localApps: {
        loading: false,
        failed: false,
        records: []
    },

    bundles: {
        loading: false,
        failed: false,
        records: {}
    },

    apikey : {
        key : null,
        loading: false,
        valid : undefined,
        changeable : false
    },

    // Live search query, applied to name + summary + description + categories
    searchQuery: "",

    processing: [],
    installed: []
};

// Apply the search filter to a list of applications.
// Match only against id, name and summary so short queries like "log" do not
// drag in every app that mentions "log" inside its long description.
function matchesSearch (application, query) {
    if (!query) {
        return true;
    }
    const needle = String(query).trim().toLowerCase();
    if (!needle) {
        return true;
    }
    const id = String(application.id || "").toLowerCase();
    const name = String(application.name || "").toLowerCase();
    const summary = String(application.summary || "").toLowerCase();
    if (id.indexOf(needle) !== -1 || name.indexOf(needle) !== -1) {
        return true;
    }
    if (needle.length >= 4 && summary.indexOf(needle) !== -1) {
        return true;
    }
    return false;
}

// Retrieve computed values from state.
const getters = {

	config (state) {
		return state.config;
	},

    categories (state) {
        return state.categories.records;
    },

    category: (state) => (id) => {
        return _.find(state.categories.records, function (category) {
            return category.id == id;
        });
    },

    searchQuery (state) {
        return state.searchQuery;
    },

    applications: (state) => (category) => {
        const all = _.values(state.applications.records);
        const filtered = all.filter(function (application) {
            if (!matchesSearch(application, state.searchQuery)) {
                return false;
            }
            if (category === undefined) {
                return true;
            }
            return _.contains(application.categories, category);
        });
        return filtered;
    },

    installedApplications (state) {
        return _.filter(state.applications.records, "installed");
    },

    localApplications (state) {
        return state.localApps.records.filter(function (application) {
            return matchesSearch(application, state.searchQuery);
        });
    },

    applicationsByLicense: (state) => (license) => {
        return _.filter(state.applications.records, function (application) {
            if (application.release) {
                return application.release.license === license;
            }
        });
    },

    bundles: (state) => {
        return state.bundles.records;
    },

    application: (state) => (id) => {
        return _.find(state.applications.records, function (application) {
            return application.id == id;
        });
    },

    updateList: (state) => {
        return _.filter(state.applications.records, function (application) {
            return application.updateInfo === true;
        });
    },

    apikey (state) {
        return state.apikey;
    }
};

// Manipulate from the current state.
const mutations = {

	CONFIG (state, changes) {
		state["config"] = changes;
	},

    LOADING_APPLICATIONS (state) {
        _.extend(state["applications"], {
            loading: true,
            failed: false
        })
    },

    FAILED_APPLICATIONS (state) {
        _.extend(state["applications"], {
            loading: false,
            failed: true,
            records: {}
        })
    },

    FINISH_APPLICATIONS (state) {
        _.extend(state["applications"], {
            loading: false,
            failed: false
        })
    },

    SET_APPLICATIONS (state, content) {
        _.extend(state["applications"], {
            records: content
        })
    },

    LOADING_LOCAL_APPS (state) {
        _.extend(state["localApps"], {
            loading: true,
            failed: false
        })
    },

    FAILED_LOCAL_APPS (state) {
        _.extend(state["localApps"], {
            loading: false,
            failed: true,
            records: []
        })
    },

    FINISH_LOCAL_APPS (state) {
        _.extend(state["localApps"], {
            loading: false,
            failed: false
        })
    },

    SET_LOCAL_APPS (state, content) {
        _.extend(state["localApps"], {
            records: content
        })
    },

    SET_BUNDLES (state, content) {
        _.extend(state["bundles"], {
            records: content
        })
    },

	LOADING_BUNDLES (state) {
		_.extend(state["bundles"], {
			loading: true,
			failed: false
		})
	},

	FINISH_BUNDLES (state) {
		_.extend(state["bundles"], {
			loading: false,
			failed: false
		})
	},

	FAILED_BUNDLES (state) {
		_.extend(state["bundles"], {
			loading: false,
			failed: true,
            record: {}
		})
	},

    LOADING_CATEGORIES (state) {
        _.extend(state["categories"], {
            loading: true,
            failed: false,
            records: {}
        })
    },

    FAILED_CATEGORIES (state) {
        _.extend(state["categories"], {
            loading: false,
            failed: true,
            records: {}
        })
    },

    FINISH_CATEGORIES (state) {
        _.extend(state["categories"], {
            loading: false,
            failed: false
        })
    },

    SET_CATEGORIES (state, content) {
        _.extend(state["categories"], {
            records: content
        })
    },

    SET_SEARCH_QUERY (state, query) {
        state.searchQuery = query || "";
    },

    START_PROCESSING (state, id) {
        state["processing"].push(id)
    },

    FINISH_PROCESSING (state, id) {
        state["processing"] = _.without(state["processing"], id);
    },

    SET_APPLICATION_INSTALLED (state, id) {
        state["installed"].push(id)
    },

    APIKEY (state, changes) {
        _.extend(state["apikey"], changes);
    },
};

// Request content from the remote API.
const actions = {
    INVALIDATE_CACHE (context, options) {
        const silent = options && options.silent === true;

        return Axios.post(OC.generateUrl("/apps/market/cache/invalidate"),
            {}, {
                headers: {
                    requesttoken: OC.requestToken
                }
            }
        ).then((response) => {
            if (!silent) {
                UIkit.notification(response.data.message, {
                    status: "success",
                    pos: "bottom-right"
                });
            }

            return Promise.all([
                context.dispatch("FETCH_APPLICATIONS"),
                context.dispatch("FETCH_LOCAL_APPS")
            ]);

        }).catch((error) => {
            const response = error && error.response ? error.response : {};
            const data = response.data || {};

            if (!silent) {
                UIkit.notification(data.message || "Could not refresh the market cache.", {status:"danger", pos: "bottom-right"});
            }

            return Promise.reject(error);
        })
    },

    REFRESH_MARKET (context) {
        return context.dispatch("INVALIDATE_CACHE", {silent: true}).catch(() => {
            return Promise.all([
                context.dispatch("FETCH_APPLICATIONS"),
                context.dispatch("FETCH_LOCAL_APPS")
            ]);
        });
    },

    UPDATE_SEARCH (context, query) {
        context.commit("SET_SEARCH_QUERY", query);
    },

    PROCESS_APPLICATION (context, payload) {
        let id           = payload[0];
        let route        = payload[1];
        let options      = (payload[2]) ? payload[2] : false;

        context.commit("START_PROCESSING", id);

        let data = (options.version) ? {'toVersion' : options.version} : {};

        return Axios.post(OC.generateUrl("/apps/market/apps/{id}/" + route, {id}),
            data, {
                headers: {
                    requesttoken: OC.requestToken
                }
            }
        ).then((response) => {
			if (!options.suppressRefetch) {
				context.dispatch("REFRESH_MARKET");
			}

			if (!options.suppressNotifications) {
				UIkit.notification(response.data.message, {
					status: "success",
					pos: "bottom-right"
				});
			}

			context.commit("FINISH_PROCESSING", id);
			context.commit("SET_APPLICATION_INSTALLED", id);

        }).catch((error) => {
            if (!options.suppressNotifications) {
                UIkit.notification(error.response.data.message, {
                    status:"danger",
                    pos: "bottom-right"
                });
			}

			context.commit("FINISH_PROCESSING", id);
			return Promise.reject(error.response);
        })
    },

    FETCH_APPLICATIONS (context) {
        context.commit("LOADING_APPLICATIONS");

        return Axios.get(OC.generateUrl("/apps/market/apps"))
            .then((response) => {
                context.commit("SET_APPLICATIONS", response.data);
                context.commit("FINISH_APPLICATIONS")
            })
            .catch((error) => {
                const response = error && error.response ? error.response : {};
                const data = response.data || {};
                UIkit.notification(data.message || "Could not load apps from the market.", {status:"danger", pos: "bottom-right"});
                context.commit("FAILED_APPLICATIONS");
                return Promise.reject(error);
            });
    },

    FETCH_LOCAL_APPS (context) {
        context.commit("LOADING_LOCAL_APPS");

        return Promise.all([
            Axios.get(OC.generateUrl("/apps/market/installed-apps/enabled")),
            Axios.get(OC.generateUrl("/apps/market/installed-apps/disabled"))
        ]).then((responses) => {
            const records = {};
            responses.forEach((response) => {
                const apps = Array.isArray(response.data) ? response.data : [];
                apps.forEach((app) => {
                    if (app && app.id) {
                        records[app.id] = app;
                    }
                });
            });

            const apps = _.values(records).sort(function (first, second) {
                return String(first.name || first.id).localeCompare(String(second.name || second.id));
            });

            context.commit("SET_LOCAL_APPS", apps);
            context.commit("FINISH_LOCAL_APPS");
        }).catch((error) => {
            const response = error && error.response ? error.response : {};
            const data = response.data || {};
            UIkit.notification(data.message || "Could not load installed apps.", {status:"danger", pos: "bottom-right"});
            context.commit("FAILED_LOCAL_APPS");
        });
    },

    REQUEST_LICENSE_KEY (context) {
        return Axios.get(OC.generateUrl("/apps/market/request-license-key-from-market"))
            .then((response) => {
                context.dispatch('FETCH_CONFIG');
                UIkit.notification(response.data.message, {
                    status : "success",
                    pos    : "bottom-right"
                });
            })
            .catch((error) => {
                UIkit.notification(error.response.data.message, {
                    status : "danger",
                    pos    : "bottom-right"
                });

				return Promise.reject(error.response);
			});
    },

    INSTALL_BUNDLE (context, payload) {

        let count = payload.length;

        let install = (i) => {

            if (payload[i]) {
                context.dispatch('PROCESS_APPLICATION', [payload[i].id, 'install', { suppressNotifications: true, suppressRefetch: true }])
                .then( () => {
					console.info( payload[i].id + ' installed successfully.')
				})
                .catch( () => {
					console.warn( payload[i].id + ' installation failed.')
				})
                .then( () => {
					install(++i);
                });
            }
            else {
				context.dispatch('REFRESH_MARKET');
            }
        };

        install(0);
    },

    FETCH_BUNDLES (context) {
        context.commit("LOADING_BUNDLES");

        Axios.get(OC.generateUrl("/apps/market/bundles"))
            .then((response) => {
                context.commit("SET_BUNDLES", response.data);
                context.commit("FINISH_BUNDLES")
            })
            .catch((error) => {
                UIkit.notification(error.response.data.message, {status:"danger", pos: "bottom-right"});
                context.commit("FAILED_BUNDLES")
            });
    },

    FETCH_CATEGORIES (context) {
        context.commit("LOADING_CATEGORIES")

        Axios.get(OC.generateUrl("/apps/market/categories"))
            .then((response) => {
                context.commit("SET_CATEGORIES", response.data);
                context.commit("FINISH_CATEGORIES")
            })
            .catch((error) => {
                UIkit.notification(error.response.data.message, {status:"danger", pos: "bottom-right"});
                context.commit("FAILED_CATEGORIES")
            });
    },

    FETCH_APIKEY (context, callback) {
        context.commit("APIKEY", {"loading": true });
        Axios.get(OC.generateUrl("/apps/market/apikey"))
            .then((response) => {
                context.commit("APIKEY", {
                    "key"        : response.data.apiKey,
                    "changeable" : response.data.changeable,
                    "loading"    : false,
                    "processing" : false
				});

				if (typeof callback === 'function')
					callback(response.data);
            })
            .catch((error) => {
				context.commit("APIKEY", {"loading": false });

				if (typeof callback === 'function')
					callback(error);
            });
    },

    FETCH_CONFIG (context) {
        Axios.get(OC.generateUrl("/apps/market/config"))
            .then((response) => {
                context.commit("CONFIG", response.data);
            })
            .catch((error) => {
                UIkit.notification(error.response.data.message, {
                    status:"danger",
                    pos: "bottom-right"
                });
            });
    },

    WRITE_APIKEY (context, payload) {
        let key = payload;
        context.commit("APIKEY", {"loading": true });
        Axios.put(OC.generateUrl("/apps/market/apikey"),
            {
                "apiKey" : key
            }, {
                headers: {
                    requesttoken: OC.requestToken
                }
            }
        ).then((response) => {
            if (response.data.message == "The api key is not valid.") {
                context.commit("APIKEY", {
                    "loading": false,
                    "valid"  : false
                });
            }
            else {
                context.commit("APIKEY", {"valid" : true });
                context.dispatch("FETCH_APIKEY");
                context.dispatch("REFRESH_MARKET");
                context.dispatch("FETCH_CATEGORIES");
                context.dispatch("FETCH_BUNDLES");
            }
        }).catch((error) => {
            context.commit("APIKEY", {"loading" : false });
        })
    },

    REBUILD_NAVIGATION() {
        Axios.get(OC.filePath('settings', 'ajax', 'navigationdetect.php'),
            {
                headers: {
                    requesttoken: OC.requestToken
                }
            }
        ).then((response) => {

            let navEntries   = response.data.nav_entries;
            let $container   = $('#apps ul').html("");
            let $iconLoading = $('<div>', { "class" : "icon-loading-dark" });

            _.each(navEntries, function (e) {
                let $li   = $('<li>',   { "data-id" : e.id }),
                    $link = $('<a>',    { "href" : e.href }),
                    $icon = $('<img>',  { "class" : "app-icon", "src" : e.icon }),
                    $name = $('<span>', { "text" : e.name });

                $link
                    .append($icon, $iconLoading.clone().hide(), $name);

                $li
                    .append($link)
                    .appendTo($container);

                if (!OC.Util.hasSVGSupport() && e.icon.match(/\.svg$/i)) {
                    $icon.addClass('svg');
                    OC.Util.replaceSVG();
                }
            });
        })
    }
};

export default new Vuex.Store({
    state,
    getters,
    mutations,
    actions
})
