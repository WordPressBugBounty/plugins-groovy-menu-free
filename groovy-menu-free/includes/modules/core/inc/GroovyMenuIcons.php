<?php defined( 'ABSPATH' ) || die( 'This script cannot be accessed directly.' );

/**
 * @return string
 */
function GroovyMenuRenderIconsModal() {
	$icons               = '';
	$lang                = [];
	$lang['Select-icon'] = esc_html__( 'Select icon', 'groovy-menu' );
	$lang['Close']       = esc_html__( 'Close', 'groovy-menu' );

	foreach ( \GroovyMenu\FieldIcons::getFonts() as $fontName => $font ) {
		$font_slug = sanitize_html_class( $fontName );
		$font_name = isset( $font['name'] ) ? sanitize_text_field( $font['name'] ) : $font_slug;
		$icons .= '
<div class="groovy-iconset" data-name="' . esc_attr( $font_slug ) . '">
	<span class="groovy-iconset-name">' . esc_html( $font_name ) . ' (' . esc_html( $font_slug ) . ')</span>
	<div class="groovy-icons">
';

		foreach ( $font['icons'] as $icon ) {
			$icon_name  = isset( $icon['name'] ) ? sanitize_html_class( $icon['name'] ) : '';
			$icon_class = $font_slug . '-' . $icon_name;
			$icons     .= '<span class="groovy-icon ' . esc_attr( $icon_class ) . '" data-class="' . esc_attr( $icon_class ) . '"></span>';
		}
		$icons .= '</div></div>';
	}

	$out  = '';
	$out .= <<<HTML
	<div class="gm-modal gm-hidden" id="gm-icon-settings-modal">
		<div class="gm-modal-header">
			<h4 class="modal-title">{$lang['Select-icon']}</h4>
		</div>
		<div class="gm-modal-body">
			{$icons}
		</div>
		<div class="gm-modal-footer">
			<div class="btn-group">
				<button type="button" class="btn modal-btn gm-modal-close">{$lang['Close']}</button>
			</div>
		</div>
	</div>
HTML;


	return $out;

}
