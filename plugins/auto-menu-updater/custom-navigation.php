<?php
/**
 * Plugin Name: Auto Menu Updater
 * Description: Met à jour automatiquement les menus de navigation en fonction des catégories, sous-catégories et articles publiés ou supprimés.
 * Version: 1.0
 * Author: flegdevfr
 */

 
function custom_menu_categories() {
    // Récupérer les catégories de premier niveau en excluant "Uncategorized"
    $categories = get_categories(array(
        'orderby' => 'name',
        'parent' => 0,
        'exclude' => get_cat_ID('Uncategorized') // Exclure la catégorie "Uncategorized"
    ));
    
    // Vérifier si le menu existe déjà
    $menu_name = 'Custom Menu'; // Nom de votre menu
    $menu_exists = wp_get_nav_menu_object($menu_name);
    
    if (!$menu_exists) {
        // Si le menu n'existe pas, le créer
        $menu_id = wp_create_nav_menu($menu_name);
    } else {
        // Si le menu existe, récupérer son ID
        $menu_id = $menu_exists->term_id;
        
        // Supprimer tous les éléments de menu existants pour le reconstruire
        $existing_menu_items = wp_get_nav_menu_items($menu_id);
        if ($existing_menu_items) {
            foreach ($existing_menu_items as $item) {
                wp_delete_post($item->ID);
            }
        }
    }
    
    // Ajouter les catégories et leurs sous-catégories au menu
    foreach ($categories as $category) {
        // Ajouter la catégorie principale au menu
        $item_id = wp_update_nav_menu_item($menu_id, 0, array(
            'menu-item-title' => $category->name,
            'menu-item-object-id' => $category->term_id,
            'menu-item-object' => 'category',
            'menu-item-status' => 'publish'
        ));
        
        // Récupérer les sous-catégories de la catégorie principale
        $subcategories = get_categories(array(
            'orderby' => 'name',
            'parent' => $category->term_id
        ));
        
        // Ajouter les sous-catégories et leurs articles au menu
        foreach ($subcategories as $subcategory) {
            // Ajouter la sous-catégorie au menu
            $sub_item_id = wp_update_nav_menu_item($menu_id, 0, array(
                'menu-item-title' => $subcategory->name,
                'menu-item-object-id' => $subcategory->term_id,
                'menu-item-object' => 'category',
                'menu-item-parent-id' => $item_id,
                'menu-item-status' => 'publish'
            ));
            
            // Récupérer les articles de la sous-catégorie
            $posts = get_posts(array(
                'category' => $subcategory->term_id,
                'posts_per_page' => -1
            ));
            
            // Ajouter les articles au menu
            foreach ($posts as $post) {
                wp_update_nav_menu_item($menu_id, 0, array(
                    'menu-item-title' => $post->post_title,
                    'menu-item-url' => get_permalink($post->ID),
                    'menu-item-parent-id' => $sub_item_id,
                    'menu-item-status' => 'publish'
                ));
            }
        }
    }
}
add_action('init', 'custom_menu_categories');
