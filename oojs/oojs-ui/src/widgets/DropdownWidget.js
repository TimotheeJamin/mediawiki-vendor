/**
 * Dropdown menu of options.
 *
 * Dropdown menus provide a control for accessing a menu and compose a menu within the widget, which
 * can be accessed using the #getMenu method.
 *
 * Use with OO.ui.MenuOptionWidget.
 *
 * @class
 * @extends OO.ui.Widget
 * @mixins OO.ui.IconElement
 * @mixins OO.ui.IndicatorElement
 * @mixins OO.ui.LabelElement
 * @mixins OO.ui.TitledElement
 * @mixins OO.ui.TabIndexedElement
 *
 * @constructor
 * @param {Object} [config] Configuration options
 * @cfg {Object} [menu] Configuration options to pass to menu widget
 */
OO.ui.DropdownWidget = function OoUiDropdownWidget( config ) {
	// Configuration initialization
	config = $.extend( { indicator: 'down' }, config );

	// Parent constructor
	OO.ui.DropdownWidget.super.call( this, config );

	// Properties (must be set before TabIndexedElement constructor call)
	this.$handle = this.$( '<span>' );

	// Mixin constructors
	OO.ui.IconElement.call( this, config );
	OO.ui.IndicatorElement.call( this, config );
	OO.ui.LabelElement.call( this, config );
	OO.ui.TitledElement.call( this, $.extend( {}, config, { $titled: this.$label } ) );
	OO.ui.TabIndexedElement.call( this, $.extend( {}, config, { $tabIndexed: this.$handle } ) );

	// Properties
	this.menu = new OO.ui.MenuSelectWidget( $.extend( { widget: this }, config.menu ) );

	// Events
	this.$handle.on( {
		click: this.onClick.bind( this ),
		keypress: this.onKeyPress.bind( this )
	} );
	this.menu.connect( this, { select: 'onMenuSelect' } );

	// Initialization
	this.$handle
		.addClass( 'oo-ui-dropdownWidget-handle' )
		.append( this.$icon, this.$label, this.$indicator );
	this.$element
		.addClass( 'oo-ui-dropdownWidget' )
		.append( this.$handle, this.menu.$element );
};

/* Setup */

OO.inheritClass( OO.ui.DropdownWidget, OO.ui.Widget );
OO.mixinClass( OO.ui.DropdownWidget, OO.ui.IconElement );
OO.mixinClass( OO.ui.DropdownWidget, OO.ui.IndicatorElement );
OO.mixinClass( OO.ui.DropdownWidget, OO.ui.LabelElement );
OO.mixinClass( OO.ui.DropdownWidget, OO.ui.TitledElement );
OO.mixinClass( OO.ui.DropdownWidget, OO.ui.TabIndexedElement );

/* Methods */

/**
 * Get the menu.
 *
 * @return {OO.ui.MenuSelectWidget} Menu of widget
 */
OO.ui.DropdownWidget.prototype.getMenu = function () {
	return this.menu;
};

/**
 * Handles menu select events.
 *
 * @param {OO.ui.MenuOptionWidget} item Selected menu item
 */
OO.ui.DropdownWidget.prototype.onMenuSelect = function ( item ) {
	var selectedLabel;

	if ( !item ) {
		return;
	}

	selectedLabel = item.getLabel();

	// If the label is a DOM element, clone it, because setLabel will append() it
	if ( selectedLabel instanceof jQuery ) {
		selectedLabel = selectedLabel.clone();
	}

	this.setLabel( selectedLabel );
};

/**
 * Handle mouse click events.
 *
 * @param {jQuery.Event} e Mouse click event
 */
OO.ui.DropdownWidget.prototype.onClick = function ( e ) {
	if ( !this.isDisabled() && e.which === 1 ) {
		if ( this.menu.isVisible() ) {
			this.menu.toggle( false );
		} else {
			this.menu.toggle( true );
		}
	}
	return false;
};

/**
 * Handle key press events.
 *
 * @param {jQuery.Event} e Key press event
 */
OO.ui.DropdownWidget.prototype.onKeyPress = function ( e ) {
	if ( !this.isDisabled() && ( e.which === OO.ui.Keys.SPACE || e.which === OO.ui.Keys.ENTER ) ) {
		if ( this.menu.isVisible() ) {
			this.menu.toggle( false );
		} else {
			this.menu.toggle( true );
		}
	}
	return false;
};
