<?php
class Mappress_Icons {
	static  $icons_dir,
			$icons_url,
			$standard_icons_url,
			$user_icons = array(),
			$standard_icons = array ('blue-dot', 'ltblue-dot', 'green-dot', 'pink-dot', 'purple-dot', 'red-dot', 'yellow-dot', 'blue', 'green', 'lightblue', 'pink', 'purple', 'red', 'yellow', 'blue-pushpin', 'grn-pushpin', 'ltblu-pushpin', 'pink-pushpin', 'purple-pushpin', 'red-pushpin', 'ylw-pushpin', 'bar', 'coffeehouse', 'man', 'wheel_chair_accessible', 'woman', 'restaurant', 'snack_bar', 'parkinglot', 'bus', 'cabs', 'ferry', 'helicopter', 'plane', 'rail', 'subway', 'tram', 'truck', 'info', 'info_circle', 'rainy', 'sailing', 'ski', 'snowflake_simple', 'swimming', 'water', 'fishing', 'flag', 'marina', 'campfire', 'campground', 'cycling', 'golfer', 'hiker', 'horsebackriding', 'motorcycling', 'picnic', 'POI', 'rangerstation', 'sportvenue', 'toilets', 'trail', 'tree', 'arts', 'conveniencestore', 'dollar', 'electronics', 'euro', 'gas', 'grocerystore', 'homegardenbusiness', 'mechanic', 'movies', 'realestate', 'salon', 'shopping', 'yen', 'caution', 'earthquake', 'fallingrocks', 'firedept', 'hospitals', 'lodging', 'phone', 'partly_cloudy', 'police', 'postoffice-us', 'sunny', 'volcano', 'camera', 'webcam', 'iimm1-blue', 'iimm1-green', 'iimm1-orange', 'iimm1-red', 'iimm2-blue', 'iimm2-green', 'iimm2-orange', 'iimm2-red', 'poly', 'kml');

	static function register() {
		// Create directories
		$upload = wp_upload_dir();
		$basedir = $upload['basedir'] . "/mappress";
		$baseurl = $upload['baseurl'] . "/mappress";
		wp_mkdir_p($basedir);
		wp_mkdir_p($basedir . "/icons");
		self::$icons_dir = $basedir . "/icons/";
		self::$icons_url = $baseurl . "/icons/";
		self::$standard_icons_url = Mappress::$baseurl . '/pro/standard_icons/';

		$files = @scandir(self::$icons_dir);
		if ($files) {
			natcasesort($files);
			foreach($files as $file) {
				if ( !stristr($file, '.shadow') && ( stristr($file, '.png') || stristr($file, '.gif')) )
					self::$user_icons[] = $file;
			}
		}

		add_action('admin_head', array(__CLASS__, 'admin_head'));
	}


	static function admin_head() {
		// Icon picker
		$user_icons = $standard_icons = '';
		foreach (self::$user_icons as $icon)
			$user_icons .= "<img class='mapp-icon' data-mapp-iconid='$icon' src='" . self::$icons_url . $icon . "' alt='$icon' title='$icon'>";
		foreach (self::$standard_icons as $i => $icon)
			$standard_icons .= "<span data-mapp-iconid='$icon' class='mapp-icon-sprite' style='background-position: " . $i * -24 . "px 0px' alt='$icon' title='$icon'></span>";

		$template = "
			<div class='mapp-iconpicker' tabindex='0'>
			<div class='mapp-iconpicker-wrapper'>
					$user_icons<br>$standard_icons
			</div>
			<div class='mapp-iconpicker-toolbar'>
					<input class='button' data-mapp-iconid='' type='button' value='" . __('Use default icon', 'mappress-google-maps-for-wordpress') . "'>
				</div>
			</div>
		";
		echo Mappress::script_template($template, 'iconpicker');

		// Color picker
		$colors = '';
		foreach(array('#F4EB37','#CDDC39','#62AF44','#009D57','#0BA9CC','#4186F0','#3F5BA9','#7C3592','#A61B4A','#DB4436','#F8971B','#F4B400','#795046','#F9F7A6','#E6EEA3','#B7DBAB','#7CCFA9','#93D7E8','#9FC3FF','#A7B5D7','#C6A4CF','#D698AD','#EE9C96','#FAD199','#FFDD5E','#B29189','#FFFFFF','#CCCCCC','#777777','#000000') as $color)
			$colors .= "<span data-mapp-color='$color' class='mapp-color' style='background-color: $color' tabindex='0'></span>";
		$opacity = __('Opacity', 'mappress-google-maps-for-wordpress') . ' ' . Mappress_Controls::select('', array_combine(range(100, 0, -10), range(100, 0, -10)), 100, array('class' => 'mapp-opacity'));
		$weight = __('Weight', 'mappress-google-maps-for-wordpress') . ' ' .  Mappress_Controls::select('', array_combine(range(1, 20), range(1, 20)), null, array('class' => 'mapp-weight'));

		$template = "
			<div class='mapp-colorpicker' tabindex='0'>
				$colors
				<div>$opacity $weight</div>
			</div>
		";
		echo Mappress::script_template($template, 'colorpicker');
	}

	static function spritesheet() {
		$sheet_width = count(self::$standard_icons) * 24;
		$sheet_height = 24;

		// Create empty placeholder image
		$sheet = imagecreatetruecolor($sheet_width, $sheet_height);
		imagealphablending($sheet, false);
		imagesavealpha($sheet, true);

		$offset = 0;
		$css = array();
		foreach (self::$standard_icons as $iconid) {
			$file = Mappress::$basedir . "/pro/standard_icons/$iconid.png";
			if (!file_exists($file))
				continue;

			$sprite = imagecreatefrompng($file);
			$sprite = imagescale ( $sprite, 24, 24);

			for ($y = 0; $y < 24; $y++) {
				for ($x = 0; $x < 24; $x++) {
					$color = imagecolorat($sprite, $x, $y);
					imagesetpixel($sheet, $offset + $x, $y, $color);
				}
			}
			$offset += 24;
		}

		// Save the spritesheet
		imagepng($sheet, Mappress::$basedir . '/images/icons.png');
	}

	/**
	* Get an icon image URL for output in the poi list
	*/
	static function get($icon) {
		if (Mappress::$pro)
			$icon = (empty($icon)) ? Mappress::$options->defaultIcon : $icon;
		else
			$icon = null;
		$icon = (empty($icon)) ? 'red-dot' : $icon;
		$url = ( stristr($icon, '.png') || stristr($icon, '.gif')) ? self::$icons_url . $icon : self::$standard_icons_url . $icon . '.png';
		return $url;
	}
}
?>