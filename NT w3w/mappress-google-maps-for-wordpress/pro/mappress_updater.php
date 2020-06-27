<?php
class Mappress_Updater {
	var $api_server,
		$basename,
		$betas,
		$license,
		$plugin,
		$usage,
		$version
		;

	function __construct($basename, $plugin, $version, $license, $betas) {
		$this->api_server = 'https://mappresspro.com';
		$this->basename = $basename;
		$this->betas = $betas;
		$this->plugin = $plugin;
		$this->version = $version;
		$this->license = $license;

		// Pad version for version compare
		$periods = 3 - (count(explode('.', $this->version)));
		for ($i = 0; $i < $periods; $i++)
			$this->version .= '.0';

		add_filter('pre_set_site_transient_update_plugins', array($this, 'pre_set_site_transient_update_plugins'));
		add_filter('plugins_api', array($this, 'plugins_api'), 10, 3);
	}

	// Get plugin info
	// WP filter -from plugins_api() function [/includes/plugin-install.php] for API call '/plugins/info'
	function plugins_api($data, $action = '', $args = null) {

		// Only respond to calls for our plugin
		if ($action != 'plugin_information' || !isset($args->slug) || $args->slug != dirname($this->basename))
			return $data;

		$result = $this->api_call('info');

		// On error, return an empty object
		if (is_wp_error($result))
			return (object) array('sections' => array());

		// Success, but WP wants 'sections' as an array
		if ($result && $result->sections)
			$result->sections = (array) $result->sections;

		// Remove package if version up to date; note: info uses 'version' while download uses 'new_version'
		if (version_compare($this->version, $result->version, '>='))
			unset($result->download_link);

		return $result;
	}

	/**
	* WP filter - called TWICE by wp_update_plugins() [/includes/update.php] during API call '/plugins/update-check'
	* Used to fetch current version info and download link
	*/
	function pre_set_site_transient_update_plugins($transient) {
		global $pagenow;

		// Check WP transient is not empty and has an entry for our plugin
		if (!$transient || !is_object($transient))
			return $transient;

		// Fetch current version
		$result = $this->api_call('version');

		// Clear WP results
		unset($transient->checked[$this->basename], $transient->response[$this->basename], $transient->no_update[$this->basename]);

		// Error
		if (!$result || is_wp_error($result))
			return $transient;

		// Inject our results into WP transient
		$transient->checked[$this->basename] = $result->new_version;
		if (version_compare($this->version, $result->new_version, '<'))
			$transient->response[$this->basename] = $result;
		else
			$transient->no_update[$this->basename] = $result;

		return $transient;
	}

	/**
	* API Call
	*
	* @param mixed $action - 'version' or 'info'
	* @param mixed $wp_args
	*/
	function api_call($action, $cache = true) {
		// Cache is used because WP updates its own transient frequently, even when no API call is needed
		if ($cache) {
			$cache_key = $this->get_cache_key($action);
			$cached = get_site_option($cache_key);
			if ($cached && $cached->time > time())
				return $cached;
		}

		// Call API
		$url = $this->api_server;
		$args = array(
			'api_action' => $action,
			'basename' => $this->basename,
			'betas' => $this->betas,
			'license' => $this->license,
			'network_url' => (is_multisite()) ? trim(network_home_url()) : trim(home_url()),
			'plugin' => $this->plugin,
			'slug' => dirname($this->basename),
			'url' => trim(home_url()),
			'usage' => (Mappress::is_localhost()) ? null : Mappress_Pro_Settings::get_usage()
		);
		$response = wp_remote_post($url, array('timeout' => 15, 'sslverify' => false, 'body' => (array) $args));

		if (is_wp_error($response))
			$result = new WP_Error('error', __('Communication error', 'mappress-google-maps-for-wordpress'). ' : ' . $response->get_error_message());
		else {
			$json = json_decode(wp_remote_retrieve_body($response));
			if ($json)
				$result = $json;
			else
				$result = (Mappress::$debug) ? new WP_Error('error', print_r($response, true)) : new WP_Error('error', __('JSON error', 'mappress-google-maps-for-wordpress'));
		}

		// Set transient: 'version' = 8 hours, 'info' = 15 minutes
		$result->time =  ($action == 'version') ? time() + 60 * 60 * 8 : time() + 60 * 15;
		update_site_option($cache_key, $result);
		return $result;
	}

	function get_status() {
		$result = $this->api_call('version');
		return (is_wp_error($result)) ? $result : $result->status;
	}

	function delete_cache() {
		foreach(array('version', 'info') as $action)
			delete_site_option($this->get_cache_key($action));
	}

	function get_cache_key($action) {
		return $this->plugin . '_updater_' . $action;
	}

	function check($license, $betas) {
		// Deleted 2.52.4
		//		if ($license == $this->license && $betas == $this->betas)
		//			return;
		$this->delete_cache();
		wp_clean_plugins_cache(true);
	}
}