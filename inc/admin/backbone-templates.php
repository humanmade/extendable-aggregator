<?php
/**
 * Backbone templates for extending the media views.
 *
 * One function for each template that needs to be printed to keep things very clear.
 *
 * Each function is then printed in modified_backbone_templates(). This function is
 * called in the Admin\Attachment() class.
 *
 * @package WordPress
 * @subpackage Extendable Aggregator
 */

namespace EA;

/**
 * Exclusively prints out our modified templates files for the media center.
 */
function modified_backbone_templates() {
	// Single grid object.
	grid_single_template();
}

/**
 * Modify and overwrite the media grid single-item template.
 *
 * We want to add a data attribute to the wrapping element and add a div node with text delineating where the
 * media item is currently synced from.
 */
function grid_single_template() {
?>
	<script type="text/html" id="tmpl-attachment-ea">
		<div class="attachment-preview js--select-attachment type-{{ data.type }} subtype-{{ data.subtype }} {{ data.orientation }}" data-object-synced="{{ data.ea_synced }}">
			<div class="thumbnail">
				<# if ( data.uploading ) { #>
					<div class="media-progress-bar"><div style="width: {{ data.percent }}%"></div></div>
				<# } else if ( 'image' === data.type && data.sizes ) { #>
					<div class="centered">
						<img src="{{ data.size.url }}" draggable="false" alt="" />
					</div>
				<# } else { #>
					<div class="centered">
						<# if ( data.image && data.image.src && data.image.src !== data.icon ) { #>
							<img src="{{ data.image.src }}" class="thumbnail" draggable="false" alt="" />
						<# } else { #>
							<img src="{{ data.icon }}" class="icon" draggable="false" alt="" />
						<# } #>
					</div>
					<div class="filename">
						<div>{{ data.filename }}</div>
					</div>
				<# } #>
			</div>
			<# if ( data.ea_synced ) { #>
				<div class="ea-synced-info">Synced from {{ data.ea_synced }}</div>
			<# } #>
			<# if ( data.buttons.close ) { #>
				<button type="button" class="button-link attachment-close media-modal-icon"><span class="screen-reader-text"><?php esc_html_e( 'Remove' ); ?></span></button>
				<# } #>
		</div>
		<# if ( data.buttons.check ) { #>
			<button type="button" class="button-link check" tabindex="-1"><span class="media-modal-icon"></span><span class="screen-reader-text"><?php esc_html_e( 'Deselect' ); ?></span></button>
		<# } #>
		<#
		var maybeReadOnly = data.can.save || data.allowLocalEdits ? '' : 'readonly';
		if ( data.describe ) {
		if ( 'image' === data.type ) { #>
		<input type="text" value="{{ data.caption }}" class="describe" data-setting="caption"
		       placeholder="<?php esc_attr_e( 'Caption this image&hellip;' ); ?>" {{ maybeReadOnly }} />
		<# } else { #>
			<input type="text" value="{{ data.title }}" class="describe" data-setting="title"
			<# if ( 'video' === data.type ) { #>
				placeholder="<?php esc_attr_e( 'Describe this video&hellip;' ); ?>"
				<# } else if ( 'audio' === data.type ) { #>
					placeholder="<?php esc_attr_e( 'Describe this audio file&hellip;' ); ?>"
				<# } else { #>
						placeholder="<?php esc_attr_e( 'Describe this media file&hellip;' ); ?>"
				<# } #> {{ maybeReadOnly }} />
			<# }
		} #>
	</script>
<?php
}
