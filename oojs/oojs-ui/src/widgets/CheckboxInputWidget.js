/**
 * Checkbox input widget.
 *
 * @class
 * @extends OO.ui.InputWidget
 *
 * @constructor
 * @param {Object} [config] Configuration options
 */
OO.ui.CheckboxInputWidget = function OoUiCheckboxInputWidget( config ) {
	// Parent constructor
	OO.ui.CheckboxInputWidget.super.call( this, config );

	// Initialization
	this.$element.addClass( 'oo-ui-checkboxInputWidget' );
};

/* Setup */

OO.inheritClass( OO.ui.CheckboxInputWidget, OO.ui.InputWidget );

/* Methods */

/**
 * Get input element.
 *
 * @private
 * @return {jQuery} Input element
 */
OO.ui.CheckboxInputWidget.prototype.getInputElement = function () {
	return this.$( '<input type="checkbox" />' );
};

/**
 * Get checked state of the checkbox
 *
 * @return {boolean} If the checkbox is checked
 */
OO.ui.CheckboxInputWidget.prototype.getValue = function () {
	return this.value;
};

/**
 * Set checked state of the checkbox
 *
 * @param {boolean} value New value
 */
OO.ui.CheckboxInputWidget.prototype.setValue = function ( value ) {
	value = !!value;
	if ( this.value !== value ) {
		this.value = value;
		this.$input.prop( 'checked', this.value );
		this.emit( 'change', this.value );
	}
};

/**
 * @inheritdoc
 */
OO.ui.CheckboxInputWidget.prototype.onEdit = function () {
	var widget = this;
	if ( !this.isDisabled() ) {
		// Allow the stack to clear so the value will be updated
		setTimeout( function () {
			widget.setValue( widget.$input.prop( 'checked' ) );
		} );
	}
};
