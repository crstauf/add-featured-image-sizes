<?php
/*
Plugin Name: Add Featured Image Sizes
Plugin URI: http://www.calebstauffer.com
Description: Adjusts image sizes of Post Thumbnails, a.k.a. Featured Images
Version: 0.0.2
Author: Caleb Stauffer
Author URI: http://www.calebstauffer.com
*/

if (is_admin()) new add_featured_image_sizes;

class add_featured_image_sizes {

	public static $sizes = false;
	public static $criterias = false;

	function __construct() {
		self::$sizes = new stdClass();
		self::$criterias = new stdClass();
		self::$criterias->and = new stdClass();
		self::$criterias->or = new stdClass();

		add_action('wp_ajax_set-post-thumbnail',array(__CLASS__,'generate_image_size'),1);
	}

	public static function add($w,$h,$crop,$name,$criteria = false,$and = '') {
		if (!is_admin()) return;
		/*$criteria = array(
			'post_id'				=> 0,		// post ID
			'post_name'				=> '',		// slug
			'post_template'			=> '',		// page template
			'post_ancestor'			=> 0,		// post ancestor ID
			'post_ancestor_name'	=> '',		// post ancestor slug
			'post_parent'			=> 0,		// post parent ID
			'post_parent_name'		=> '',		// post parent slug
			'post_format'			=> '',
			'post_type'				=> '',
		);*/

		$size = new stdClass();
		$size->width = $w;
		$size->height = $h;
		$size->crop = $crop;
		$size->name = $name;

		if (false !== $criteria && is_array($criteria) && count($criteria)) {

			if ('' === $and) {
				if (1 < count($criteria)) $and = true;
				else $and = false;
			}

			$criteria = array_change_key_case($criteria);

			if (array_key_exists('id',$criteria)) {
				$criteria['post_id'] = $criteria['id'];
				unset($criteria['id']);
			}

			if (array_key_exists('slug',$criteria)) {
				$criteria['post_name'] = $criteria['slug'];
				unset($criteria['slug']);
			}

			foreach (array('post_id','post_ancestor','post_parent') as $test)
				if (array_key_exists($test,$criteria) && !is_bool($criteria[$test]))
					$criteria[$test] = intval($criteria[$test]);
			$size->criteria = $criteria;

		}

		$size->relation = $and;

		self::$sizes->$name = $size;

		if (false === $and)
			foreach ($criteria as $k => $v) {
				if (!isset(self::$criterias->or->$k))
					self::$criterias->or->$k = new stdClass();
				if (!isset(self::$criterias->or->$k->$v))
					self::$criterias->or->$k->$v = array();
				self::$criterias->or->$k->{$v}[] = $name;
			}
		else self::$criterias->and->$name = $criteria;
	}

	public static function generate_image_size($thumbnail_id = false,$size = false,$post_id = false) {
		if (!count(self::$sizes)) return true;
		
		if (false === $post_id && array_key_exists('post_id',$_POST))
			$post_id = intval($_POST['post_id']);

		if (in_array($thumbnail_id,array(0,'',false),true) && array_key_exists('thumbnail_id',$_POST))
			$thumbnail_id = intval($_POST['thumbnail_id']);
		else if (!is_bool($thumbnail_id))
			$thumbnail_id = intval($thumbnail_id);

		if (!isset($thumbnail_id) || false === $thumbnail_id || -1 == $thumbnail_id)
			return;

		$orig_meta = $meta = wp_get_attachment_metadata($thumbnail_id);
		$orig_backupsizes = $backupsizes = get_post_meta($thumbnail_id,'_wp_attachment_backup_sizes',true);
		$path = apply_filters('image_make_intermediate_size',get_attached_file($thumbnail_id));
		$dir = substr($path,0,strrpos($path,'/'));

		if (false !== $size) $sizes = array($size);
		else $sizes = apply_filters('featured_image_sizes_sizes',self::get_sizes($post_id),$thumbnail_id,$post_id,$size);

		foreach ($sizes as $name) {
			$size = self::$sizes->$name;

			if (
				!isset($meta['sizes'][$name]) || 
				$meta['sizes'][$name]['width'] != $size->width || 
				$meta['sizes'][$name]['height'] != $size->height || 
				!file_exists($dir . '/' . $meta['sizes'][$name]['file'])
			)
				if ($newsize = image_make_intermediate_size($path,$size->width,$size->height,$size->crop)) {
					$meta['sizes'][$name] = $newsize;
					if (false !== $backupsizes)
						$backupsizes[$name] = $newsize;
				}
		}

		if ($meta !== $orig_meta)
			update_post_meta($thumbnail_id,'_wp_attachment_metadata',$meta);

		if (false !== $backupsizes && $backupsizes !== $orig_backupsizes)
			update_post_meta($thumbnail_id,'_wp_attachment_backup_sizes',$backupsizes);

		return true;
	}

	public static function get_sizes($post_id) {
		$post = get_post($post_id);

		foreach (apply_filters('featured_image_sizes_criteria',array(
			'post_id',
			'post_name',
			'post_template',
			'post_ancestor',
			'post_ancestor_name',
			'post_parent',
			'post_parent_name',
			'post_format',
			'post_type',
		)) as $criteria)
			if (property_exists(self::$criterias->or,$criteria))
				$$criteria = apply_filters('featured_image_size_post_data',self::get_post_data($criteria,$post),$criteria,$post);

		$return = array();

		if (count(self::$criterias->or))
			foreach (self::$criterias->or as $k => $entities)
				foreach ($entities as $name => $sizes)
					if (isset($$k) && $$k == $name) $return = array_merge($return,$sizes);

		if (count(self::$criterias->and))
			foreach (self::$criterias->and as $name => $criteria) {
				foreach ($criteria as $k => $v) {
					if (!isset($$k)) $$k = self::get_post_data($k,$post);
					if ('post_ancestor_name' == $k) {
						foreach ($$k as $ancestor_name)
							if ($v == $ancestor_name) break 2;
					} else if (
						(
							'post_parent' == $k && 
							(
								(true === $v && !in_array($$k,array(0,''))) || 
								(false === $v && in_array($$k,array(0,'')))
							)
						) || (
							'post_ancestor' == $k && 
							(
								(true === $v && count($$k)) || 
								(false === $v && !count($$k))
							)
						)
					) break;
					if ($$k !== $v) continue 2;
				}
				$return = array_merge($return,array($name));
			}

		return $return;
	}

	public static function get_post_data($data,$post) {
		switch ($data) {

			case 'post_name':
				return $post->post_name;

			case 'post_type':
				return get_post_type($post);

			case 'post_format':
				return get_post_format($post);

			case 'post_ancestor':
			case 'post_ancestor_name':
				$post_ancestor = get_post_ancestors($post);
				if ('post_ancestor' == $data) return $post_ancestor;
				if (false !== $post_ancestor && is_array($post_ancestor) && count($post_ancestor))
					foreach ($post_ancestor as $ancestor_id) {
						$ancestor = get_post($ancestor_id);
						$post_ancestor_name[] = $ancestor->post_name;
					}
				return $post_ancestor_name;

			case 'post_parent':
				return $post->post_parent;

			case 'post_parent_name':
				$temp = get_post($post->post_parent);
				return $temp->post_name;

			case 'post_template':
				return get_page_template_slug($post_id);

			default:
				return false;

		}
	}

}

function add_featured_image_size($w,$h,$c,$name,$criteria = false,$and = '') {
	add_featured_image_sizes::add($w,$h,$c,$name,$criteria,$and);
}

?>