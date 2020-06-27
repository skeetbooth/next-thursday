<?php
class Mappress_Widget extends WP_Widget {

	var $defaults = array(
		'height' => 300,
		'hideEmpty' => false,
		'other' => '',
		'poiList' => false,
		'show' => 'current',                    // query radio button: "all" = maps from ALL posts, "current" = maps from current posts, "query" = custom query
		'show_query' => null,                   // Custom query string
		'widget_title' => 'MapPress Map',
		'width' => '100%'
	);

	function __construct() {
		parent::__construct(false, $name = 'MapPress Map');
	}

	static function register() {
		add_action('widgets_init', array(__CLASS__, 'widgets_init'));
	}

	static function widgets_init() {
		return register_widget("Mappress_Widget");
	}

	function widget($args, $instance) {
		// Get widget attributes
		$atts = wp_parse_args($instance, $this->defaults);

		// Convert query input fields into a query
		if ($atts['show'] == 'current')
			$atts['query'] = '';
		else if ($atts['show'] == 'all')
			$atts['query'] = 'all';
		else
			$atts['query'] = $atts['show_query'];
		unset($atts['show'], $atts['show_query']);

		// Merge in any misc (shortcode) atts
		$other = shortcode_parse_atts($atts['other']);
		$other = Mappress::scrub_atts($other);
		$atts = array_merge($atts, $other);

		// Get the map html
		$html = Mappress::get_mashup($atts);

		// If html is empty, then assume the map was suppressed using hideEmpty
		if (empty($html))
			return;

		echo $args['before_widget'];
		echo $args['before_title'] . $instance['widget_title'] . $args['after_title'];
		echo $html;
		echo $args['after_widget'];
	}

	function update($new_instance, $old_instance) {
		// Set true/false/null
		$new_instance['hideEmpty'] = (isset($new_instance['hideEmpty'])) ? true : false;
		$new_instance['poiList'] = (isset($new_instance['poiList'])) ? true : false;
		return $new_instance;
	}

	function form($instance) {
		$args = (object) wp_parse_args($instance, $this->defaults);
		?>
			<p>
				<?php _e('Widget title', 'mappress-google-maps-for-wordpress'); ?>:
				<input class="widefat" id="<?php echo $this->get_field_id('widget_title'); ?>" name="<?php echo $this->get_field_name('widget_title'); ?>" type="text" value="<?php echo $args->widget_title ?>" />
			</p>

			<p>
				<?php _e('Map size', 'mappress-google-maps-for-wordpress'); ?>:
				<input size="3" id="<?php echo $this->get_field_id('width'); ?>" name="<?php echo $this->get_field_name('width'); ?>" type="text" value="<?php echo $args->width; ?>" />
				x <input size="3" id="<?php echo $this->get_field_id('height'); ?>" name="<?php echo $this->get_field_name('height'); ?>" type="text" value="<?php echo $args->height; ?>" />
			</p>

			<p>
				<?php _e('Show', 'mappress-google-maps-for-wordpress'); ?>:<br/>
				<input type="radio" name="<?php echo $this->get_field_name('show'); ?>" value="current" <?php checked($args->show, 'current'); ?> /> <?php _e('Current posts', 'mappress-google-maps-for-wordpress');?>
				&nbsp;( <input type="checkbox" name="<?php echo $this->get_field_name('hideEmpty'); ?>" <?php checked($args->hideEmpty); ?> /> <?php _e('Hide if empty', 'mappress-google-maps-for-wordpress'); ?> )
				<br/>
				<input type="radio" name="<?php echo $this->get_field_name('show'); ?>" value="all" <?php checked($args->show, 'all'); ?> /> <?php _e('All posts', 'mappress-google-maps-for-wordpress');?><br/>
				<input type="radio" name="<?php echo $this->get_field_name('show'); ?>" value="query" <?php checked($args->show, 'query'); ?> /> <?php _e('Custom query', 'mappress-google-maps-for-wordpress');?>
				<input type="text" style='width:100%' name="<?php echo $this->get_field_name('show_query'); ?>" value="<?php echo $args->show_query ?>" />

				<br/><i><?php echo "<a target='_none' href='http://codex.wordpress.org/Function_Reference/query_posts'>" . __('Learn about queries', 'mappress-google-maps-for-wordpress') . "</a>" ?></i>
			</p>

			<p>
				<input type="checkbox" name="<?php echo $this->get_field_name('poiList'); ?>" <?php checked($args->poiList); ?> />
				<?php _e('Show POI list', 'mappress-google-maps-for-wordpress');?>
				<br/>
			</p>

			<p>
				<br/>
				<?php _e('Other Settings', 'mappress-google-maps-for-wordpress'); ?>:<br/>
				<input type="text" style='width:100%' name="<?php echo $this->get_field_name('other'); ?>" value="<?php echo esc_attr($args->other); ?>" />
				<br/>
				<i><?php echo __('Example: initialopeninfo="true"', 'mappress-google-maps-for-wordpress');?></i>
			</p>
		<?php
	}
}
?>