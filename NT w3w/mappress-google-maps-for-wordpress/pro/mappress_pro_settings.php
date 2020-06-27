<?php
class Mappress_Pro_Settings extends Mappress_Settings {
	function __construct() {
		parent::__construct();
	}

	static function register() {
		add_action('wp_ajax_mapp_autoicons_form', array(__CLASS__, 'set_autoicons_form'));
	}

	function admin_init() {
		parent::admin_init();

		// License: single blogs, or main blog on multisite
		if (!is_multisite() || (is_super_admin() && is_main_site())) {
			$this->add_field('license', __('MapPress license key', 'mappress-google-maps-for-wordpress'), 'license');
			$this->add_field('betas', __('Beta versions', 'mappress-google-maps-for-wordpress'), 'license');
		}

		if ($this->options->engine == 'leaflet')
			$this->add_field('geocoder', __('Geocoder', 'mappress-google-maps-for-wordpress'), 'basic');

		$this->add_field('poiList', __('POI list', 'mappress-google-maps-for-wordpress'), 'maps');
		$this->add_field('layout', __('POI list layout', 'mappress-google-maps-for-wordpress'), 'maps');
		$this->add_field('sort', __('Sort', 'mappress-google-maps-for-wordpress'), 'maps');

		if ($this->options->engine != 'leaflet')
			$this->add_field('iwType', __('Popup type', 'mappress-google-maps-for-wordpress'), 'pois');

		$this->add_field('defaultIcon', __('Default icon', 'mappress-google-maps-for-wordpress'), 'icons');
		$this->add_field('customIconsDir', __('Icon directory', 'mappress-google-maps-for-wordpress'), 'icons');
		$this->add_field('iconScale', __('Icon scaling', 'mappress-google-maps-for-wordpress'), 'icons');
		$this->add_field('autoicons', __('Automatic icons', 'mappress-google-maps-for-wordpress'), 'icons');

		$this->add_field('search', __('Search', 'mappress-google-maps-for-wordpress'), 'mashups');
		// Not used $this->add_field('radius', __('Radius (km)'), 'mashups');
		$this->add_field('filter', __('Filter', 'mappress-google-maps-for-wordpress'), 'mashups');
		$this->add_field('mashupBody', __('POI content', 'mappress-google-maps-for-wordpress'), 'mashups');
		$this->add_field('mashupClick', __('POI click', 'mappress-google-maps-for-wordpress'), 'mashups');
		$this->add_field('mashupKml', __('KMLs', 'mappress-google-maps-for-wordpress'), 'mashups');
		$this->add_field('thumbs', __('Thumbnails', 'mappress-google-maps-for-wordpress'), 'mashups');
		$this->add_field('thumbSize', __('Thumbnail size', 'mappress-google-maps-for-wordpress'), 'mashups');

		if ($this->options->engine == 'leaflet')
			$this->add_field('mapboxStyles', __('Styled maps', 'mappress-google-maps-for-wordpress'), 'styles');
		else {
			$this->add_field('styles', __('Styled maps', 'mappress-google-maps-for-wordpress'), 'styles');
			$this->add_field('style', __('Default style', 'mappress-google-maps-for-wordpress'), 'styles');
		}

		$this->add_field('metaKeys', __('Geocoding fields', 'mappress-google-maps-for-wordpress'), 'geocoding');
		$this->add_field('metaSyncSave', __('Overwrite', 'mappress-google-maps-for-wordpress'), 'geocoding');
		$this->add_field('metaErrors', __('Geocoding errors', 'mappress-google-maps-for-wordpress'), 'geocoding');

		if ($this->options->engine != 'leaflet')
			$this->add_field('apiKeyServer', __('Google Server API key', 'mappress-google-maps-for-wordpress'), 'geocoding');

		$this->add_field('templates', __('Custom templates', 'mappress-google-maps-for-wordpress'), 'templates');

		$this->add_field('forceResize', __('Force resize', 'mappress-google-maps-for-wordpress'), 'misc');
	}

	function validate($input) {
		// Cast widths/heights to integer; if either dimension is missing, set to empty array
		foreach(array('iconScale') as $dimension) {
			$size = (isset($input[$dimension])) ? $input[$dimension] : array(0,0);
			$input[$dimension] = ((int) $size[0] && (int) $size[1]) ? array((int) $size[0], (int) $size[1]) : array('', '');
		}

		// Cast integers
		$input['thumbWidth'] = (int) $input['thumbWidth'];
		$input['thumbHeight'] = (int) $input['thumbHeight'];

		// Combine arrays
		$input['metaKeys'] = $this->combine($input['metaKeys']);
		$input['styles'] = $this->combine($input['styles']);
		$input['mapboxStyles'] = $this->combine($input['mapboxStyles']);

		// Combine autoicons values
		if (isset($input['autoicons']['values']))
			$input['autoicons']['values'] = $this->combine($input['autoicons']['values']);

		// Try not to lose mapbox/google styles for the engine that's NOT selected
		if ($this->options->engine == 'leaflet')
			$input['styles'] = $this->options->styles;
		else
			$input['mapboxStyles'] = $this->options->mapboxStyles;

		// No mapbox geocoder if no mapbox access token
		if (isset($input['geocoder']) && $input['geocoder'] == 'mapbox' && empty($input['mapbox']))
			$input['geocoder'] = 'algolia';

		// If resize was clicked then resize ALL maps
		if (isset($_POST['force_resize']) && $_POST['resize_from']['width'] && $_POST['resize_from']['height']
		&& $_POST['resize_to']['width'] && $_POST['resize_to']['height']) {
			$maps = Mappress_Map::get_list();
			foreach ($maps as $map) {
				if ($map->width == $_POST['resize_from']['width'] && $map->height == $_POST['resize_from']['height']) {
					$map->width = $_POST['resize_to']['width'];
					$map->height = $_POST['resize_to']['height'];
					$map->save();
				}
			}
		}

		// License checking
		if (isset($input['license'])) {
			$input['license'] = trim($input['license']);
			$betas = (isset($input['betas'])) ? Mappress::string_to_boolean($input['betas']) : null;
			if (isset($_POST['check_license']) || $input['license'] != $this->options->license || $betas != $this->options->betas)
				Mappress::$updater->check($license, $betas);
		}


		return parent::validate($input);
	}

	function combine(&$a) {
		if (!is_array($a) || empty($a))
			return array();

		$result = array();
		$cols = array_keys($a);
		$combo = array_combine($a[$cols[0]], $a[$cols[1]]);

		if (!is_array($combo))
			return array();

		foreach($combo as $key => $value) {
			$key = sanitize_title_with_dashes($key, '', 'save');
			$value = trim($value);
			if (empty($key) || empty($value))
				continue;
			else
				$result[$key] = $value;
		}
		return $result;
	}

	function set_api_key_server($name) {
		echo Mappress_Controls::input($name, $this->options->apiKeyServer, array('size' => '50'));
		$helpurl = "<a href='https://mappresspro.com/mappress-faq' target='_blank'>" . __('more info', 'mappress-google-maps-for-wordpress') . "</a>";
		printf("<br/><i>%s %s</i>", __("API key secured by IP address for geocoding (optional)", 'mappress-google-maps-for-wordpress'), $helpurl);
	}

	function set_autoicons($name) {
		echo "<div class='mapp-autoicons'>" . self::set_autoicons_form($this->options->autoicons) . "</div>";
	}

	static function set_autoicons_form($atts) {
		$ajax = (defined('DOING_AJAX') && DOING_AJAX);
		$atts = ($ajax) ? $_GET : $atts;
		$rule = (object) shortcode_atts(array('key' => null, 'values' => array()), $atts);
		$name = self::$basename . "[autoicons]";
		$keys = array('post_type' => __('Post type', 'mappress-google-maps-for-wordpress')) + Mappress_Controls::get_taxonomies();

		$html = '';
		$html .= Mappress_Controls::select("{$name}[key]", $keys, $rule->key, array('id' => 'mapp-autoicons-key', 'none' => true));

		// Only show the icon assignment grid if a key has been selected
		if ($rule->key) {
			$headers = array(__('Value', 'mappress-google-maps-for-wordpress'), __('Icon', 'mappress-google-maps-for-wordpress'));
			$values = $rule->values + array('' => '');
			$value_keys = ($rule->key == 'post_type') ? Mappress_Controls::get_post_types() : Mappress_Controls::get_terms($rule->key);
			foreach($values as $value => $iconid) {
				$rows[] = array(
					Mappress_Controls::select("{$name}[values][value][]", $value_keys, $value, array('none' => true)),
					Mappress_Controls::icon_picker("{$name}[values][iconid][]", $iconid)
				);
			}
			$html .= Mappress_Controls::grid($headers, $rows);
		}
		return ($ajax) ? Mappress::ajax_response('OK', $html) : $html;
	}

	function set_betas($name) {
		echo Mappress_Controls::checkmark($name, $this->options->betas, __('Enable updates for beta versions', 'mappress-google-maps-for-wordpress'));
	}

	function set_custom_icons_dir() {
		echo "<code>" . Mappress_Icons::$icons_dir . "</code>";
	}

	function set_default_icon($name) {
		echo Mappress_Controls::icon_picker($name, $this->options->defaultIcon);
	}

	function set_filter($name) {
		$taxonomies = Mappress_Controls::get_taxonomies();
		$formats = array('checkboxes' => __('Checkboxes', 'mappress-google-maps-for-wordpress'), 'select' => __('Select', 'mappress-google-maps-for-wordpress'));
		echo Mappress_Controls::select("{$name}", $taxonomies, $this->options->filter, array('none' => true));
	}

	function set_filters($name) {
		$filters = $this->options->filters + array('' => '');
		$rows = array();
		$headers = array(__('Key', 'mappress-google-maps-for-wordpress'), __('Format', 'mappress-google-maps-for-wordpress'));
		foreach($filters as $key => $format) {
			$rows[] = array(
				Mappress_Controls::select("{$name}[key][]", $taxonomies, $key, array('none' => true)),
				Mappress_Controls::select("{$name}[format][]", $formats, $format, array('none' => false))
			);
		}
		echo Mappress_Controls::grid($headers, $rows, array('id' => 'mapp-filters', 'sortable' => true));
	}

	function set_force_resize() {
		$from = "<input type='text' size='2' name='resize_from[width]' value='' />"
			. "x<input type='text' size='2' name='resize_from[height]' value='' /> ";
		$to = "<input type='text' size='2' name='resize_to[width]]' value='' />"
			. "x<input type='text' size='2' name='resize_to[height]]' value='' /> ";
		echo __('Permanently resize existing maps (classic editor only)', 'mappress-google-maps-for-wordpress');
		echo ": <br/>";
		printf(__('from %s to %s', 'mappress-google-maps-for-wordpress'), $from, $to);
		echo "<input type='submit' name='force_resize' class='button' value='" . __('Force Resize', 'mappress-google-maps-for-wordpress') . "' />";
	}

	function set_geocoder($name) {
		echo Mappress_Controls::radio($name, '', __('Algolia', 'mappress-google-maps-for-wordpress') . ' (' . __('Default', 'mappress-google-maps-for-wordpress') . ')', array('checked' => 'checked'));
		echo Mappress_Controls::radio($name, 'nominatim', __('Nominatim', 'mappress-google-maps-for-wordpress'), array('checked' => $this->options->geocoder == 'nominatim'));
		echo Mappress_Controls::radio($name, 'mapbox', __('MapBox', 'mappress-google-maps-for-wordpress'), array('checked' => $this->options->geocoder == 'mapbox', 'disabled' => empty($this->options->mapbox)));
		echo Mappress_Controls::help(null, '#toc-picking-a-geocoder');
	}

	function set_icon_scale($name) {
		$scale = ($this->options->iconScale) ? $this->options->iconScale : array('', '');
		echo Mappress_Controls::input("{$name}[0]", $scale[0], array('maxlength' => 3, 'size' => 3));
		echo ' X ';
		echo Mappress_Controls::input("{$name}[1]", $scale[1], array('maxlength' => 3, 'size' => 3));
		echo ' (px)';
	}

	function set_iw_type($name) {
		$iw_types = array(
			'iw' => __('Standard', 'mappress-google-maps-for-wordpress'),
			'ib' => __('InfoBox', 'mappress-google-maps-for-wordpress')
		);
		echo Mappress_Controls::radios($name, $iw_types, $this->options->iwType);
	}

	function set_layout($name) {
		$layouts = array(
            'left' => __('Left of map', 'mappress-google-maps-for-wordpress'),
			'inline' => __('Below map', 'mappress-google-maps-for-wordpress')
		);
		echo Mappress_Controls::radios($name, $layouts, $this->options->layout);
	}

	function set_license($name) {
		echo Mappress_Controls::input($name, $this->options->license, array('size' => 50, 'placeholder' => __('Enter license to enable automatic updates', 'mappress-google-maps-for-wordpress')));

		if (empty($this->options->license))
			return;

		$yes = "<span class='dashicons dashicons-yes mapp-yes'></span>";
		$no = "<span class='dashicons dashicons-no mapp-no'></span>";

		$status = Mappress::$updater->get_status();

		if ($status == 'active') {
			echo $yes . __('Active', 'mappress-google-maps-for-wordpress');
			return;
		}

		// Show a 'check now' button if invalid
		echo "<input type='submit' name='check_license' class='button' value='" . __('Check Now', 'mappress-google-maps-for-wordpress') . "' />";

		// Message about what's wrong
		if (is_wp_error($status))
			printf("<div>$no %s : %s</div>", __('Communication error, please try again later', 'mappress-google-maps-for-wordpress'), $status->get_error_message());
		else
			printf("<div>$no %s</div>", __('License is invalid or expired', 'mappress-google-maps-for-wordpress'));
	}

	function set_mashup_body($name) {
		$body_types = array('poi' => __('POI title + body', 'mappress-google-maps-for-wordpress'), 'post' => __('Post title + excerpt', 'mappress-google-maps-for-wordpress'));
		echo Mappress_Controls::radios($name, $body_types, $this->options->mashupBody);
	}

	function set_mashup_click($name) {
		$types = array('poi' => __('Open POI', 'mappress-google-maps-for-wordpress'), 'post' => __('Open post', 'mappress-google-maps-for-wordpress'), 'postnew' => __('Open post in new tab', 'mappress-google-maps-for-wordpress'));
		echo Mappress_Controls::radios($name, $types, $this->options->mashupClick);
	}

	function set_mashup_kml($name) {
		echo Mappress_Controls::checkmark($name, $this->options->mashupKml, __('Include KML POIs in mashups', 'mappress-google-maps-for-wordpress'));
	}

	function set_meta_errors($name) {
		$max_posts = 20;
		$ids = new WP_Query(array('meta_key' => 'mappress_error', 'posts_per_page' => $max_posts, 'post_type' => $this->options->postTypes));

		if (count($ids->posts) == 0) {
			printf('No errors found');
			return;
		}

		echo '<div class="mapp-error">' . sprintf(__('%d Errors', 'mappress-google-maps-for-wordpress'), $ids->found_posts) . '</div>';
		foreach($ids->posts as $post) {
			$url = admin_url('post.php?post=%d&action=edit');
			printf("<div><a href='$url' target='_blank'>%s</a> : %s</div>", $post->ID, $post->post_title, get_post_meta($post->ID, 'mappress_error', true));
		}
	}

	function set_meta_keys($name) {
		$keys = array();
		for ($i = 1; $i < 7; $i++)
			$keys['address' . $i] = __('Address line ', 'mappress-google-maps-for-wordpress') . ' ' . $i;
		$keys = array_merge($keys, array('lat' => __('Latitude', 'mappress-google-maps-for-wordpress'), 'lng' => __('Longitude', 'mappress-google-maps-for-wordpress'), 'title' => __('Title', 'mappress-google-maps-for-wordpress'), 'body' => __('Body', 'mappress-google-maps-for-wordpress'), 'iconid' => __('Icon', 'mappress-google-maps-for-wordpress'), 'zoom' => __('Zoom', 'mappress-google-maps-for-wordpress')));

		$all_meta_keys = Mappress_Controls::get_meta_keys();
		$meta_keys = array_merge($this->options->metaKeys, array('' => ''));

		$headers = array(__('Map Field', 'mappress-google-maps-for-wordpress'), __('Custom Field', 'mappress-google-maps-for-wordpress'));
		$rows = array();

		foreach($meta_keys as $field => $meta_key) {
			$rows[] = array(
				Mappress_Controls::select("{$name}[field][]", $keys, $field, array('none' => true)),
				Mappress_Controls::select("{$name}[key][]", $all_meta_keys, $meta_key, array('none' => true))
			);
		}
		echo Mappress_Controls::grid($headers, $rows);
		}

	function set_meta_sync_save($name) {
		echo Mappress_Controls::checkmark($name, $this->options->metaSyncSave, __('Overwrite maps when posts are saved', 'mappress-google-maps-for-wordpress'));
	}

	function set_poi_list($name) {
		echo Mappress_Controls::checkmark($name, $this->options->poiList, __("Show a list of POIs with each map", 'mappress-google-maps-for-wordpress'));
	}

	function set_radius($name) {
		echo Mappress_Controls::input($name, $this->options->radius, array('maxlength' => 3, 'size' => 3));
	}

	function set_search($name) {
		echo Mappress_Controls::checkmark($name, $this->options->search, __('Enable search', 'mappress-google-maps-for-wordpress'));
	}

	function set_sort($name) {
		echo Mappress_Controls::checkmark($name, $this->options->sort, __('Sort POI list by title', 'mappress-google-maps-for-wordpress'));
	}

	function set_style($name) {
		$styles = ($this->options->engine == 'leaflet') ? $this->options->mapboxStyles : $this->options->styles;
		if (empty($styles)) {
			_e('No styles have been defined yet', 'mappress-google-maps-for-wordpress');
			return;
		}

		$style_names = array_combine(array_keys($styles), array_keys($styles));
		echo Mappress_Controls::select($name, $style_names, $this->options->style, array('none' => true));
	}

	function set_styles($name) {
		$wizard_link = "<a href='http://googlemaps.github.io/js-samples/styledmaps/wizard/index.html' target='_blank'>" . __('Styled Maps Wizard', 'mappress-google-maps-for-wordpress') . "</a>";
		echo "<p>" . sprintf(__("JSON from Google's %s", 'mappress-google-maps-for-wordpress'), $wizard_link) . "<br/>";

		$styles = $this->options->styles + array('' => '');
		$rows = array();
		$headers = array(__("Style name", 'mappress-google-maps-for-wordpress'), 'JSON');
		foreach($styles as $style_name => $style) {
			$rows[] = array(
				"<input type='text' size='10' name='{$name}[id][]' value='$style_name' />",
				"<textarea rows='1' style='width:100%' class='mapp-expand' name='{$name}[json][]'>$style</textarea>"
			);
		}

		echo Mappress_Controls::grid($headers, $rows);
	}

	function set_mapbox_styles($name) {
		$wizard_link = "<a href='https://www.mapbox.com/mapbox-studio/' target='_blank'>" . __('Mapbox Studio', 'mappress-google-maps-for-wordpress') . "</a>";
		echo "<p>" . sprintf(__("Enter styles from %s", 'mappress-google-maps-for-wordpress'), $wizard_link) . "<br/>";
		$styles = $this->options->mapboxStyles + array('' => '');
		$rows = array();
		$headers = array(__("Style name", 'mappress-google-maps-for-wordpress'), "MapBox Share URL");
		foreach($styles as $style_name => $url) {
			$rows[] = array(
				"<input type='text' size='10' name='{$name}[name][]' value='$style_name' />",
				"<input type='text' size='80' name='{$name}[url][]' value='$url' />"
			);
		}

		echo Mappress_Controls::grid($headers, $rows);
	}

	function set_templates() {
		global $wp_version;

		if (!is_super_admin()) {
			echo __('Only admins or multisite super-admins can edit templates', 'mappress-google-maps-for-wordpress');
			return;
		}

		if (version_compare($wp_version, '4.9', '<')) {
			echo __('WordPress 4.9 or higher is needed to use the template editor.', 'mappress-google-maps-for-wordpress');
			return;
		}

		echo "<div class='mapp-tp-editor'></div>";
		echo Mappress::script(sprintf('new mapp.widgets.TemplateEditor(".mapp-tp-editor", %s);', json_encode(Mappress_Template::$tokens)), true);
	}

	function set_thumb_size($name) {
		// Note: WP doesn't return dimensions, just the size names - ticket is > 6 months old now: http://core.trac.wordpress.org/ticket/18947
		$sizes = get_intermediate_image_sizes();
		$sizes = array_combine(array_values($sizes), array_values($sizes));

		echo Mappress_Controls::select($name, $sizes, $this->options->thumbSize, array('none' => true));
		echo __("or ", 'mappress-google-maps-for-wordpress');
		echo Mappress_Controls::input(self::$basename . "[thumbWidth]", $this->options->thumbWidth, array('size' => 2, 'maxlength' => 3));
		echo " X ";
		echo Mappress_Controls::input(self::$basename . "[thumbHeight]", $this->options->thumbHeight, array('size' => 2, 'maxlength' => 3));
		echo "  (px)";
	}

	function set_thumbs($name) {
		echo Mappress_Controls::checkmark($name, $this->options->thumbs, __("Show featured image thumbnails in mashup POIs", 'mappress-google-maps-for-wordpress'));
	}

	static function ajax_get_rule_values() {
		$key = (isset($_REQUEST['key'])) ? $_REQUEST['key'] : null;
		Mappress::ajax_response('OK', self::get_rule_values($key));
	}

	static function get_rule_values($key) {
		if (!$key)
			return array();

		if ($key == 'post_type')
			$values = array_combine(Mappress::$options->postTypes, Mappress::$options->postTypes);

		else {
			$terms = get_terms($key, array('hide_empty' => false));
			$walker = new Mappress_Walker();
			$walk = $walker->walk($terms, 0, array('indent' => true));
			$values = (is_array($walk) && !empty($walk)) ? $walk : array();
		}

		// Add a blank entry
		$values = array('' => '&nbsp;') + $values;
		return $values;
	}

	static function get_usage() {
		$usage = new stdClass();
		foreach(array('betas', 'engine', 'mapBox') as $key) {
			if (isset(Mappress::$options->$key))
				$usage->$key = Mappress::$options->$key;
		}
		$usage->mp_version = Mappress::VERSION;
		$usage->wp_version = get_bloginfo('version');
        $usage->gutenberg = version_compare( $GLOBALS['wp_version'], '5.0-beta', '>' ) && !is_plugin_active( 'classic-editor/classic-editor.php' );
		return $usage;
	}
} // End class Mappress_Pro_Settings
?>