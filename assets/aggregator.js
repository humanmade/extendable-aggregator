/* global ajaxurl */

var ExtendableAggregator = ExtendableAggregator || {},
    EA = ExtendableAggregator;

document.addEventListener( 'DOMContentLoaded', function() {
    EA.init();
});

/**
 * Start all of our event listeners and handle on-load tasks.
 */
EA.init = function() {
    // Disable bulk actions on synced objects
    EA.denyBulkActions();

    // If we're on a locked down post, lock the inputs and prevent submission.
    EA.lockSyncedInputs();

    // Find any action links on current page and hook into click event.
    EA.gatherActionLinks();

    if ( jQuery( '.syndicate-toggle' ).get(0) ) {
        jQuery( document ).on( 'click', '.syndicate-toggle a', function( e ) {
            e.preventDefault();
            EA.toggleSyncSelectors();
        } );

        if ( ! jQuery('.consumer-sites-item:not(:checked)' ).get(0) ) {
            EA.setSyncSelectors( true );
        }
    }
};

/**
 * Get all plugin action links and hook them into their click event.
 */
EA.gatherActionLinks = function() {
    // Find all aggregator action links on the page.
    var actionLinks = document.querySelectorAll( 'a.ea-action' ),
        quantity = actionLinks.length;

    // If we have valid links on this page, hook into the click event for each one.
    if ( 0 != quantity ) {
        for ( i = 0; i < quantity; i++ ) {
            EA.hookClicks( actionLinks[i] );
        }
    }
};

/**
 * Loop through all rows with the synced-object class and disable the checkbox.
 */
EA.denyBulkActions = function() {
    var listRows = document.querySelectorAll( 'tr.synced-object' ),
        quantity = listRows.length;

    if ( 0 != quantity ) {
        for ( i = 0; i < quantity; i++ ) {
            var checkbox = listRows[i].getElementsByClassName( 'check-column' )[0].getElementsByTagName( 'input' )[0];
            EA.toggleCheckboxDisabled( checkbox, true );
        }
    }
};

EA.lockSyncedInputs = function() {
    var body = document.getElementsByTagName( 'body' );

    if ( EA.hasClass( body[0], 'synced-object' ) ) {
        EA.toggleLockableInputs( true );
    }
};

/**
 * Hook click events for our AJAX actions.
 *
 * @param item
 */
EA.hookClicks = function( item ) {
    item.addEventListener( 'click', function() {

        var type = item.attributes['data-object-type'].value,
            action = item.attributes['data-ajax-action'].value,
            siteID = item.attributes['data-site-id'] ? item.attributes['data-site-id'].value : undefined,
            objectID = item.attributes['data-object-id'].value;

        // Assemble data for AJAX calls.
        var data = {
            'action' : 'ea_' + type + '_' + action,
            'object_id' : objectID,
            'site_id': ( undefined !== siteID ) ? siteID : '',
            'nonce' : eaValues.nonce
        };

        // Get the element containing our action link and change the opacity to indicate work occurring.
        var ancestor = EA.getAncestor( item );

        if ( false !== ancestor ) {
            ancestor.classList.add( "ea-working" );
        }

        // Handle AJAX calls.
        jQuery.post(
            ajaxurl,
            data
        ).done(function ( response ) {
            if ( true === response.success ) {
                if ( 'edit' === eaValues.page || 'edit-tags' === eaValues.page || 'upload' === eaValues.page ) {

                    // Just reload the page for now after successful ajax. The on the fly replacement implementation is buggy
                    location.reload();
                    // On listing pages, we need to clean up the object row a bit.
                    EA.cleanRow( item, action, ancestor );
                } else if ( 'post' === eaValues.page || 'term' === eaValues.page ) {
                    // On post & term page, we're detaching and should therefore release the post.
                    EA.unlockScreen( ancestor );
                }
            } else if ( false !== ancestor ) {
                // Remove the cogwheel and show the row again upon failure.
                ancestor.classList.remove( "ea-working" );
            }
        });
    });
};

/**
 * Disable the checkbox on post list screens so that bulk actions can't be clicked.
 *
 * @todo:: get this working on term and attachment list tables.
 *
 * @param Node item
 * @param bool True for disable, false for re-enable
 * @return bool false if no valid checkbox is passed in
 */
EA.toggleCheckboxDisabled = function( checkbox, disable ) {
    if ( undefined === typeof checkbox ) {
        return false;
    }

    checkbox.disabled = disable;
};

/**
 * Find the closest ancestor of a given type.
 *
 * @param {Node} item element node.
 * @returns {Node} element node.
 */
EA.getAncestor = function( item ) {
    // @todo:: convert to vanilla JS
    var ancestor = jQuery( item ).closest( ".wp-list-table tr, .notice" );

    if ( undefined == ancestor || undefined == ancestor[0] ) {
        return false;
    }

    return ancestor[0];
};

/**
 * Unlock an admin screen so that a user can immediately edit it.
 *
 * @param Node ancestor
 */
EA.unlockScreen = function( ancestor ) {
    var body = document.querySelectorAll( 'body' )[0];
    // Remove blocking class on body to allow people to see inputs.
    body.classList.remove( 'synced-object' );
    // Remove notice about being synced.
    ancestor.remove();
    // Re-enable lockable inputs.
    EA.toggleLockableInputs( false );
};

/**
 * Disable all inputs, textareas, selects, and submission buttons on locked-down screens.
 *
 * @param bool Disabled or not
 */
EA.toggleLockableInputs = function( locked ) {
    var inputs = EA.getLockableInputs(),
        length = inputs.length;

    // Make sure we have elements to look at.
    if ( length === 0 ) {
        return false;
    }

    for ( var i = 0; i < length; i++ ) {
        inputs[i].disabled = locked;
    }
};

/**
 * Fetch all lockable input that should not work on synced posts/terms.
 *
 * @returns {NodeList}
 */
EA.getLockableInputs = function() {
    return document.querySelectorAll( '.wrap input, .wrap textarea, .wrap select, .wrap button' );
};

/**
 * Check whether an element has a class or not.
 *
 * @param element Element node
 * @param string cls
 * @returns boolean
 */
EA.hasClass = function( element, cls ) {
    return element.className && new RegExp( "(\\s|^)" + cls + "(\\s|$)" ).test( element.className );
};

/**
 * Clean a table list row links and text after an action has occurred.
 *
 * @param {Node} item
 * @param {string} action
 * @param {Node} ancestor
 * @returns {boolean}
 */
EA.cleanRow = function( item, action, ancestor ) {

    // If we have no ancestor or the wrong ancestor, then there's nothing to clean up.
    if ( undefined == typeof ancestor || ! EA.hasClass( ancestor.parentNode.parentNode, 'wp-list-table' ) ) {
        return false;
    }

    if ( 'reattach' === action ) {

        // Add sync class back.
        EA.toggleClass( ancestor, true, 'synced-object' );
        // AdRemoved detached class
        EA.toggleClass( ancestor, false, 'detached-object' );
        // Re-disable the row checkbox.
        EA.toggleCheckboxDisabled( EA.findCheckbox( ancestor ), true );
        // Remove edit and quick edit links
        EA.removeEditLinks( ancestor );

        // Add a detach link in place of the reattach link
        var links = [
            EA.setActionLinkInfo( item, 'detach' )
        ];

        EA.buildActionLinks( ancestor, links );

    } else if ( 'detach' === action ) {

        // Remove synced class.
        EA.toggleClass( ancestor, false, 'synced-object' );
        // Add detached class
        EA.toggleClass( ancestor, true, 'detached-object' );
        // Re-enable the row checkbox.
        EA.toggleCheckboxDisabled( EA.findCheckbox( ancestor ), false );
        // Removed "Synced from..." text
        EA.removeSyncedText( ancestor );

        // Add back in an edit link
        // Add a detach link in place of the reattach link
        var links = [
            EA.setEditLinkInfo( item ),
            EA.setActionLinkInfo( item, 'reattach' )
        ];

        EA.buildActionLinks( ancestor, links );

    } else if ( 'publish' === action ) {
        // @todo:: handle "Enable Publishing" as well

        // Remove Draft post state text.
        EA.removeDraftText( ancestor );
        // Change preview link to view.
        EA.previewToView( ancestor, item );

    }

    // Remove action link span wrapper.
    item.parentNode.remove();

    // Trailing pipes have to be checked after we've removed our action link.
    if ( 'sync_new' === action || 'reattach' === action ) {

        // Check for and remove trailing pipe.
        EA.removeTrailingPipe( ancestor );

    }

    // Remove the cogwheel and show the row again.
    ancestor.classList.remove( 'ea-working' );
};

/**
 * Specific Row Cleanup Functions:
 */

/**
 * Remove "Draft" text from post states - used when publishing via AJAX.
 *
 * @param {Node} ancestor
 */
EA.removeDraftText = function( ancestor ) {
    var text = ancestor.getElementsByClassName( 'column-title' )[0].getElementsByTagName( 'strong' )[0],
        innerText = text.innerHTML;

    // Within strong text - replace " - " and "Draft".
    var replaced = innerText.replace( 'Draft', '' );
    replaced = replaced.replace( ' — ', '' );

    text.innerHTML = replaced;
};

/**
 * Remove 'Synced from...' text from post states list.
 *
 * @param {Node} ancestor
 */
EA.removeSyncedText = function( ancestor ) {
    var states = ancestor.getElementsByClassName( 'post-state' ),
        quantity = states.length;

    for ( var i = 0; i < quantity; i++ ) {
        if ( '-1' == states[ i ].innerText.indexOf( 'Synced from' ) ) {
            continue;
        }

        // Remove synced text.
        states[ i ].remove();

        // Check for a prior state, such as "Draft".
        var prev = i - 1;
        if ( undefined !== states[ prev ] ) {
            states[ prev ].innerHTML = states[ prev ].innerHTML.replace( ',', '' );
        } else {
            var text = ancestor.getElementsByClassName( 'column-title' )[0].getElementsByTagName( 'strong' )[0];
            text.innerHTML = text.innerHTML.replace( ' — ', '' );
        }
    }
};

/**
 * Add or remove the edit/quick edit links from the list row.
 *
 * @param {Node} ancestor
 * @param {boolean} add true for add, false for remove.
 * @param {Node} item
 */
EA.removeEditLinks = function( ancestor ) {
    ancestor.getElementsByClassName( 'edit' )[0].remove();

    // Edit always exists in the row list, inline edit doesn't exist on media items.
    if ( undefined !== ancestor.getElementsByClassName( 'inline' )[0] ) {
        ancestor.getElementsByClassName( 'inline' )[0].remove();
    }
};

/**
 * Change a preview link into a view link.
 *
 * @param {Node} ancestor
 * @param {Node} item
 */
EA.previewToView = function( ancestor, item ) {
    var link = ancestor.getElementsByClassName( 'view' )[0].getElementsByTagName( 'a' )[0];

    // Only want to modify if this is a preview link.
    if ( '-1' == link.innerText.indexOf( 'Preview' ) ) {
        return;
    }

    link.innerText = eaValues.text.view;
    link.setAttribute( 'href', eaValues.links.viewPost.replace( '{{ID}}', item.attributes['data-object-id'].value ) );
};

/**
 * Remove a trailing pipe character in a row actions list.
 *
 * @param {Node} ancestor
 */
EA.removeTrailingPipe = function( ancestor ) {
    var actions = ancestor.getElementsByClassName( 'row-actions' )[0].getElementsByTagName( 'span' ),
        lastAction = actions[ --actions.length ],
        text = lastAction.innerHTML;

    // We don't want to mess with our action links.
    if ( ! EA.hasClass( lastAction.getElementsByTagName( 'a' )[0], 'ea-action' ) ) {
        lastAction.innerHTML = text.replace( ' | ', '' );
    }
};

/**
 * Action Link Handling:
 */

/**
 * Iterate over a list of given links and add them to a row's action link group.
 *
 * @param {Node} ancestor
 * @param {Object} links
 */
EA.buildActionLinks = function( ancestor, links ) {
    var quantity = links.length,
        link = null,
        span = null;

    for ( i = 0; i < quantity; i++ ) {
        link = EA.buildActionRowLink( links[ i ] );
        span  = EA.buildActionSpan( link, links[ i ].spanClass );

        if ( undefined !== links[ i + 1 ] ) {
            span.innerHTML = span.innerHTML + ' | ';
        }

        EA.addActionLink( ancestor, span, link, links[ i ].hookAction );
    }
};

/**
 * Build and attach an action link and hook into the click event for it.
 *
 * @param {Node} ancestor
 * @param {Node} span
 * @param {Node} link
 * @param {boolean} hook
 */
EA.addActionLink = function( ancestor, span, link, hook ) {
    ancestor.getElementsByClassName( 'row-actions' )[0].appendChild( span );

    if ( true === hook ) {
        EA.hookClicks( link );
    }
};

/**
 * Build an action row span element.
 *
 * @param {Node} link
 * @param {string} action
 * @returns {Element}
 */
EA.buildActionSpan = function( link, action ) {
    var edit = document.createElement( 'span' );
    edit.appendChild( link );
    edit.classList.add( action );
    return edit;
};

/**
 * Build an action row link node from given object information
 *
 * @param {Object} link information about the link
 * @returns {Element}
 */
EA.buildActionRowLink = function( link ) {
    var item = document.createElement( 'a' );

    // Set the basic information about the link.
    item.setAttribute( 'href', link.url );
    item.innerText = link.text;

    // Some links need a class, some do not.
    if ( '' !== link.class ) {
        item.classList.add( link.class );
    }

    // Some links have extra attributes, some do not.
    if ( {} !== link.attributes ) {
       for ( var key in link.attributes ) {
           if ( link.attributes.hasOwnProperty( key ) ) {
               item.setAttribute( key, link.attributes[ key ] );
           }
       }
    }

    return item;
};

/**
 * Build an HTML node for a table-list-row edit action.
 *
 * @param {Node} item
 * @return {Node} Full action link and span for editing
 */
EA.setEditLinkInfo = function( item ) {
    var objectID = item.attributes['data-object-id'].value,
        type = item.attributes['data-object-type'].value,
        tax = ( undefined !== item.attributes['data-object-taxonomy'] ) ? item.attributes['data-object-taxonomy'].value : '';

    // Normalize attachment type to post - uses the same URL structure.
    type = ( 'attachment' === type ) ? 'post' : type;

    var url = eaValues.links[ type ].replace( '{{ID}}', objectID );

    if ( 'term' === type ) {
        url = url.replace( '{{taxonomy}}', tax );
    }

    return {
        'spanClass': 'edit',
        'url': url,
        'text': eaValues.text.edit,
        'attributes': {},
        'hookAction': false
    };
};

/**
 * Take values from an old item/link and make a new link.
 *
 * @param {Node} item
 * @param {string} action
 * @returns {Element}
 */
EA.setActionLinkInfo = function( item, action ) {
    var link = {
        'spanClass': action,
        'class': 'ea-action',
        'url': 'javascript:void(0)',
        'text': eaValues.text[ action ],
        'attributes': {
            'data-ajax-action': action,
            'data-object-id': item.attributes['data-object-id'].value,
            'data-object-type': item.attributes['data-object-type'].value
        },
        'hookAction': true
    };

    if ( undefined !== item.attributes['data-object-taxonomy'] ) {
        link.attributes['data-object-taxonomy'] = item.attributes['data-object-taxonomy'].value;
    }

    if ( undefined !== item.attributes['data-site-id'] ) {
        link.attributes['data-site-id'] = item.attributes['data-site-id'].value;
    }

    return link;
};

/**
 * General Utility Functions:
 */

/**
 * Add or remove the synced-object class from the list row.
 *
 * @param {Node} element
 * @param {boolean} add true for add, false for remove.
 * @param {string} className
 */
EA.toggleClass = function( element, add, className ) {
    if ( true === add ) {
        element.classList.add( className );
    } else {
        element.classList.remove( className );
    }
};

/**
 * Find the checkbox node from an ancestor list row.
 *
 * @param {Node} ancestor
 */
EA.findCheckbox = function( ancestor ) {
    return ancestor.getElementsByClassName( 'check-column' )[0].getElementsByTagName( 'input' )[0];
};

EA.toggleSyncSelectors = function() {

    if ( jQuery( '.syndicate-toggle .select-all' ).is( ':visible' ) ) {
        EA.setSyncSelectors( true );
    } else {
        EA.setSyncSelectors( false );
    }

};

EA.setSyncSelectors = function( ticked ) {

    if ( ticked ) {
        jQuery( '.syndicate-toggle .select-all' ).hide();
        jQuery( '.syndicate-toggle .deselect-all' ).show();
    } else {
        jQuery( '.syndicate-toggle .select-all' ).show();
        jQuery( '.syndicate-toggle .deselect-all' ).hide();
    }

    jQuery('.consumer-sites-item:not(:disabled)' ).each( function() {
        jQuery( this ).prop( 'checked', ticked );
    } );

};
