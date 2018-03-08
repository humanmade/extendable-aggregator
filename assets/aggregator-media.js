/**
 * Handling of Media Backbone templates.
 */

var ExtendableAggregator = ExtendableAggregator || {},
    EA = ExtendableAggregator;

EA.media = {};

document.addEventListener( 'DOMContentLoaded', function() {
    EA.media.init();
});

/**
 * Initialize.
 */
EA.media.init = function() {
    if ( undefined !== window.wp.media ) {
        EA.media.replaceTemplates();
    }
}

/**
 * Replace templates with our own.
 */
EA.media.replaceTemplates = function() {
    // Single grid item.
    if ( window.wp.media.view.Attachment ) {
        _.extend(window.wp.media.view.Attachment.prototype, {
            template: window.wp.template( 'attachment-ea' )
        });
    }
}
