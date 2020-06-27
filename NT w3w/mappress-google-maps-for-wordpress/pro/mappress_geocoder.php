<?php
class Mappress_Geocoder {

	/**
	* Geocode an address using http
	*
	* @return true | WP_Error on failure
	*/
	static function geocode($address) {
		// If the address is in format "lat,lng" then geocoding isn't needed
		$latlng = self::string_to_latlng($address);
		if ($latlng) {
			return (object) array(
				'formatted_address' => null,
				'lat' => $latlng->lat,
				'lng' => $latlng->lng,
				'viewport' => null
			);
		}

		// Do the geocoding
		for ($i = 0; $i < 2; $i++) {
			// Call appropriate geocoder function - if using Google, geocoder is ALWAYS 'google'
			$geocoder = (Mappress::$options->engine == 'leaflet') ? Mappress::$options->geocoder : 'google';
			$geocoder = (empty($geocoder)) ? 'algolia' : $geocoder;

			// If geocoder invalid, return immediately
			if (!method_exists(__CLASS__, $geocoder))
				return new WP_Error('geocoder', sprintf(__('Invalid geocoder: %s', 'mappress-google-maps-for-wordpress'), $geocoder));

			$result = self::$geocoder($address);

			// If query limit is reached, wait a few seconds and try again
			if ($result === -1)
				sleep(2);
			else
				return $result;
		}
	}

	static function algolia($address) {
		// Default to english, otherwise Algolia returns ALL languages
		$language = (Mappress::$options->language) ? Mappress::$options->language : 'en';
		$url = "https://places-dsn.algolia.net/1/places/query?query=" . urlencode($address);
		$url = (Mappress::$options->country) ? $url . "&countries=" . strtolower(Mappress::$options->country) : $url;
		$url = ($language) ? $url . "&language=" . strtolower($language) : $url;

		$args = array('sslverify' => false);
		$json = self::get_json($url, $args);
		if (is_wp_error($json))
			return $json;

		// If query limit is reached, wait a few seconds and try again
		if (isset($json->message))
			return -1;

		// Error if no results
		if (!$json || !isset($json->hits) || !isset($json->hits[0]))
			return new WP_Error('algolia', sprintf(__('No results for address: %s', 'mappress-google-maps-for-wordpress'), $address));

		// First result
		$hit = $json->hits[0];

		// Take first 'locale_name'
		$locale_name = $hit->locale_names[0];

		// Format as a placemark
		$location = (object) array(
			'formatted_address' => $locale_name,
			'lat' => $hit->_geoloc->lat,
			'lng' => $hit->_geoloc->lng,
			'viewport' => null		// Alogolia doesn't have viewports or bboxes
		);

		return $location;
	}

	static function google($address) {
		// Geocoding won't work without a server api key
		$apikey = Mappress::get_api_keys()->server;
		if (!$apikey)
			return new WP_Error('geocode', __("Server API Key is missing - see MapPress Settings", 'mappress-google-maps-for-wordpress'));;

		$url = "https://maps.googleapis.com/maps/api/geocode/json?address=" . urlencode($address) . "&output=json";
		$url = (Mappress::$options->country) ? $url . "&region=" . Mappress::$options->country : $url;
		$url = (Mappress::$options->language) ? $url . "&language=" . Mappress::$options->language : $url;
		$url .= "&key=$apikey";

		$json = self::get_json($url, array('sslverify' => false));
		if (is_wp_error($json))
			return $json;

		// If query limit is reached, wait a few seconds and try again
		$status = isset($json->status) ? $json->status : null;
		if ($status == 'OVER_QUERY_LIMIT')
			return -1;

		// Check status
		if ($status && $status != 'OK')
			return new WP_Error('google', sprintf(__("Invalid status: %s, %s Address: %s", 'mappress-google-maps-for-wordpress'), $json->status, (isset($json->error_message)) ? $json->error_message : '', $address));

		// Discard empty results
		foreach((array)$json->results as $key=>$result) {
			if(empty($result->formatted_address))
				unset($json->results[$key]);
		}

		// Error if no non-empty results
		if (!$json  || !isset($json->results) || !isset($json->results[0]) || empty($json->results[0]))
			return new WP_Error('google', sprintf(__('No results for address: %s', 'mappress-google-maps-for-wordpress'), $address));

		// Return first result
		$placemark = $json->results[0];
		$location = (object) array(
			'formatted_address' => $placemark->formatted_address,
			'lat' => $placemark->geometry->location->lat,
			'lng' => $placemark->geometry->location->lng,
			'viewport' => null
		);

		if (isset($placemark->geometry->viewport)) {
			$location->viewport = array(
				'sw' => array('lat' => $placemark->geometry->viewport->southwest->lat, 'lng' => $placemark->geometry->viewport->southwest->lng),
				'ne' => array('lat' => $placemark->geometry->viewport->northeast->lat, 'lng' => $placemark->geometry->viewport->northeast->lng)
			);
		}

		return $location;
	}

	static function geocodio($address) {
	}

	static function mapbox($address) {
		$language = (Mappress::$options->language) ? Mappress::$options->language : null;
		$url = "https://api.mapbox.com/geocoding/v5/mapbox.places/" . urlencode($address) . ".json?access_token=" . Mappress::$options->mapbox;
		$url = (Mappress::$options->country) ? $url . "&country=" . strtolower(Mappress::$options->country) : $url;
		$url = ($language) ? $url . "&language=" . strtolower($language) : $url;

		$args = array('sslverify' => false);
		$json = self::get_json($url, $args);
		if (is_wp_error($json))
			return $json;

		// Error if no results
		if (empty($json) || !isset($json->features) || empty($json->features))
			return new WP_Error('mapbox', sprintf(__('No results for address: %s', 'mappress-google-maps-for-wordpress'), $address));

		// First result
		$place = $json->features[0];

		// Format as a placemark
		$location = (object) array(
			'formatted_address' => $place->place_name,
			'lat' => $place->center[1],
			'lng' => $place->center[0],
			'viewport' => null
		);

		if (isset($place->bbox) && count($place->bbox) == 4) {
			$location->viewport = array(
				'sw' => array('lng' => $place->bbox[0], 'lat' => $place->bbox[1]),
				'ne' => array('lng' => $place->bbox[2], 'lat' => $place->bbox[3])
			);
		}
		return $location;
	}

	static function nominatim($address) {
		// Default to english, otherwise Algolia returns ALL languages
		$language = (Mappress::$options->language) ? Mappress::$options->language : 'en';
		$url = "https://nominatim.openstreetmap.org/search?format=json&limit=1&q=" . urlencode($address);
		$url = (Mappress::$options->country) ? $url . "&countrycodes=" . strtolower(Mappress::$options->country) : $url;
		$url = ($language) ? $url . "&accept-language=" . strtolower($language) : $url;

		$args = array('sslverify' => false);
		$json = self::get_json($url, $args);
		if (is_wp_error($json))
			return $json;

		// Error if no results
		if (empty($json))
			return new WP_Error('algolia', sprintf(__('No results for address: %s', 'mappress-google-maps-for-wordpress'), $address));

		// First result
		$place = $json[0];

		// Format as a placemark
		$location = (object) array(
			'formatted_address' => $place->display_name,
			'lat' => $place->lat,
			'lng' => $place->lon,
			'viewport' => null
		);

		if (isset($place->boundingbox) && count($place->boundingbox) == 4) {
			$location->viewport = array(
				'sw' => array('lat' => $place->boundingbox[0], 'lng' => $place->boundingbox[2]),
				'ne' => array('lat' => $place->boundingbox[1], 'lng' => $place->boundingbox[3])
			);
		}
		return $location;
	}

	static function get_json($url, $args = array()) {
		$response = wp_remote_get($url, $args);

		if (is_wp_error($response))
			return $response;

		if ($response['response']['code'] != 200)
			return new WP_Error('geocode', sprintf(__('Error: %s %s', 'mappress-google-maps-for-wordpress'), $response['response']['code'], $response['response']['message']));

		$json = json_decode($response['body']);

		if ($json === null || $json === false)
			return new WP_Error('geocode', sprintf(__('Invalid JSON from Geocoding service: %s', 'mappress-google-maps-for-wordpress'), $response['body']));

		return $json;
	}

	static function parse_address($address) {
		// USA Addresses - Google / Nominatim
		$address = str_replace(array(', United States of America', ', USA'), '', $address);

		// Nominatim writes street # followed by a comma ("100, main street, clevelend") - try to remove that first comma if present
		// (see if there's a numeric first part just before the first comma, if so remove the comma)
		$first_comma = strpos($address, ",");
		$first_part = substr($address, 0, $first_comma);
		if (is_numeric($first_part) && $first_comma !== false)
			$address = $first_part . substr($address, $first_comma + 1);

		// If 0 or 1 remaining commas then use a single line, e.g. "Paris, France" or "Ohio"
		// Otherwise return first line up to first comma, second line after, e.g. "Paris, France" => "Paris<br>France"
		if (!strpos($address, ','))
			return array($address);

		return array(substr($address, 0, strpos($address, ",")), trim(substr($address, strpos($address, ",") + 1)));
	}

	static function string_to_latlng($address) {
		$latlng = explode(',', $address);
		if (count($latlng) == 2 && (string)(float)$latlng[0] === trim($latlng[0]) && (string)(float)$latlng[1] === trim($latlng[1]) )
			return (object) array('lat' => $latlng[0], 'lng' => $latlng[1]);
		return false;
	}
}
?>