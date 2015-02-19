/**
 * ActionSets manage the behavior of the {@link OO.ui.ActionWidget Action widgets} that comprise them.
 * Actions can be made available for specific contexts (modes) and circumstances
 * (abilities). Please see the [OOjs UI documentation on MediaWiki][1] for more information.
 *
 *     @example
 *     // Example: An action set used in a process dialog
 *     function ProcessDialog( config ) {
 *         ProcessDialog.super.call( this, config );
 *     }
 *     OO.inheritClass( ProcessDialog, OO.ui.ProcessDialog );
 *     ProcessDialog.static.title = 'An action set in a process dialog';
 *     // An action set that uses modes ('edit' and 'help' mode, in this example).
 *     ProcessDialog.static.actions = [
 *        { action: 'continue', modes: 'edit', label: 'Continue', flags: [ 'primary', 'constructive' ] },
 *        { action: 'help', modes: 'edit', label: 'Help' },
 *        { modes: 'edit', label: 'Cancel', flags: 'safe' },
 *        { action: 'back', modes: 'help', label: 'Back', flags: 'safe' }
 *     ];
 *
 *     ProcessDialog.prototype.initialize = function () {
 *         ProcessDialog.super.prototype.initialize.apply( this, arguments );
 *         this.panel1 = new OO.ui.PanelLayout( { $: this.$, padded: true, expanded: false } );
 *         this.panel1.$element.append( '<p>This dialog uses an action set (continue, help, cancel, back) configured with modes. This is edit mode. Click \'help\' to see help mode. </p>' );
 *         this.panel2 = new OO.ui.PanelLayout( { $: this.$, padded: true, expanded: false } );
 *         this.panel2.$element.append( '<p>This is help mode. Only the \'back\' action widget is configured to be visible here. Click \'back\' to return to \'edit\' mode</p>' );
 *         this.stackLayout= new OO.ui.StackLayout( {
 *             items: [ this.panel1, this.panel2 ]
 *         });
 *         this.$body.append( this.stackLayout.$element );
 *     };
 *     ProcessDialog.prototype.getSetupProcess = function ( data ) {
 *         return ProcessDialog.super.prototype.getSetupProcess.call( this, data )
 *         .next( function () {
 *         this.actions.setMode('edit');
 *         }, this );
 *     };
 *     ProcessDialog.prototype.getActionProcess = function ( action ) {
 *         if ( action === 'help' ) {
 *             this.actions.setMode( 'help' );
 *             this.stackLayout.setItem( this.panel2 );
 *             } else if ( action === 'back' ) {
 *             this.actions.setMode( 'edit' );
 *             this.stackLayout.setItem( this.panel1 );
 *             } else if ( action === 'continue' ) {
 *             var dialog = this;
 *             return new OO.ui.Process( function () {
 *                 dialog.close();
 *             } );
 *         }
 *         return ProcessDialog.super.prototype.getActionProcess.call( this, action );
 *     };
 *     ProcessDialog.prototype.getBodyHeight = function () {
 *         return this.panel1.$element.outerHeight( true );
 *     };
 *     var windowManager = new OO.ui.WindowManager();
 *     $( 'body' ).append( windowManager.$element );
 *     var processDialog = new ProcessDialog({
 *        size: 'medium'});
 *     windowManager.addWindows( [ processDialog ] );
 *     windowManager.openWindow( processDialog );
 *
 * [1]: https://www.mediawiki.org/wiki/OOjs_UI/Windows/Process_Dialogs#Action_sets
 *
 * @abstract
 * @class
 * @mixins OO.EventEmitter
 *
 * @constructor
 * @param {Object} [config] Configuration options
 */
OO.ui.ActionSet = function OoUiActionSet( config ) {
	// Configuration initialization
	config = config || {};

	// Mixin constructors
	OO.EventEmitter.call( this );

	// Properties
	this.list = [];
	this.categories = {
		actions: 'getAction',
		flags: 'getFlags',
		modes: 'getModes'
	};
	this.categorized = {};
	this.special = {};
	this.others = [];
	this.organized = false;
	this.changing = false;
	this.changed = false;
};

/* Setup */

OO.mixinClass( OO.ui.ActionSet, OO.EventEmitter );

/* Static Properties */

/**
 * Symbolic name of the flags used to identify special actions. Special actions are displayed in the
 *  header of a {@link OO.ui.ProcessDialog process dialog}.
 *  See the [OOjs UI documentation on MediaWiki][2] for more information and examples.
 *
 *  [2]:https://www.mediawiki.org/wiki/OOjs_UI/Windows/Process_Dialogs
 *
 * @abstract
 * @static
 * @inheritable
 * @property {string}
 */
OO.ui.ActionSet.static.specialFlags = [ 'safe', 'primary' ];

/* Events */

/**
 * @event click
 * @param {OO.ui.ActionWidget} action Action that was clicked
 */

/**
 * @event resize
 * @param {OO.ui.ActionWidget} action Action that was resized
 */

/**
 * @event add
 * @param {OO.ui.ActionWidget[]} added Actions added
 */

/**
 * @event remove
 * @param {OO.ui.ActionWidget[]} added Actions removed
 */

/**
 * @event change
 */

/* Methods */

/**
 * Handle action change events.
 *
 * @private
 * @fires change
 */
OO.ui.ActionSet.prototype.onActionChange = function () {
	this.organized = false;
	if ( this.changing ) {
		this.changed = true;
	} else {
		this.emit( 'change' );
	}
};

/**
 * Check if a action is one of the special actions.
 *
 * @param {OO.ui.ActionWidget} action Action to check
 * @return {boolean} Action is special
 */
OO.ui.ActionSet.prototype.isSpecial = function ( action ) {
	var flag;

	for ( flag in this.special ) {
		if ( action === this.special[ flag ] ) {
			return true;
		}
	}

	return false;
};

/**
 * Get actions.
 *
 * @param {Object} [filters] Filters to use, omit to get all actions
 * @param {string|string[]} [filters.actions] Actions that actions must have
 * @param {string|string[]} [filters.flags] Flags that actions must have
 * @param {string|string[]} [filters.modes] Modes that actions must have
 * @param {boolean} [filters.visible] Actions must be visible
 * @param {boolean} [filters.disabled] Actions must be disabled
 * @return {OO.ui.ActionWidget[]} Actions matching all criteria
 */
OO.ui.ActionSet.prototype.get = function ( filters ) {
	var i, len, list, category, actions, index, match, matches;

	if ( filters ) {
		this.organize();

		// Collect category candidates
		matches = [];
		for ( category in this.categorized ) {
			list = filters[ category ];
			if ( list ) {
				if ( !Array.isArray( list ) ) {
					list = [ list ];
				}
				for ( i = 0, len = list.length; i < len; i++ ) {
					actions = this.categorized[ category ][ list[ i ] ];
					if ( Array.isArray( actions ) ) {
						matches.push.apply( matches, actions );
					}
				}
			}
		}
		// Remove by boolean filters
		for ( i = 0, len = matches.length; i < len; i++ ) {
			match = matches[ i ];
			if (
				( filters.visible !== undefined && match.isVisible() !== filters.visible ) ||
				( filters.disabled !== undefined && match.isDisabled() !== filters.disabled )
			) {
				matches.splice( i, 1 );
				len--;
				i--;
			}
		}
		// Remove duplicates
		for ( i = 0, len = matches.length; i < len; i++ ) {
			match = matches[ i ];
			index = matches.lastIndexOf( match );
			while ( index !== i ) {
				matches.splice( index, 1 );
				len--;
				index = matches.lastIndexOf( match );
			}
		}
		return matches;
	}
	return this.list.slice();
};

/**
 * Get special actions.
 *
 * Special actions are the first visible actions with special flags, such as 'safe' and 'primary'.
 * Special flags can be configured by changing #static-specialFlags in a subclass.
 *
 * @return {OO.ui.ActionWidget|null} Safe action
 */
OO.ui.ActionSet.prototype.getSpecial = function () {
	this.organize();
	return $.extend( {}, this.special );
};

/**
 * Get other actions.
 *
 * Other actions include all non-special visible actions.
 *
 * @return {OO.ui.ActionWidget[]} Other actions
 */
OO.ui.ActionSet.prototype.getOthers = function () {
	this.organize();
	return this.others.slice();
};

/**
 * Toggle actions based on their modes.
 *
 * Unlike calling toggle on actions with matching flags, this will enforce mutually exclusive
 * visibility; matching actions will be shown, non-matching actions will be hidden.
 *
 * @param {string} mode Mode actions must have
 * @chainable
 * @fires toggle
 * @fires change
 */
OO.ui.ActionSet.prototype.setMode = function ( mode ) {
	var i, len, action;

	this.changing = true;
	for ( i = 0, len = this.list.length; i < len; i++ ) {
		action = this.list[ i ];
		action.toggle( action.hasMode( mode ) );
	}

	this.organized = false;
	this.changing = false;
	this.emit( 'change' );

	return this;
};

/**
 * Change which actions are able to be performed.
 *
 * Actions with matching actions will be disabled/enabled. Other actions will not be changed.
 *
 * @param {Object.<string,boolean>} actions List of abilities, keyed by action name, values
 *   indicate actions are able to be performed
 * @chainable
 */
OO.ui.ActionSet.prototype.setAbilities = function ( actions ) {
	var i, len, action, item;

	for ( i = 0, len = this.list.length; i < len; i++ ) {
		item = this.list[ i ];
		action = item.getAction();
		if ( actions[ action ] !== undefined ) {
			item.setDisabled( !actions[ action ] );
		}
	}

	return this;
};

/**
 * Executes a function once per action.
 *
 * When making changes to multiple actions, use this method instead of iterating over the actions
 * manually to defer emitting a change event until after all actions have been changed.
 *
 * @param {Object|null} actions Filters to use for which actions to iterate over; see #get
 * @param {Function} callback Callback to run for each action; callback is invoked with three
 *   arguments: the action, the action's index, the list of actions being iterated over
 * @chainable
 */
OO.ui.ActionSet.prototype.forEach = function ( filter, callback ) {
	this.changed = false;
	this.changing = true;
	this.get( filter ).forEach( callback );
	this.changing = false;
	if ( this.changed ) {
		this.emit( 'change' );
	}

	return this;
};

/**
 * Add actions.
 *
 * @param {OO.ui.ActionWidget[]} actions Actions to add
 * @chainable
 * @fires add
 * @fires change
 */
OO.ui.ActionSet.prototype.add = function ( actions ) {
	var i, len, action;

	this.changing = true;
	for ( i = 0, len = actions.length; i < len; i++ ) {
		action = actions[ i ];
		action.connect( this, {
			click: [ 'emit', 'click', action ],
			resize: [ 'emit', 'resize', action ],
			toggle: [ 'onActionChange' ]
		} );
		this.list.push( action );
	}
	this.organized = false;
	this.emit( 'add', actions );
	this.changing = false;
	this.emit( 'change' );

	return this;
};

/**
 * Remove actions.
 *
 * @param {OO.ui.ActionWidget[]} actions Actions to remove
 * @chainable
 * @fires remove
 * @fires change
 */
OO.ui.ActionSet.prototype.remove = function ( actions ) {
	var i, len, index, action;

	this.changing = true;
	for ( i = 0, len = actions.length; i < len; i++ ) {
		action = actions[ i ];
		index = this.list.indexOf( action );
		if ( index !== -1 ) {
			action.disconnect( this );
			this.list.splice( index, 1 );
		}
	}
	this.organized = false;
	this.emit( 'remove', actions );
	this.changing = false;
	this.emit( 'change' );

	return this;
};

/**
 * Remove all actions.
 *
 * @chainable
 * @fires remove
 * @fires change
 */
OO.ui.ActionSet.prototype.clear = function () {
	var i, len, action,
		removed = this.list.slice();

	this.changing = true;
	for ( i = 0, len = this.list.length; i < len; i++ ) {
		action = this.list[ i ];
		action.disconnect( this );
	}

	this.list = [];

	this.organized = false;
	this.emit( 'remove', removed );
	this.changing = false;
	this.emit( 'change' );

	return this;
};

/**
 * Organize actions.
 *
 * This is called whenever organized information is requested. It will only reorganize the actions
 * if something has changed since the last time it ran.
 *
 * @private
 * @chainable
 */
OO.ui.ActionSet.prototype.organize = function () {
	var i, iLen, j, jLen, flag, action, category, list, item, special,
		specialFlags = this.constructor.static.specialFlags;

	if ( !this.organized ) {
		this.categorized = {};
		this.special = {};
		this.others = [];
		for ( i = 0, iLen = this.list.length; i < iLen; i++ ) {
			action = this.list[ i ];
			if ( action.isVisible() ) {
				// Populate categories
				for ( category in this.categories ) {
					if ( !this.categorized[ category ] ) {
						this.categorized[ category ] = {};
					}
					list = action[ this.categories[ category ] ]();
					if ( !Array.isArray( list ) ) {
						list = [ list ];
					}
					for ( j = 0, jLen = list.length; j < jLen; j++ ) {
						item = list[ j ];
						if ( !this.categorized[ category ][ item ] ) {
							this.categorized[ category ][ item ] = [];
						}
						this.categorized[ category ][ item ].push( action );
					}
				}
				// Populate special/others
				special = false;
				for ( j = 0, jLen = specialFlags.length; j < jLen; j++ ) {
					flag = specialFlags[ j ];
					if ( !this.special[ flag ] && action.hasFlag( flag ) ) {
						this.special[ flag ] = action;
						special = true;
						break;
					}
				}
				if ( !special ) {
					this.others.push( action );
				}
			}
		}
		this.organized = true;
	}

	return this;
};
