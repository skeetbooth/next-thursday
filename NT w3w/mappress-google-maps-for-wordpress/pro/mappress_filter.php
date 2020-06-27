<?php
class Mappress_Filter extends Mappress_Obj {

	public
		$key,
		$format,
		$label,
		$type,
		$meta_type,
		$values = array()
	;

	function __construct($atts) {
		$this->update($atts);
		$this->type = ($this->type) ? $this->type : $this->get_type();
	}

	function get_html() {
		$html = '';

		// Output hidden fields for atts
		foreach(array('key', 'type', 'meta_type') as $att) {
			if ($this->$att)
				$html .= "<input type='hidden' name='{$this->key}[$att]' value='{$this->$att}'>";
		}

		// Output values
		$values = ($this->values) ? $this->values : $this->get_values();

		switch($this->format) {
			case 'select' :
				$html .= Mappress_Controls::select($this->key, $values, null, array('none' => true));
				break;

			case 'checkboxes' :
			default :
				// Remove mdash for hierarchical lists when displaying checkboxes
				foreach($values as &$value)
					$value = str_ireplace('&mdash;', '', $value);
				$this->get_icons($values);
				$html .= ($values) ? Mappress_Controls::checkboxes("{$this->key}[values]", $values) : '--';
				break;
		}
		return $html;
	}

	function get_icons(&$values) {
		$autoicons = (object) Mappress::$options->autoicons;

		// Add autoicons to the labels
		if ($autoicons && $autoicons->key == $this->key) {
			foreach($values as $value=>&$label) {
				$iconid = (isset($autoicons->values[$value])) ? $autoicons->values[$value] : null;
				if ($iconid)
					$label = "[$iconid] $label";
			}
		}

		// In each label, replace [iconid] with an image for the icon
		foreach($values as $value=>&$label)
			$label = preg_replace_callback('/[\[{\(].*[\]}\)]/U' , array(__CLASS__, 'replace_iconid', ), $label);
	}

	static function replace_iconid($iconids) {
		$iconid = (isset($iconids[0])) ? str_replace(array('[', ']'), '', $iconids[0]) : null;

		if ($iconid)
			return sprintf("<img class='mapp-icon' src='%s'/>", Mappress_Icons::get($iconid));
	}

	function get_type() {
		if (taxonomy_exists($this->key))
			return 'tax';
		else if (in_array($this->key, array('post_type')))
			return 'post';
		else
			return 'meta';
	}

	function get_label() {
		$type = ($this->type) ? $this->type : $this->get_type();
		switch($type) {
			case 'meta' :
				$label = $this->key;
				break;

			case 'tax' :
				$taxonomy = get_taxonomy($this->key);
				$label = ($this->format == 'select') ? $taxonomy->labels->singular_name : $taxonomy->label;
				break;

			case 'post' :
				$label = __('Post type', 'mappress-google-maps-for-wordpress');
				break;
		}
		return ucfirst($label);
	}

	function get_values() {
		global $wpdb;

		$values = array();

		switch($this->type) {
			case 'meta' :
				$meta_values = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT meta_value FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value != '' ORDER BY meta_value", $this->key));
				$values = ($meta_values) ? array_combine($meta_values, $meta_values) : array();
				break;

			case 'post' :
				foreach(Mappress::$options->postTypes as $type) {
					$post_type = get_post_type_object($type);
					$values[$type] = $post_type->label;
				}
				break;

			case 'tax' :
				$values = Mappress_Controls::get_terms($this->key, true);
				break;
		}

		return $values;
	}

	static function get_keys($type = 'tax') {
		global $wpdb;

		$types = (array) $type;
		$labels = array('tax' => __('Taxonomy', 'mappress-google-maps-for-wordpress'), 'post' => __('Post field', 'mappress-google-maps-for-wordpress'), 'meta' => __('Custom Field', 'mappress-google-maps-for-wordpress'));
		$keys = array();

		foreach($types as $type) {
			if (count($types) > 1)
				$keys += array("optgroup_$type" => $labels[$type]);

			switch($type) {
				case 'tax' :
					$keys += Mappress_Controls::get_taxonomies();
					break;

				case 'post' :
					$keys += array('post_type' => __('Post type', 'mappress-google-maps-for-wordpress'));
					break;

				case 'meta' :
					$keys += Mappress_Controls::get_meta_keys();
					break;
			}
		}
		return $keys;
	}
}

?>