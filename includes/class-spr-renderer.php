<?php
/**
 * HTML output for recommendation blocks.
 *
 * Themes can override the card markup by placing
 * yourtheme/smart-product-recommendations/card.php (receives $product, $placement).
 *
 * @package SPR
 */

defined( 'ABSPATH' ) || exit;

class SPR_Renderer {

	/**
	 * Inline CSS variables for a block (accent, radius, columns).
	 */
	private static function style_vars( $columns ) {
		$accent = sanitize_hex_color( SPR_Settings::get( 'accent', '#0F8B8D' ) );
		$radius = max( 0, min( 28, (int) SPR_Settings::get( 'radius', 12 ) ) );
		return sprintf( '--spr-cols:%d;--spr-accent:%s;--spr-radius:%dpx', (int) $columns, $accent ? $accent : '#0F8B8D', $radius );
	}

	private static function style_class() {
		$style = SPR_Settings::get( 'style', 'card' );
		return in_array( $style, array( 'card', 'minimal', 'compact' ), true ) ? $style : 'card';
	}

	/**
	 * Render a block of product cards.
	 *
	 * @param int[]  $ids       Product IDs.
	 * @param string $title     Heading.
	 * @param string $placement Placement key (used for analytics).
	 * @param array  $opts      layout, columns, main (product ID for the bundle layout).
	 * @return string
	 */
	public static function block( array $ids, $title, $placement, array $opts = array() ) {
		if ( ! $ids ) {
			return '';
		}
		$opts = wp_parse_args(
			$opts,
			array(
				'layout'  => SPR_Settings::get( 'layout', 'carousel' ),
				'columns' => SPR_Settings::get( 'columns', 4 ),
				'main'    => 0,
			)
		);

		_prime_post_caches( $ids, false, true );

		if ( $opts['main'] && SPR_Settings::get( 'bundle' ) ) {
			$bundle = self::bundle( (int) $opts['main'], $ids, $title, $placement );
			if ( $bundle ) {
				return $bundle;
			}
		}

		$cards = '';
		$count = 0;
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product || ! $product->is_visible() ) {
				continue;
			}
			$cards .= self::card( $product, $placement );
			$count++;
		}
		if ( ! $count ) {
			return '';
		}

		$layout  = 'grid' === $opts['layout'] ? 'grid' : 'carousel';
		$columns = max( 1, min( 6, (int) $opts['columns'] ) );

		$nav = '';
		if ( 'carousel' === $layout ) {
			$nav = '<div class="spr-nav">'
				. '<button type="button" class="spr-prev" aria-label="' . esc_attr__( 'Previous', 'smart-product-recommendations' ) . '"></button>'
				. '<button type="button" class="spr-next" aria-label="' . esc_attr__( 'Next', 'smart-product-recommendations' ) . '"></button>'
				. '</div>';
		}

		$html  = '<section class="spr-block spr-layout-' . esc_attr( $layout ) . ' spr-style-' . esc_attr( self::style_class() ) . '" data-spr-placement="' . esc_attr( $placement ) . '" style="' . esc_attr( self::style_vars( $columns ) ) . '">';
		$html .= '<div class="spr-head">';
		if ( '' !== $title ) {
			$html .= '<h2 class="spr-title">' . esc_html( $title ) . '</h2>';
		}
		$html .= $nav . '</div>';
		$html .= '<ul class="spr-list">' . $cards . '</ul>';
		$html .= '</section>';

		return apply_filters( 'spr_block_html', $html, $ids, $title, $placement );
	}

	/**
	 * Can this product be added to the cart from a recommendation without choosing options?
	 */
	private static function quick_addable( $product ) {
		return $product->supports( 'ajax_add_to_cart' ) && $product->is_purchasable() && $product->is_in_stock();
	}

	private static function display_price( $product ) {
		if ( function_exists( 'wc_get_price_to_display' ) ) {
			return (float) wc_get_price_to_display( $product );
		}
		return (float) $product->get_price();
	}

	/**
	 * Discount percentage for simple products on sale (0 if not applicable).
	 */
	private static function discount( $product ) {
		if ( ! $product->is_on_sale() ) {
			return 0;
		}
		$regular = (float) $product->get_regular_price();
		$sale    = (float) $product->get_sale_price();
		if ( $regular > 0 && $sale > 0 && $sale < $regular ) {
			return (int) round( 100 * ( $regular - $sale ) / $regular );
		}
		return 0;
	}

	/**
	 * "Frequently bought together" as a bundle: this product + picks, live total, add all to cart.
	 *
	 * @return string Empty when the main product cannot be bundled (e.g. variable product).
	 */
	public static function bundle( $main_id, array $ids, $title, $placement ) {
		$main = wc_get_product( $main_id );
		if ( ! $main || ! self::quick_addable( $main ) ) {
			return '';
		}

		$items = array( $main );
		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( $p && $p->is_visible() && self::quick_addable( $p ) ) {
				$items[] = $p;
			}
			if ( count( $items ) >= 4 ) {
				break;
			}
		}
		if ( count( $items ) < 2 ) {
			return '';
		}

		$rows  = '';
		$total = 0;
		foreach ( $items as $i => $p ) {
			$price  = self::display_price( $p );
			$total += $price;
			$is_main = 0 === $i;
			$rows  .= sprintf(
				'<li class="spr-bundle-item%1$s" data-pid="%2$d" data-price="%3$s">'
				. '<label class="spr-check"><input type="checkbox" checked aria-label="%4$s"><span class="spr-thumb">%5$s</span></label>'
				. '<div class="spr-bundle-info">%6$s<span class="spr-price">%7$s</span></div>'
				. '</li>',
				$is_main ? ' is-main' : '',
				$p->get_id(),
				esc_attr( $price ),
				esc_attr( sprintf( /* translators: %s: product name */ __( 'Include %s', 'smart-product-recommendations' ), $p->get_name() ) ),
				$p->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy', 'class' => 'spr-img' ) ),
				$is_main
					? '<span class="spr-name"><span class="spr-this">' . esc_html__( 'This item:', 'smart-product-recommendations' ) . '</span> ' . esc_html( $p->get_name() ) . '</span>'
					: '<a class="spr-link spr-name" href="' . esc_url( $p->get_permalink() ) . '" data-spr-pid="' . (int) $p->get_id() . '">' . esc_html( $p->get_name() ) . '</a>',
				wp_kses_post( $p->get_price_html() )
			);
		}

		$count = count( $items );
		$html  = '<section class="spr-block spr-bundle spr-style-' . esc_attr( self::style_class() ) . '" data-spr-placement="' . esc_attr( $placement ) . '" style="' . esc_attr( self::style_vars( $count ) ) . '">';
		if ( '' !== $title ) {
			$html .= '<div class="spr-head"><h2 class="spr-title">' . esc_html( $title ) . '</h2></div>';
		}
		$html .= '<div class="spr-bundle-body">';
		$html .= '<ul class="spr-bundle-items">' . $rows . '</ul>';
		$html .= '<div class="spr-bundle-sum">'
			. '<span class="spr-sum-label">' . esc_html__( 'Total for selected items', 'smart-product-recommendations' ) . '</span>'
			. '<strong class="spr-sum-total">' . wp_kses_post( wc_price( $total ) ) . '</strong>'
			. '<button type="button" class="button spr-add-all" data-label="' . esc_attr__( 'Add %d items to cart', 'smart-product-recommendations' ) . '" data-label-one="' . esc_attr__( 'Add to cart', 'smart-product-recommendations' ) . '">'
			. esc_html( sprintf( /* translators: %d: number of products */ __( 'Add %d items to cart', 'smart-product-recommendations' ), $count ) )
			. '</button>'
			. '<span class="spr-bundle-msg" role="status" aria-live="polite"></span>'
			. '</div>';
		$html .= '</div></section>';

		return apply_filters( 'spr_bundle_html', $html, $items, $placement );
	}

	/**
	 * One product card.
	 *
	 * @param WC_Product $product   Product.
	 * @param string     $placement Placement.
	 * @return string
	 */
	public static function card( $product, $placement ) {
		$template = locate_template( 'smart-product-recommendations/card.php' );
		if ( $template ) {
			ob_start();
			include $template;
			return ob_get_clean();
		}

		$id    = $product->get_id();
		$url   = $product->get_permalink();
		$name  = $product->get_name();
		$image = $product->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy', 'class' => 'spr-img' ) );
		$price = $product->get_price_html();

		$badge = '';
		if ( SPR_Settings::get( 'show_discount' ) && $product->is_on_sale() ) {
			$pct   = self::discount( $product );
			$badge = '<span class="spr-badge">' . ( $pct
				? esc_html( sprintf( /* translators: %s: discount percent */ __( '%s%% off', 'smart-product-recommendations' ), number_format_i18n( $pct ) ) )
				: esc_html__( 'Sale', 'smart-product-recommendations' ) ) . '</span>';
		}

		$rating = '';
		if ( SPR_Settings::get( 'show_rating' ) && function_exists( 'wc_get_rating_html' ) && $product->get_average_rating() > 0 ) {
			$rating = '<span class="spr-rating">' . wc_get_rating_html( $product->get_average_rating(), $product->get_rating_count() ) . '</span>';
		}

		$button = '';
		if ( SPR_Settings::get( 'show_add_to_cart' ) ) {
			$ajax    = self::quick_addable( $product );
			$classes = array( 'button', 'spr-btn', 'product_type_' . $product->get_type() );
			if ( $ajax ) {
				$classes[] = 'add_to_cart_button';
				$classes[] = 'ajax_add_to_cart';
			}
			$button = sprintf(
				'<a href="%s" data-quantity="1" data-product_id="%d" data-product_sku="%s" data-spr-pid="%d" class="%s" rel="nofollow" aria-label="%s">%s</a>',
				esc_url( $product->add_to_cart_url() ),
				$id,
				esc_attr( $product->get_sku() ),
				$id,
				esc_attr( implode( ' ', $classes ) ),
				esc_attr( $product->add_to_cart_description() ),
				esc_html( $product->add_to_cart_text() )
			);
		}

		$html = '<li class="spr-item' . ( $product->is_in_stock() ? '' : ' is-oos' ) . '">'
			. '<a class="spr-link" href="' . esc_url( $url ) . '" data-spr-pid="' . (int) $id . '">'
			. '<span class="spr-thumb">' . $badge . $image . '</span>'
			. '<span class="spr-name">' . esc_html( $name ) . '</span>'
			. '</a>'
			. $rating
			. '<div class="spr-foot">'
			. ( $price ? '<span class="spr-price">' . wp_kses_post( $price ) . '</span>' : '' )
			. $button
			. '</div>'
			. '</li>';

		return apply_filters( 'spr_card_html', $html, $product, $placement );
	}
}
