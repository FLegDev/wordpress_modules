<?php

	/*
	 * Plugin Name: Melody2WP
	 * Plugin URI: https://www.cosavostra.com
	 * Description: Envoi des articles Melody vers WordPress
	 * Author: CosaVostra
	 * Version: 1.0
	 * Author URI: https://www.cosavostra.com
	*/

// !!! Penser à regénérer les permaliens après l'activation de l'extension (Réglages > Permaliens) !!!

// Mise en place des URL du type https://mon-wp.com/new-melody-article/1234/

	add_action('init', function() {
		add_rewrite_rule('^non-classifiee/preview-melody/(\d+)/?$', 'index.php?preview_melody_id=$matches[1]', 'top');
	});

	add_filter( 'generate_rewrite_rules', function ( $wp_rewrite ) {
		$wp_rewrite->rules = array_merge(
			[ 'new-melody-article/(\d+)/?$' => 'index.php?article_id=$matches[1]' ],
			$wp_rewrite->rules
		);
	} );

	add_filter( 'query_vars', function ( $query_vars ) {
		$query_vars[] = 'article_id';
		$query_vars[] = 'preview_melody_id';

		return $query_vars;
	} );

	add_filter('template_include', function($template) {
		if (get_query_var('preview_melody_id')) {
			return plugin_dir_path(__FILE__) . 'preview_melody_article.php';
		}
		return $template;
	});

	add_action( 'template_redirect', function () {
		$custom = intval( get_query_var( 'article_id' ) );
		if ( $custom ) {
			include plugin_dir_path( __FILE__ ) . 'new_melody_article.php';
			die;
		}
	} );