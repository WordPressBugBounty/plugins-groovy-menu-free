<?php

namespace GroovyMenu;

defined( 'ABSPATH' ) || die( 'This script cannot be accessed directly.' );


/**
 * Class FieldInlineStart
 */
class FieldInlineStart extends \GroovyMenu\FieldField {
	/**
	 * Header of inline field
	 */
	public function render() {
		?>
		<div class="gm-gui-module-wrapper"
			<?php echo ( isset( $this->field['condition'] ) ) ? ' data-condition=\'' . esc_attr( wp_json_encode( $this->field['condition'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ) ) . '\'' : ''; ?>
			<?php echo ( isset( $this->field['condition_type'] ) ) ? ' data-condition_type="' . esc_attr( $this->field['condition_type'] ) . '" ' : ''; ?>>
			<span class="gm-gui__module__alpha"><?php echo wp_kses_post( $this->field['title'] ); ?></span>
			<?php
			if ( isset( $this->field['description'] ) && ! empty( $this->field['description'] ) ) {
				?>
				<p class="gm-gui__module__info"><?php echo wp_kses_post( $this->field['description'] ); ?></p>
				<?php
			}
	}
}
