<?php
/*
Plugin Name: More Image Sizes
Plugin URI: http://www.calebstauffer.com
Description: Adjusts image sizes of special images, mainly Post Thumbnails, a.k.a. Featured Images
Version: 0.0.1
Author: Caleb Stauffer
Author URI: http://www.calebstauffer.com
*/

new css_mis();

class css_mis {

	public static $sizes = false;
	public static $criteria = array();

	function __construct() {
		self::$sizes = new stdClass();

		self::$criteria = array(
			'ID',
			'post_name', // slug
			'post_template', // page template
			'post_parent', // post's parent
			'post_format',
			'post_type',
		);

		self::hooks();
	}

		public static function add_image_size($criteria,$w,$h,$crop,$name = '') {
			$size = new stdClass();

			if (!is_array($criteria)) {
				if ('' == $name) $name = $criteria;
				$size->criteria['post_type'] = $criteria;
			} else if ('' == $name) {
				throw new Exception('Image Size requires a name!');
				return new WP_Error('css_mis','Image Size requires a name!');
			} else {
				foreach ($criteria as $k => $v) {
					if (!in_array($k,self::$criteria)) {
						unset($criteria[$k]);
						continue;
					}
				}
				$size->criteria = $criteria;
			}

			$size->dims = array(
				'width' => $w,
				'height' => $h,
			);

			$size->crop = $crop;

			self::$sizes->$name = $size;
		}

	public static function hooks() {
		add_action('wp_ajax_set-post-thumbnail',array(__CLASS__,'generate'),1);
	}

	public static function generate($thumbnail_id = false,$size = false,$post_id = false) {
		if (false === $post_id && isset($_POST['post_id'])) {
			$post_id = intval($_POST['post_id']);
			if (false !== $size) $size = get_post_type($post_id);
		}

		if (!$thumbnail_id) $thumbnail_id = intval($_POST['thumbnail_id']);
		if (!isset($thumbnail_id) || !$thumbnail_id || -1 == $thumbnail_id) return;

		$orig_meta = $meta = wp_get_attachment_metadata($thumbnail_id);
		$orig_backupsizes = $backupsizes = get_post_meta($thumbnail_id,'_wp_attachment_backup_sizes',true);

		$path = apply_filters('image_make_intermediate_size',get_attached_file($thumbnail_id));
		$dir = substr($path,0,strrpos($path,'/'));

		if (false !== $size && isset(self::$sizes->$size)) $sizes = self::$sizes->$size;
		else $sizes = self::get_sizes($post_id);

		foreach ($sizes as $name) {
			$size = self::$sizes->$name;

			if (
				!isset($meta['sizes']['mis_'.$name]) || 
				$meta['sizes']['mis_'.$name]['width'] != $size->dims['width'] || 
				$meta['sizes']['mis_'.$name]['height'] != $size->dims['height'] || 
				!file_exists($dir . '/' . $meta['sizes']['mis_' . $name]['file'])
			)
				if ($newsize = image_make_intermediate_size($path,$size->dims['width'],$size->dims['height'],$size->crop)) {
					$meta['sizes']['mis_'.$name] = $newsize;
					if (false !== $backupsizes) $backupsizes['mis_'.$name] = $newsize;
				}
		}

		if ($meta != $orig_meta) update_post_meta($thumbnail_id,'_wp_attachment_metadata',$meta);
		if (false !== $backupsizes && $backupsizes != $orig_backupsizes) update_post_meta($thumbnail_id,'_wp_attachment_backup_sizes',$backupsizes);

		return true;
	}

		public static function get_sizes($post_id) {
			$post = get_post($post_id);
			$matches = array();

			foreach (self::$sizes as $name => $size) {
				$fail = false;
				if (isset($size->criteria) && is_array($size->criteria)) {
					foreach ($size->criteria as $criteria => $value) {
						if ('post_template' == $criteria) {
							if (!$meta = get_post_meta($post_id,'_wp_page_template',true)) $fail = true;
							if ($meta != $value) $fail = true;
						} else if ('post_format' == $criteria && get_post_format($post) != $value) $fail = true;
						else if (isset($post->$criteria) && $post->$criteria != $value) $fail = true;
						if (true === $fail) break;
					}
					if (false === $fail) $matches[] = $name;
				}
			}

			return $matches;
		}

}

if (!class_exists('mis')) {
	class mis extends css_mis {
		function __construct() { }
	}
}
