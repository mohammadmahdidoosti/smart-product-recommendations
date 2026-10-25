<?php
/**
 * Classic widget (sidebars / footers). In block widget areas use the
 * "Shortcode" block with [spr_recommendations] or the "Legacy widget" block.
 *
 * @package SPR
 */

defined( 'ABSPATH' ) || exit;

class SPR_Widget extends WP_Widget {

	public function __construct() {
		parent::__construct(
			'spr_widget',
			__( 'Product recommendations', 'smart-product-recommendations' ),
			array( 'description' => __( 'Similar, bought together, recently viewed, for you or trending products.', 'smart-product-recommendations' ) )
		);
	}

	public function widget( $args, $instance ) {
		$sources = SPR_Settings::sources();
		$source  = isset( $instance['source'], $sources[ $instance['source'] ] ) ? $instance['source'] : 'for_you';
		$limit   = isset( $instance['limit'] ) ? max( 1, min( 12, (int) $instance['limit'] ) ) : 4;
		$title   = isset( $instance['title'] ) && '' !== $instance['title'] ? $instance['title'] : $sources[ $source ];

		$product_id = 0;
		if ( in_array( $source, array( 'similar', 'bought' ), true ) ) {
			if ( ! ( function_exists( 'is_product' ) && is_product() ) ) {
				return; // These sources need a product context.
			}
			$product_id = get_queried_object_id();
		}

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo SPR_Frontend::slot( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				'source'    => $source,
				'placement' => 'widget_' . $source,
				'limit'     => $limit,
				'title'     => $title,
				'product'   => $product_id,
				'layout'    => 'grid',
				'columns'   => isset( $instance['columns'] ) ? (int) $instance['columns'] : 2,
			)
		);
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function form( $instance ) {
		$title   = isset( $instance['title'] ) ? $instance['title'] : '';
		$source  = isset( $instance['source'] ) ? $instance['source'] : 'for_you';
		$limit   = isset( $instance['limit'] ) ? (int) $instance['limit'] : 4;
		$columns = isset( $instance['columns'] ) ? (int) $instance['columns'] : 2;
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title', 'smart-product-recommendations' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'source' ) ); ?>"><?php esc_html_e( 'Recommendation type', 'smart-product-recommendations' ); ?></label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'source' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'source' ) ); ?>">
				<?php foreach ( SPR_Settings::sources() as $k => $label ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $source, $k ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'limit' ) ); ?>"><?php esc_html_e( 'Number of products', 'smart-product-recommendations' ); ?></label>
			<input class="tiny-text" id="<?php echo esc_attr( $this->get_field_id( 'limit' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'limit' ) ); ?>" type="number" min="1" max="12" value="<?php echo esc_attr( $limit ); ?>">
			<label for="<?php echo esc_attr( $this->get_field_id( 'columns' ) ); ?>"><?php esc_html_e( 'Columns', 'smart-product-recommendations' ); ?></label>
			<input class="tiny-text" id="<?php echo esc_attr( $this->get_field_id( 'columns' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'columns' ) ); ?>" type="number" min="1" max="6" value="<?php echo esc_attr( $columns ); ?>">
		</p>
		<?php
		return '';
	}

	public function update( $new, $old ) {
		$sources = SPR_Settings::sources();
		return array(
			'title'   => sanitize_text_field( isset( $new['title'] ) ? $new['title'] : '' ),
			'source'  => isset( $new['source'], $sources[ $new['source'] ] ) ? $new['source'] : 'for_you',
			'limit'   => max( 1, min( 12, (int) ( isset( $new['limit'] ) ? $new['limit'] : 4 ) ) ),
			'columns' => max( 1, min( 6, (int) ( isset( $new['columns'] ) ? $new['columns'] : 2 ) ) ),
		);
	}
}
