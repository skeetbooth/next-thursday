<?php
class Mappress_Query {
	function __construct() {}

	static function register() {
		// Query parameters
		add_filter('query_vars', array(__CLASS__, 'filter_query_vars'));
		add_filter('parse_query', array(__CLASS__, 'filter_parse_query'));

		// AJAX
		add_action('wp_ajax_mapp_query', array(__CLASS__, 'ajax_query'));
		add_action('wp_ajax_nopriv_mapp_query', array(__CLASS__, 'ajax_query'));
	}

	/**
	* Parse a query from a shortcode
	*
	* Special 'query' parameter values:
	*   query='' or query='current' : current blog posts (default)
	*   query='all' : show ALL posts with a map
	*
	* @param array $atts - shortcode atts
	* @return array - query for the shortcode (as an array)
	*/
	static function parse_query($atts) {
		global $wp_query;

		$query = (isset($atts['query'])) ? $atts['query'] : array();

		// Back-compat for old mashup atts ('show' and 'show_query')
		if (isset($atts['show']) && !isset($atts['query'])) {
			$show = $atts['show'];
			$show_query = (isset($atts['show_query'])) ? $atts['show_query'] : '';
			$query = (empty($show) || $show == 'current' || $show == 'all') ? $show : $show_query;
		}

		// Special case 'all'
		if ($query == 'all') {
			$query = array('post_type' => 'any');
			$query['posts_per_page'] = isset($query['posts_per_page']) ? $query['posts_per_page'] : -1;
			return $query;
		}

		// Special case 'current' : treat this as a static map using the current posts verbatim
		if ($query == 'current' || empty($query)) {
			$query = null;
			return $query;
		}

		// If query is a querystring, convert it to an array
		if (!is_array($query)) {
			// If query came from a shortcode WP replaces "&, <, >" with html entities, so convert back
			$query = str_replace(array('&amp;', '&#038;', '&lt;', '&gt;'), array('&', '&', '<', '>'), $query);

			// Replace curly braces with brackets for array parameters, e.g. date_query, tax_query, etc.
			$query = str_replace(array('{', '}'), array('[', ']'), $query);

			parse_str($query, $query);

			// Explode array parameters into arrays.  Note that WP *requires* some parameters as comma-separated strings
			$array_keys = array('category__in', 'category__not_in', 'category__and', 'post__in', 'post__not_in', 'tag__in', 'tag__not_in', 'tag__and', 'tag_slug__in', 'tag_slug__and');

			// These parameters may be a string or an array
			$string_array_keys = array('post_type', 'post_status');

			foreach($query as $index => $arg) {
				if (in_array($index, $array_keys))
					$query[$index] = explode(',', $arg);

				else if (in_array($index, $string_array_keys) && strpos($arg, ',') !== false)
					$query[$index] = explode(',', $arg);
			}
		}

		// Substitute query variable strings: e.g. @cat
		foreach($query as $key => $value) {
			if (is_string($value) && substr($value, 0, 1) == '@') {
				$var = substr($value, 1);
				if (isset($wp_query->query_vars[$var]))
					$query[$key] =  $wp_query->query_vars[$var];
				elseif (isset($_REQUEST[$var]))
					$query[$key] = $_REQUEST[$var];
				else
					unset($query[$key]);
			}
		}

		return $query;
	}

	static function ajax_query() {
		ob_start();
		$args = (object) wp_parse_args($_GET, array('debug' => null, 'filters' => null, 'list' => null, 'query' => null));

		// Benchmarking
		self::timing('start');

		// Language for WPML
		Mappress::set_language();

		// Allow query to be modified
		$map = apply_filters('mappress_pre_query_filter', $args->query);

		// Convert query booleans
		$query = Mappress::string_to_boolean($args->query);

		// Query defaults
		$query = wp_parse_args($query, array(
			'post_type' => 'any',
		));

		// Query overrides
		$query = array_merge($query, array(
			'fields' => 'ids',
			'ignore_sticky_posts' => true,
			'map' => true,
			'posts_per_page' => -1
		));

		// Filters
		self::parse_filters($query, $args->filters);

		// Create map
		$map = new Mappress_Map(array('query' => $query));

		// Run query
		$wpq = new WP_Query($map->query);

		self::timing('query');

		// Get POIs
		$map->pois = self::get_pois($wpq);
		self::timing('get_pois');

		// Post-query filtering
		$map = apply_filters('mappress_query_filter', $map, $args->filters);

		// Prepare the map
		$map->prepare();
		self::timing('prepare');

		Mappress::ajax_response('OK', array(
			'timing' => ($args->debug) ? self::timing() : null,
			'wpq' => $wpq->request,
			'msg' =>  '',	// Not used
			'pois' => $map->pois
		), true);
	}

	/**
	* Get pois from query
	*/
	static function get_pois($wpq) {
		global $wpdb;

		// Round up postids (different for query = 'current')
		$postids = ($wpq->get('fields') == 'ids') ? $wpq->posts : array_map(function($post) { return $post->ID; }, $wpq->posts);
		$postids = implode(',', $postids);

		if (empty($postids))
			return array();

		// Gather map / post data
		$posts_table = $wpdb->prefix . 'mappress_posts';
		$maps_table = $wpdb->prefix . 'mappress_maps';

		$sql = "SELECT $wpdb->posts.*, $maps_table.obj FROM $wpdb->posts "
			. " INNER JOIN $posts_table ON ($posts_table.postid = $wpdb->posts.ID) "
			. " INNER JOIN $maps_table ON ($maps_table.mapid = $posts_table.mapid) "
			. " WHERE $wpdb->posts.ID IN($postids) "
			. " ORDER BY FIELD($wpdb->posts.ID, $postids); ";
		$posts = $wpdb->get_results($sql);

		$pois = array();

		foreach($posts as $post) {
			$map = unserialize($post->obj);

			// Use post object for WP internals
			$post = new WP_post($post);
			$post->filter = 'raw';

			foreach ($map->pois as $poi) {
				// No KML in mashups
				if (!Mappress::$options->mashupKml && $poi->type == 'kml')
					continue;

				// Permalink (200ms)
				$poi->postid = $post->ID;
				$poi->url = get_permalink($post); 	// WPML: $poi->url = apply_filters( 'wpml_permalink', home_url() . '?p=' . $_post->ID);

				// Post title/excerpt if needed (100ms with fast excerpt)
				if (Mappress::$options->mashupBody == 'post') {
					$poi->title = $post->post_title;
					$poi->body = $poi->get_post_excerpt($post);
				}

				// Thumbnail (300-500ms)
				if (Mappress::$options->thumbs)
					$poi->thumbnail = $poi->get_thumbnail($post);

				$pois[] = $poi;
			}
		}
		return $pois;
	}

	static function parse_filters(&$query, $filters) {
		// Convert filters from query string to array
		parse_str($filters, $filters);
		if (empty($filters) || !is_array($filters))
			return;

		foreach($filters as $key => $atts) {
			$filter = new Mappress_Filter($atts);

			// Remove empty values
			$filter->values = array_filter( (array) $filter->values, 'strlen');

			// Ignore empty filters
			if (empty($filter->values))
				continue;

			switch($filter->get_type()) {
				case 'post' :
					$query[$filter->key] = $filter->values;
					break;

				case 'tax' :
					$query['tax_query'][] = array('taxonomy' => $filter->key, 'field' => 'slug', 'terms' => $filter->values);

					// Try to remove any querystring parameters for same key
					if ($filter->key == 'post_tag')
						unset($query['tag'], $query['tag_id'], $query['tag__and'], $query['tag__in'], $query['tag__not_in'], $query['tag_slug__and'], $query['tag_slug__in']);
					else if ($filter->key == 'category')
						unset($query['cat'], $query['category_name'], $query['category__and'], $query['category__in'], $query['category__not_in']);
					break;

				case 'meta' :
					$meta_type = ($filter->type) ? $filter->type : 'CHAR';
					$query['meta_query'][] = array('key' => $filter->key, 'value' => $filter->values, 'type' => $filter->meta_type);
			}
		}
	}

	/**
	* Check if a query is empty, i.e. returns no POIs
	*
	* @param mixed $query
	* @return mixed
	*/
	static function is_empty($query) {
		global $wp_query;

		// For 'current' query just check current posts
		if (empty($query))
			return ( count($wp_query->posts) == 0 );

		// Set query fields
		$query['map'] = true; // only get posts with maps
		$query['post_type'] = (isset($query['post_type'])) ? $query['post_type'] : 'any';	// commonly forgotten
		$query['cache_results'] = false;
		$query['posts_per_page'] = 1;	// Max 1 post returned

		// Check that at least 1 post will be returned
		$wpq = new WP_Query($query);
		return ( count($wpq->posts) == 0 );
	}

	/**
	* Remove the is_admin flag from map queries
	* During frontend AJAX calls WP thinks it's running in the admin, so WP_Query will return private, draft, etc. posts that should be hidden
	* See: http://core.trac.wordpress.org/ticket/12400
	*
	* @param mixed $query
	*/
	static function filter_parse_query( $query ) {
		if (isset($query->query_vars['map']) && $query->query_vars['map'])
			$query->is_admin = false;
		return $query;
	}

	/**
	* Add map query variables
	*
	* @param mixed $qvars
	*/
	static function filter_query_vars ( $qv ) {
		$qv[] = 'map';
		return $qv;
	}

	static function timing($name = null) {
		static $times, $last_time;

		if ($name)
			$times[$name] = microtime(true);

		else {
			$s = '';
			$total = 0;
			foreach($times as $name => $time) {
				if ($name != 'start') {
					$d = round(1000 * ($time - $last_time), 0);
					$s .= " $name : " . $d;
					$total = $total + $d;
				}
				$last_time = $time;
			}
			return "$s total: $total";
		}
	}
}
?>