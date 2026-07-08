import showdown from "showdown";

// Elemente, die aus Marketplace-Markdown (description/changelog) nie in den
// DOM gelangen dürfen — showdown reicht rohes HTML ungefiltert durch.
const FORBIDDEN_TAGS = [
	"script", "style", "iframe", "frame", "frameset", "object", "embed",
	"applet", "form", "input", "button", "textarea", "select", "option",
	"link", "meta", "base"
];

// Attribute mit URL-Wert, die javascript:/vbscript:/data:-Payloads tragen können.
const URL_ATTRIBUTES = ["href", "src", "xlink:href", "action", "formaction", "srcdoc"];

// Führende Whitespaces/Steuerzeichen abfangen (Bypass wie "\tjavascript:").
const UNSAFE_URL_PATTERN = /^[\s\x00-\x1F]*(javascript|vbscript|data)\s*:/i;

// Browser entfernen Tab/CR/LF/NUL beim URL-Parsen AUCH mitten im Schema
// ("jav\tascript:" wird ausgeführt) — vor dem Pattern-Test daher eine um
// alle Steuerzeichen bereinigte Kopie des Werts prüfen.
function isUnsafeUrl (value) {
	return UNSAFE_URL_PATTERN.test(String(value).replace(/[\x00-\x20]/g, ""));
}

// Minimaler Sanitizer ohne neue Dependency: parst in ein detached Document
// (dort laden keine Ressourcen und feuern keine Event-Handler), entfernt
// gefährliche Tags, alle on*-Attribute und unsichere URL-Schemata.
function sanitizeHtml (html) {
	const doc = document.implementation.createHTMLDocument("");
	doc.body.innerHTML = html;

	FORBIDDEN_TAGS.forEach(function (tag) {
		const nodes = doc.body.querySelectorAll(tag);
		for (let i = nodes.length - 1; i >= 0; i--) {
			nodes[i].parentNode.removeChild(nodes[i]);
		}
	});

	const elements = doc.body.querySelectorAll("*");
	for (let i = 0; i < elements.length; i++) {
		const element = elements[i];
		const attributes = element.attributes;
		// Rückwärts iterieren: attributes ist eine Live-Collection.
		for (let j = attributes.length - 1; j >= 0; j--) {
			const name = attributes[j].name.toLowerCase();
			if (name.indexOf("on") === 0) {
				element.removeAttribute(attributes[j].name);
			}
			else if (URL_ATTRIBUTES.indexOf(name) !== -1 && isUnsafeUrl(attributes[j].value)) {
				element.removeAttribute(attributes[j].name);
			}
		}
	}

	return doc.body.innerHTML;
}

export default {
	methods: {
		t (string, interpolation) {
			if (!interpolation) {
				return this.$gettext(string);
			}
			else {
				// %{interplate} with object
				return this.$gettextInterpolate(string, interpolation);
			}
		},
		markdown (string) {
			if (!string) {
				return "";
			}

			let converter = new showdown.Converter();
			let markdown  = converter.makeHtml(string);

			return sanitizeHtml(markdown);
		}
	}
}
