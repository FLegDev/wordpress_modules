<?php

	function melody_render_children_html($xml) {
		$children_html = '';
		if (!empty($xml->children) && isset($xml->children->child)) {
			$children_html = '<div class="children_post">';
			foreach ($xml->children->child as $child) {
				$child_id = isset($child->id) ? (string)$child->id : '';
				$fallback_title = isset($child->title) && !empty((string)$child->title)
					? (string)$child->title
					: (isset($child->title_raw) ? (string)$child->title_raw : '');
				$fallback_title = trim(strip_tags(html_entity_decode($fallback_title)));
				$post_link = '';
				$wp_title = '';
				if (!empty($child_id)) {
					$query = new WP_Query([
						'post_type'   => 'post',
						'meta_key'    => 'melody_id',
						'meta_value'  => $child_id,
						'post_status' => 'publish',
						'fields'      => 'ids',
						'posts_per_page' => 1
					]);
					if (!empty($query->posts)) {
						$child_post_id = $query->posts[0];
						$post_link = get_permalink($child_post_id);
						$wp_title = get_the_title($child_post_id);
					}
					wp_reset_postdata();
				}
				if (!empty($post_link) && !empty($wp_title)) {
					$children_html .= '<li class="children_item"><a href="' . esc_url($post_link) . '">' . esc_html($wp_title) . '</a></li>';
				} else {
					$children_html .= '<li class="children_item">' . esc_html($fallback_title) . '</li>';
				}
			}
			$children_html .= '</ul></div>';
		}
		return $children_html;
	}