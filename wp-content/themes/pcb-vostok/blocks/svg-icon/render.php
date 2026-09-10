<?php

$name = isset( $attributes['name'] )
    ? sanitize_file_name( $attributes['name'] )
    : '';

if ( ! $name ) {
    return;
}

echo do_shortcode(
    '[svg_icon name="' . esc_attr( $name ) . '"]'
);