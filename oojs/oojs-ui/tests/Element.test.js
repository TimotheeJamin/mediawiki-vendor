QUnit.module( 'Element', {
	setup: function () {
		this.fixture = document.createElement( 'div' );
		document.body.appendChild( this.fixture );
	},
	teardown: function () {
		this.fixture.parentNode.removeChild( this.fixture );
		this.fixture = null;
	}
} );

QUnit.test( 'getDocument', 10, function ( assert ) {
	var frameDoc, frameEl, frameDiv,
		el = this.fixture,
		div = document.createElement( 'div' ),
		$el = $( this.fixture ),
		$div = $( '<div>' ),
		win = window,
		doc = document,
		frame = doc.createElement( 'iframe' );

	el.appendChild( frame );
	frameDoc = ( frame.contentWindow && frame.contentWindow.document ) || frame.contentDocument;
	frameEl = frameDoc.createElement( 'span' );
	frameDoc.documentElement.appendChild( frameEl );
	frameDiv = frameDoc.createElement( 'div' );

	assert.strictEqual( OO.ui.Element.getDocument( $el ), doc, 'jQuery' );
	assert.strictEqual( OO.ui.Element.getDocument( $div ), doc, 'jQuery (detached)' );
	assert.strictEqual( OO.ui.Element.getDocument( el ), doc, 'HTMLElement' );
	assert.strictEqual( OO.ui.Element.getDocument( div ), doc, 'HTMLElement (detached)' );
	assert.strictEqual( OO.ui.Element.getDocument( win ), doc, 'Window' );
	assert.strictEqual( OO.ui.Element.getDocument( doc ), doc, 'HTMLDocument' );

	assert.strictEqual( OO.ui.Element.getDocument( frameEl ), frameDoc, 'HTMLElement (framed)' );
	assert.strictEqual( OO.ui.Element.getDocument( frameDiv ), frameDoc, 'HTMLElement (framed, detached)' );
	assert.strictEqual( OO.ui.Element.getDocument( frameDoc ), frameDoc, 'HTMLDocument (framed)' );

	assert.strictEqual( OO.ui.Element.getDocument( {} ), null, 'Invalid' );
} );
