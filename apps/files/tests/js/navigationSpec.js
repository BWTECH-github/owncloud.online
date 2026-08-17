describe('OCA.Files.Navigation', function() {
	var nav;

	beforeEach(function() {
		$('#testArea').append(
			'<div id="app-navigation"><ul>' +
			'<li data-id="files"><a>Files</a></li>' +
			'<li data-id="trashbin"><a>Trash</a></li>' +
			'</ul></div>' +
			'<div id="app-content">' +
			'<div id="app-content-files" class="hidden viewcontainer">' +
			'<template class="viewcontent"></template>' +
			'<table id="filestable"></table>' +
			'</div>' +
			'<div id="app-content-trashbin" class="hidden viewcontainer">' +
			'<template class="viewcontent"><table id="filestable"></table></template>' +
			'</div>' +
			'</div>'
		);
		nav = new OCA.Files.Navigation($('#app-navigation'));
	});
	afterEach(function() {
		nav = null;
	});

	it('keeps exactly one set of list ids in the document', function() {
		nav.setActiveItem('files');
		expect($('#testArea').find('#filestable').length).toEqual(1);
		nav.setActiveItem('trashbin');
		expect($('#testArea').find('#filestable').length).toEqual(1);
		expect($('#app-content-trashbin').find('#filestable').length).toEqual(1);
		expect($('#app-content-files').find('#filestable').length).toEqual(0);
	});

	it('preserves element identity across park and unpark', function() {
		nav.setActiveItem('trashbin');
		var el = $('#app-content-trashbin').find('#filestable')[0];
		nav.setActiveItem('files');
		nav.setActiveItem('trashbin');
		expect($('#app-content-trashbin').find('#filestable')[0]).toBe(el);
	});

	it('parks a view that was never active (boot with view != files)', function() {
		// first call ever goes to trashbin - the files content must be
		// swept into its parking template although it was never current
		nav.setActiveItem('trashbin');
		expect($('#app-content-files').children('table').length).toEqual(0);
	});

	it('degrades gracefully without a template child', function() {
		$('#app-content-files').children('template').remove();
		nav.setActiveItem('files');
		nav.setActiveItem('trashbin');
		// files content stays in the DOM (old behaviour), nothing throws
		expect($('#app-content-files').find('#filestable').length).toEqual(1);
	});
});
