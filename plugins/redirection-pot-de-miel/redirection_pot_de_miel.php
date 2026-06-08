<?php
/*
Plugin Name: Redirection Pot de Miel
Description: Gère la redirection automatique avec pot de miel et la génération des pages de redirection.
Version: 1.0
Author: FLegDevFr
*/

// Sécurité : empêche l'accès direct
if (!defined('ABSPATH')) exit;

// Inclusions
require_once plugin_dir_path(__FILE__) . 'includes/core.php';
require_once plugin_dir_path(__FILE__) . 'includes/pages.php';
require_once plugin_dir_path(__FILE__) . 'includes/seo.php';
