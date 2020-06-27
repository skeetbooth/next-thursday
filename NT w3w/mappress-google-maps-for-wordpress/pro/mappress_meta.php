<?php
class Mappress_Meta {
	static $meta_queue = null;

	static function register() {
		// Change events for custom fields - only if geocoding is configured
		if (!empty(Mappress::$options->metaKeys)) {

			// WP post publish/update events
			add_action('save_post', array(__CLASS__, 'save_post'), 100, 2);

			// Custom field change events
			add_action('updated_post_meta', array(__CLASS__, 'meta_update'), 100, 3);
			add_action('added_post_meta', array(__CLASS__, 'meta_update'), 100, 3);
			add_action('deleted_post_meta', array(__CLASS__, 'meta_update'), 100, 3);

			// Shutdown, flush the queue
			add_action('shutdown', array(__CLASS__, 'shutdown'), 100, 2);

			// Force update event
			add_action('mappress_update_meta', array(__CLASS__, 'save_post'), 10, 2);
		}
	}

	/**
	* If a map-related custom field has changed, queue the post for update
	*
	*/
	static function meta_update($meta_id, $object_id, $meta_key, $_meta_value = null) {
		// Only configured meta keys
		if (!in_array($meta_key, Mappress::$options->metaKeys))
			return;

		// Only configured post types
		$post = get_post($object_id);
		if (!in_array($post->post_type, Mappress::$options->postTypes))
			return;

		// If there's another post queued, flush it first
		if (self::$meta_queue && self::$meta_queue != $object_id)
			self::create_meta_map(self::$meta_queue);

		// Queue current post for update
		self::$meta_queue = $object_id;
	}

	/**
	* Create new map when post is saved with custom field.  Existing map will not be changed.
	* This also flushes any queued updates.
	*/
	static function save_post($post_ID, $post = null) {
		// Ignore save_post for revisions
		if (wp_is_post_revision($post_ID))
			return;

		// Check that post exists and it is a configured post type
		$post = ($post) ? $post : get_post($post_ID);
		if (!$post || !in_array($post->post_type, Mappress::$options->postTypes))
			return;

		// If there's another post queued, flush it first
		if (self::$meta_queue && self::$meta_queue != $post_ID)
			self::create_meta_map(self::$meta_queue);

		self::create_meta_map($post_ID);
	}

	static function shutdown() {
		// If there's an update queued, flush it
		if (self::$meta_queue)
			self::create_meta_map(self::$meta_queue);
	}

	/**
	* Create a map from custom fields
	*
	* If only address field(s) are configured, the poi is geocoded and the address is corrected
	* If lat/lng fields are configured, the address is taken verbatim (or left blank) without geocoding
	*/
	static function create_meta_map($postid) {
		// Clear the queue
		self::$meta_queue = null;

		// Get any existing meta map
		$map = self::get_meta_map($postid);

		// If update isn't configured, return without changing map
		if ($map && !Mappress::$options->metaSyncSave)
			return false;

		// Clear any old errors
		delete_post_meta($postid, 'mappress_error');

		// Get poi from meta fields
		$poi = self::get_meta_poi($postid);

		// If no pois were found, delete any existing map and return
		if (empty($poi)) {
			if ($map)
				Mappress_Map::delete($map->mapid);
			return;
		}

		// Update existing map or create new map
		$map = ($map) ? $map : new Mappress_Map();
		$map->center = null;
		$map->metaKey = true;
		$map->pois = array($poi);
		$map->title = __('Automatic', 'mappress-google-maps-for-wordpress');
		$map->postid = $postid;
		if (isset(Mappress::$options->metaKeys['zoom']) && !empty(Mappress::$options->metaKeys['zoom']))
			$map->zoom = get_post_meta($postid, Mappress::$options->metaKeys['zoom'], true);
		$map->save($postid);
	}

	static function get_meta_map ($postid) {
		global $wpdb;
		$posts_table = $wpdb->prefix . 'mappress_posts';

		// Search by meta_key
		$results = $wpdb->get_results($wpdb->prepare("SELECT mapid FROM $posts_table WHERE postid = %d", $postid));

		if ($results === false)
			return false;

		// Find which map, if any was generated automatically
		foreach($results as $key => $result) {
			$map = Mappress_Map::get($result->mapid);
			if ($map && $map->metaKey)
				return $map;
		}
	}

	static function get_meta_poi($postid) {
		$meta_keys = Mappress::$options->metaKeys;
		$poi = new Mappress_Poi();
		$address = array();

		foreach($meta_keys as $key => $meta_field) {
			$meta_value = (empty($meta_field)) ? null : trim(get_post_meta($postid, $meta_field, true));
			if (empty($meta_value))
				continue;

			if (substr($key, 0, 7) == 'address')
				$address[] = $meta_value;
			else if ($key == 'lat' || $key == 'lng')
				$poi->point[$key] = $meta_value;
			else
				$poi->$key = $meta_value;
		}

		$poi->address = ($address) ? implode(', ', $address) : null;

		// If no address or lat/lng, then a meta poi doesn't exist
		if (empty($poi->address) && (empty($poi->point['lat']) || empty($poi->point['lng'])))
			return null;

		// Geocode the POI
		$result = $poi->geocode();
		if (is_wp_error($result)) {
			add_post_meta($postid, 'mappress_error', $result->get_error_message());
			return null;
		}
		return $poi;
	}
} // End Class Mappress_Pro
?>