/**
 * Copyright (c) 2014 Vincent Petry <pvince81@owncloud.com>
 *
 * This file is licensed under the Affero General Public License version 3
 * or later.
 *
 * See the COPYING-README file.
 *
 * Modified by BW-Tech GmbH on 2026-11-24.
 * Changes:
 *   - compare the generated plural functions with the former eval version and
 *     cover invalid plural forms
 *
 */
describe('OC.L10N tests', function() {
	var TEST_APP = 'jsunittestapp';

	beforeEach(function() {
		OC.appswebroots[TEST_APP] = OC.webroot + '/apps3/jsunittestapp';
	});
	afterEach(function() {
		delete OC.L10N._bundles[TEST_APP];
		delete OC.appswebroots[TEST_APP];
	});

	describe('text translation', function() {
		beforeEach(function() {
			OC.L10N.register(TEST_APP, {
				'Hello world!': 'Hallo Welt!',
				'Hello {name}, the weather is {weather}': 'Hallo {name}, das Wetter ist {weather}',
				'sunny': 'sonnig'
			}, 'nplurals=2; plural=(n != 1);');
		});
		it('returns untranslated text when no bundle exists', function() {
			delete OC.L10N._bundles[TEST_APP];
			expect(t(TEST_APP, 'unknown text')).toEqual('unknown text');
		});
		it('returns untranslated text when no key exists', function() {
			expect(t(TEST_APP, 'unknown text')).toEqual('unknown text');
		});
		it('returns translated text when key exists', function() {
			expect(t(TEST_APP, 'Hello world!')).toEqual('Hallo Welt!');
		});
		it('returns translated text with placeholder', function() {
			expect(
				t(TEST_APP, 'Hello {name}, the weather is {weather}', {name: 'Steve', weather: t(TEST_APP, 'sunny')})
			).toEqual('Hallo Steve, das Wetter ist sonnig');
		});
		it('returns text with escaped placeholder', function() {
			expect(
				t(TEST_APP, 'Hello {name}', {name: '<strong>Steve</strong>'})
			).toEqual('Hello &lt;strong&gt;Steve&lt;/strong&gt;');
		});
		it('returns text with not escaped placeholder', function() {
			expect(
				t(TEST_APP, 'Hello {name}', {name: '<strong>Steve</strong>'}, null, {escape: false})
			).toEqual('Hello <strong>Steve</strong>');
		});
		it('keeps old texts when registering existing bundle', function() {
			OC.L10N.register(TEST_APP, {
				'sunny': 'sonnig',
				'new': 'neu'
			}, 'nplurals=2; plural=(n != 1);');
			expect(t(TEST_APP, 'sunny')).toEqual('sonnig');
			expect(t(TEST_APP, 'new')).toEqual('neu');
		});
	});
	describe('plurals', function() {
		var warnStub;

		beforeEach(function() {
			warnStub = sinon.stub(console, 'warn');
		});
		afterEach(function() { 
			warnStub.restore(); 
		});
	
		function checkPlurals() {
			expect(
				n(TEST_APP, 'download %n file', 'download %n files', 0)
			).toEqual('0 Dateien herunterladen');
			expect(
				n(TEST_APP, 'download %n file', 'download %n files', 1)
			).toEqual('1 Datei herunterladen');
			expect(
				n(TEST_APP, 'download %n file', 'download %n files', 2)
			).toEqual('2 Dateien herunterladen');
			expect(
				n(TEST_APP, 'download %n file', 'download %n files', 1024)
			).toEqual('1024 Dateien herunterladen');
		}

		it('generates plural for default text when translation does not exist', function() {
			OC.L10N.register(TEST_APP, {
			});
			expect(warnStub.called).toEqual(true);
			expect(
				n(TEST_APP, 'download %n file', 'download %n files', 0)
			).toEqual('download 0 files');
			expect(
				n(TEST_APP, 'download %n file', 'download %n files', 1)
			).toEqual('download 1 file');
			expect(
				n(TEST_APP, 'download %n file', 'download %n files', 2)
			).toEqual('download 2 files');
			expect(
				n(TEST_APP, 'download %n file', 'download %n files', 1024)
			).toEqual('download 1024 files');
		});
		it('generates plural with default function when no forms specified', function() {
			OC.L10N.register(TEST_APP, {
				'_download %n file_::_download %n files_':
					['%n Datei herunterladen', '%n Dateien herunterladen']
			});
			expect(warnStub.called).toEqual(true);
			checkPlurals();
		});
		it('generates plural with generated function when forms is specified', function() {
			OC.L10N.register(TEST_APP, {
				'_download %n file_::_download %n files_':
					['%n Datei herunterladen', '%n Dateien herunterladen']
			}, 'nplurals=2; plural=(n != 1);');
			expect(warnStub.notCalled).toEqual(true);
			checkPlurals();
		});
		it('generates plural with function when forms is specified as function', function() {
			OC.L10N.register(TEST_APP, {
				'_download %n file_::_download %n files_':
					['%n Datei herunterladen', '%n Dateien herunterladen']
			}, function(n) {
				return {
					nplurals: 2,
					plural: (n !== 1) ? 1 : 0
				};
			});
			expect(warnStub.notCalled).toEqual(true);
			checkPlurals();
		});
	});
	describe('plural function generation', function() {
		// Alle Pluralformen der l10n-Dateien von core, settings, apps und
		// apps-external (gesammelt am 24.11.2026), davor Beispielsprachen
		var PLURAL_FORMS = [
			// fa, id, id_ID, ja, jv, ka_GE, ...
			'nplurals=1; plural=0;',
			// ak
			'nplurals=2; plural=n > 1;',
			// ka_GE
			'nplurals=2; plural=(n!=1);',
			// ach, da, fa, fil, fr, kn, ...
			'nplurals=2; plural=(n > 1);',
			// ady, af_ZA, am_ET, ast, az, bg, ...
			'nplurals=2; plural=(n != 1);',
			// is
			'nplurals=2; plural=(n % 10 != 1 || n % 100 == 11);',
			// mk
			'nplurals=2; plural=(n % 10 == 1 && n % 100 != 11) ? 0 : 1;',
			// tzm
			'nplurals=2; plural=(n == 0 || n == 1 || (n > 10 && n < 100) ? 0 : 1);',
			// fil
			'nplurals=2; plural=(n == 1 || n==2 || n==3) || (n % 10 != 4 || n % 10 != 6 || n % 10 != 9);',
			// cs, cs_CZ, sk, sk_SK
			'nplurals=3; plural=(n==1) ? 0 : (n>=2 && n<=4) ? 1 : 2;',
			// lv
			'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n != 0 ? 1 : 2);',
			// es, es_419, es_AR, es_CL, es_EC, es_ES, ...
			'nplurals=3; plural=n == 1 ? 0 : n != 0 && n % 1000000 == 0 ? 1 : 2;',
			// ro
			'nplurals=3; plural=(n==1?0:(((n%100>19)||((n%100==0)&&(n!=0)))?2:1));',
			// he
			'nplurals=3; plural=(n == 1 && n % 1 == 0) ? 0 : (n == 2 && n % 1 == 0) ? 1: 2;',
			// fr, fr_FR, pt_BR, pt_PT
			'nplurals=3; plural=(n == 0 || n == 1) ? 0 : n != 0 && n % 1000000 == 0 ? 1 : 2;',
			// pl
			'nplurals=3; plural=(n==1 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
			// lt, lt_LT
			'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && (n%100<10 || n%100>=20) ? 1 : 2);',
			// hr
			'nplurals=3; plural=n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2;',
			// bs, hr, ru, sr, sr@latin, uk
			'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
			// cy_GB
			'nplurals=4; plural=(n==1) ? 0 : (n==2) ? 1 : (n != 8 && n != 11) ? 2 : 3;',
			// sl
			'nplurals=4; plural=(n%100==1 ? 0 : n%100==2 ? 1 : n%100==3 || n%100==4 ? 2 : 3);',
			// mt_MT
			'nplurals=4; plural=(n==1 ? 0 : n==0 || ( n%100>1 && n%100<11) ? 1 : (n%100>10 && n%100<20 ) ? 2 : 3);',
			// be
			'nplurals=4; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
			// sk, sk_SK
			'nplurals=4; plural=(n % 1 == 0 && n == 1 ? 0 : n % 1 == 0 && n >= 2 && n <= 4 ? 1 : n % 1 != 0 ? 2: 3);',
			// cs, cs_CZ
			'nplurals=4; plural=(n == 1 && n % 1 == 0) ? 0 : (n >= 2 && n <= 4 && n % 1 == 0) ? 1: (n % 1 != 0 ) ? 2 : 3;',
			// he
			'nplurals=4; plural=(n == 1 && n % 1 == 0) ? 0 : (n == 2 && n % 1 == 0) ? 1: (n % 10 == 0 && n % 1 == 0 && n > 10) ? 2 : 3;',
			// lt_LT
			'nplurals=4; plural=(n % 10 == 1 && (n % 100 > 19 || n % 100 < 11) ? 0 : (n % 10 >= 2 && n % 10 <=9) && (n % 100 > 19 || n % 100 < 11) ? 1 : n % 1 != 0 ? 2: 3);',
			// be, ru, ru_RU
			'nplurals=4; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<12 || n%100>14) ? 1 : n%10==0 || (n%10>=5 && n%10<=9) || (n%100>=11 && n%100<=14)? 2 : 3);',
			// pl, pl_PL
			'nplurals=4; plural=(n==1 ? 0 : (n%10>=2 && n%10<=4) && (n%100<12 || n%100>14) ? 1 : n!=1 && (n%10>=0 && n%10<=1) || (n%10>=5 && n%10<=9) || (n%100>=12 && n%100<=14) ? 2 : 3);',
			// uk
			'nplurals=4; plural=(n % 1 == 0 && n % 10 == 1 && n % 100 != 11 ? 0 : n % 1 == 0 && n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14) ? 1 : n % 1 == 0 && (n % 10 ==0 || (n % 10 >=5 && n % 10 <=9) || (n % 100 >=11 && n % 100 <=14 )) ? 2: 3);',
			// ar
			'nplurals=6; plural=n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : n%100>=3 && n%100<=10 ? 3 : n%100>=11 && n%100<=99 ? 4 : 5;'
		];
		// Ebenfalls aus l10n-Dateien, aber schon mit new Function unbrauchbar:
		// angehängte Kopfzeile bzw. fehlende Klammer
		var BROKEN_PLURAL_FORMS = [
			'nplurals=2; plural=(n > 1);\nGenerated-By: xls2po 3.0.1',
			'nplurals=2; plural=(n != 1);\nGenerated-By: xls2po 3.0.1',
			'nplurals=2; plural=(n == 0 || n == 1 || (n > 10 && n < 100) ? 0 : 1;'
		];
		var warnStub;

		// die frühere Fassung mit new Function, nur zum Vergleich
		function generateWithEval(pluralForm) {
			var pf = pluralForm;
			if (! /;\s*$/.test(pf)) {
				pf = pf.concat(';');
			}
			var code = 'var plural; var nplurals; ' + pf + ' return { "nplural" : nplurals, "plural" : (plural === true ? 1 : plural ? plural : 0) };';
			return new Function('n', code);
		}

		function differences(func, reference, values) {
			return _.filter(values, function(n) {
				return !_.isEqual(func(n), reference(n));
			});
		}

		function expectDefaultFunction(func) {
			expect(func(0)).toEqual({nplural: 2, plural: 1});
			expect(func(1)).toEqual({nplural: 2, plural: 0});
			expect(func(2)).toEqual({nplural: 2, plural: 1});
			expect(func(21)).toEqual({nplural: 2, plural: 1});
		}

		beforeEach(function() {
			warnStub = sinon.stub(console, 'warn');
			OC.L10N._pluralFormCache = {};
		});
		afterEach(function() {
			warnStub.restore();
		});

		it('matches the eval version for every plural form of the l10n files', function() {
			var values = _.range(0, 201);
			_.each(PLURAL_FORMS, function(form) {
				var func = OC.L10N._generatePluralFunction(form);
				expect({form: form, differences: differences(func, generateWithEval(form), values)})
					.toEqual({form: form, differences: []});
			});
			expect(warnStub.notCalled).toEqual(true);
		});
		it('matches the eval version for fractions, negative numbers and strings', function() {
			var values = [0.5, 1.5, 2.5, 21.5, 101.5, -1, -2, -11, -21, '0', '1', '2', '5', '21', '1.5', '', 'abc', null, undefined];
			_.each(PLURAL_FORMS, function(form) {
				var func = OC.L10N._generatePluralFunction(form);
				expect({form: form, differences: differences(func, generateWithEval(form), values)})
					.toEqual({form: form, differences: []});
			});
		});
		it('picks one of three forms for russian', function() {
			OC.L10N.register(TEST_APP, {
				'_download %n file_::_download %n files_':
					['%n файл', '%n файла', '%n файлов']
			}, 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);');
			expect(_.map([1, 2, 5, 11, 21, 22, 111], function(count) {
				return n(TEST_APP, 'download %n file', 'download %n files', count);
			})).toEqual(['1 файл', '2 файла', '5 файлов', '11 файлов', '21 файл', '22 файла', '111 файлов']);
		});
		it('shares one plural function between bundles with the same form', function() {
			var form = 'nplurals=2; plural=(n != 1);';
			expect(OC.L10N._generatePluralFunction(form)).toBe(OC.L10N._generatePluralFunction(form));
			expect(OC.L10N._generatePluralFunction('nplurals=2; plural=(n > 1);'))
				.not.toBe(OC.L10N._generatePluralFunction(form));
		});
		it('falls back to the default function where the eval version threw', function() {
			_.each(BROKEN_PLURAL_FORMS, function(form) {
				expect(function() { generateWithEval(form); }).toThrow();
				expectDefaultFunction(OC.L10N._generatePluralFunction(form));
			});
			expect(warnStub.callCount).toEqual(BROKEN_PLURAL_FORMS.length);
		});
		it('runs no code from invalid or malicious plural forms', function() {
			var probe = window.pluralFormProbe = sinon.stub().returns(1);
			var alertStub = sinon.stub(window, 'alert');
			var expressions = [
				'n; alert(1)',
				'n; pluralFormProbe()',
				'pluralFormProbe()',
				'(n != 1) && pluralFormProbe()',
				'constructor',
				'n.constructor',
				'n.constructor.constructor("pluralFormProbe()")()',
				'(function(){pluralFormProbe()})()',
				// JavaScript, aber kein gettext
				'-n', 'n === 1', 'n & 1', 'n, 1', '1.5', '010',
				// unvollständig
				'', 'n ?', 'n ? 1', '(n != 1', 'n != 1)', 'n 1',
				// sehr lang bzw. sehr tief verschachtelt
				new Array(5001).join('(') + 'n' + new Array(5001).join(')'),
				new Array(5001).join('n+') + 'n',
				new Array(10001).join('!') + 'n'
			];
			try {
				_.each(expressions, function(expression) {
					var func = OC.L10N._generatePluralFunction('nplurals=2; plural=' + expression + ';');
					expectDefaultFunction(func);
				});
			} finally {
				alertStub.restore();
				delete window.pluralFormProbe;
			}
			expect(probe.notCalled).toEqual(true);
			expect(alertStub.notCalled).toEqual(true);
			expect(warnStub.callCount).toEqual(expressions.length);
		});
		it('uses neither Function nor eval', function() {
			var functionSpy = sinon.spy(window, 'Function');
			var evalSpy = sinon.spy(window, 'eval');
			var translations;
			try {
				_.each(PLURAL_FORMS, function(form) {
					OC.L10N._generatePluralFunction(form)(5);
				});
				OC.L10N.register(TEST_APP, {
					'_download %n file_::_download %n files_':
						['%n Datei herunterladen', '%n Dateien herunterladen']
				}, 'nplurals=2; plural=(n != 1);');
				translations = [
					n(TEST_APP, 'download %n file', 'download %n files', 1),
					n(TEST_APP, 'download %n file', 'download %n files', 2)
				];
			} finally {
				functionSpy.restore();
				evalSpy.restore();
			}
			expect(functionSpy.called).toEqual(false);
			expect(evalSpy.called).toEqual(false);
			expect(translations).toEqual(['1 Datei herunterladen', '2 Dateien herunterladen']);
		});
	});
	describe('async loading of translations', function() {
		it('loads bundle for given app and calls callback', function() {
			var localeStub = sinon.stub(OC, 'getLocale').returns('zh_CN');
			var callbackStub = sinon.stub();
			var promiseStub = sinon.stub();
			OC.L10N.load(TEST_APP, callbackStub).then(promiseStub);
			expect(callbackStub.notCalled).toEqual(true);
			expect(promiseStub.notCalled).toEqual(true);
			expect(fakeServer.requests.length).toEqual(1);
			var req = fakeServer.requests[0];
			expect(req.url).toEqual(
				OC.webroot + '/apps3/' + TEST_APP + '/l10n/zh_CN.json'
			);
			req.respond(
				200,
				{ 'Content-Type': 'application/json' },
				JSON.stringify({
					translations: {'Hello world!': '你好世界!'},
					pluralForm: 'nplurals=2; plural=(n != 1);'
				})
			);

			expect(callbackStub.calledOnce).toEqual(true);
			expect(promiseStub.calledOnce).toEqual(true);
			expect(t(TEST_APP, 'Hello world!')).toEqual('你好世界!');
			localeStub.restore();
		});
		it('calls callback if translation already available', function() {
			var promiseStub = sinon.stub();
			var callbackStub = sinon.stub();
			OC.L10N.register(TEST_APP, {
				'Hello world!': 'Hallo Welt!'
			}, 'nplurals=2; plural=(n != 1);');
			OC.L10N.load(TEST_APP, callbackStub).then(promiseStub);
			expect(callbackStub.calledOnce).toEqual(true);
			expect(promiseStub.calledOnce).toEqual(true);
			expect(fakeServer.requests.length).toEqual(0);
		});
		it('calls callback if locale is en', function() {
			var localeStub = sinon.stub(OC, 'getLocale').returns('en');
			var promiseStub = sinon.stub();
			var callbackStub = sinon.stub();
			OC.L10N.load(TEST_APP, callbackStub).then(promiseStub);
			expect(callbackStub.calledOnce).toEqual(true);
			expect(promiseStub.calledOnce).toEqual(true);
			expect(fakeServer.requests.length).toEqual(0);
		});
	});
});
