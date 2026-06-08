<?php
/**
 * Plugin Name:       Parresia User Switcher
 * Plugin URI:        https://github.com/parresia
 * Description:       Permet aux administrateurs de basculer vers n'importe quel compte utilisateur sans connaître son mot de passe, et de revenir en un clic.
 * Version:           1.0.0
 * Author:            François / PARRESIA
 * License:           GPL-2.0-or-later
 * Text Domain:       parresia-user-switcher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ──────────────────────────────────────────────
// 1. COLONNE + LIEN "Switch To" dans la liste des utilisateurs
// ──────────────────────────────────────────────

add_filter( 'user_row_actions', 'pus_user_row_actions', 10, 2 );
function pus_user_row_actions( $actions, $user ) {
	if ( ! current_user_can( 'administrator' ) ) {
		return $actions;
	}
	if ( $user->ID === get_current_user_id() ) {
		return $actions;
	}

	$url = wp_nonce_url(
		add_query_arg(
			[
				'action'  => 'pus_switch_to',
				'user_id' => $user->ID,
			],
			admin_url( 'users.php' )
		),
		'pus_switch_to_' . $user->ID
	);

	$actions['pus_switch'] = sprintf(
		'<a href="%s" style="color:#d63638;font-weight:600;">🔀 Switch To</a>',
		esc_url( $url )
	);

	return $actions;
}

// ──────────────────────────────────────────────
// 2. ACTION : effectuer le switch
// ──────────────────────────────────────────────

add_action( 'admin_action_pus_switch_to', 'pus_do_switch_to' );
function pus_do_switch_to() {
	// Vérification des droits
	if ( ! current_user_can( 'administrator' ) ) {
		wp_die( __( 'Accès refusé : vous devez être administrateur.', 'parresia-user-switcher' ) );
	}

	$target_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
	if ( ! $target_id ) {
		wp_die( __( 'Utilisateur invalide.', 'parresia-user-switcher' ) );
	}

	check_admin_referer( 'pus_switch_to_' . $target_id );

	$target_user = get_user_by( 'id', $target_id );
	if ( ! $target_user ) {
		wp_die( __( 'Utilisateur introuvable.', 'parresia-user-switcher' ) );
	}

	$admin_id = get_current_user_id();

	// Stocke l'admin original dans la meta du compte cible
	update_user_meta( $target_id, '_pus_switched_from', $admin_id );
	// Stocke aussi le timestamp pour traçabilité
	update_user_meta( $target_id, '_pus_switched_at', current_time( 'mysql' ) );

	// Journalise dans le log erreurs WP (debug.log si WP_DEBUG_LOG est actif)
	if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
		error_log( sprintf(
			'[PUS] Admin #%d (%s) a switché vers utilisateur #%d (%s) — %s',
			$admin_id,
			wp_get_current_user()->user_login,
			$target_id,
			$target_user->user_login,
			current_time( 'mysql' )
		) );
	}

	// Bascule la session
	wp_clear_auth_cookie();
	wp_set_current_user( $target_id );
	wp_set_auth_cookie( $target_id, false );

	wp_safe_redirect( admin_url() );
	exit;
}

// ──────────────────────────────────────────────
// 3. BARRE ADMIN : bouton "Switch Back"
// ──────────────────────────────────────────────

add_action( 'admin_bar_menu', 'pus_admin_bar_switch_back', 999 );
function pus_admin_bar_switch_back( $wp_admin_bar ) {
	$current_id    = get_current_user_id();
	$switched_from = get_user_meta( $current_id, '_pus_switched_from', true );

	if ( ! $switched_from ) {
		return;
	}

	$admin = get_user_by( 'id', (int) $switched_from );
	if ( ! $admin ) {
		return;
	}

	$url = wp_nonce_url(
		add_query_arg(
			[
				'action'  => 'pus_switch_back',
				'back_to' => (int) $switched_from,
			],
			admin_url( 'users.php' )
		),
		'pus_switch_back_' . (int) $switched_from
	);

	$wp_admin_bar->add_node( [
		'id'    => 'pus-switch-back',
		'title' => '↩ Retour sur : <strong>' . esc_html( $admin->user_login ) . '</strong>',
		'href'  => esc_url( $url ),
		'meta'  => [
			'class' => 'pus-switch-back',
			'title' => 'Revenir sur le compte administrateur',
		],
	] );
}

// ──────────────────────────────────────────────
// 4. ACTION : switch back
// ──────────────────────────────────────────────

add_action( 'admin_action_pus_switch_back', 'pus_do_switch_back' );
function pus_do_switch_back() {
	$current_id  = get_current_user_id();
	$back_to     = isset( $_GET['back_to'] ) ? absint( $_GET['back_to'] ) : 0;

	if ( ! $back_to ) {
		wp_die( __( 'Cible invalide.', 'parresia-user-switcher' ) );
	}

	check_admin_referer( 'pus_switch_back_' . $back_to );

	// Vérifie que la cible est bien l'admin stocké en meta (anti-falsification)
	$stored = (int) get_user_meta( $current_id, '_pus_switched_from', true );
	if ( $stored !== $back_to ) {
		wp_die( __( 'Session invalide. Tentative de switch non autorisée.', 'parresia-user-switcher' ) );
	}

	$admin = get_user_by( 'id', $back_to );
	if ( ! $admin || ! user_can( $admin, 'administrator' ) ) {
		wp_die( __( 'La cible de retour n\'est pas un administrateur valide.', 'parresia-user-switcher' ) );
	}

	// Nettoie les metas
	delete_user_meta( $current_id, '_pus_switched_from' );
	delete_user_meta( $current_id, '_pus_switched_at' );

	if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
		error_log( sprintf(
			'[PUS] Retour de l\'utilisateur #%d vers admin #%d (%s) — %s',
			$current_id,
			$back_to,
			$admin->user_login,
			current_time( 'mysql' )
		) );
	}

	wp_clear_auth_cookie();
	wp_set_current_user( $back_to );
	wp_set_auth_cookie( $back_to, false );

	wp_safe_redirect( admin_url( 'users.php' ) );
	exit;
}

// ──────────────────────────────────────────────
// 5. STYLES : colore le bouton Switch Back dans la admin bar
// ──────────────────────────────────────────────

add_action( 'admin_head', 'pus_admin_styles' );
add_action( 'wp_head', 'pus_admin_styles' ); // pour la toolbar en front
function pus_admin_styles() {
	$current_id    = get_current_user_id();
	$switched_from = get_user_meta( $current_id, '_pus_switched_from', true );

	if ( ! $switched_from ) {
		return;
	}
	?>
	<style>
		#wpadminbar #wp-admin-bar-pus-switch-back > .ab-item {
			background-color: #d63638 !important;
			color: #fff !important;
			font-weight: 600;
			padding: 0 12px;
		}
		#wpadminbar #wp-admin-bar-pus-switch-back > .ab-item:hover {
			background-color: #b32d2e !important;
		}
		/* Bannière d'alerte dans le footer admin */
		#pus-alert-banner {
			position: fixed;
			bottom: 0; left: 0; right: 0;
			background: #d63638;
			color: #fff;
			text-align: center;
			padding: 8px;
			font-size: 13px;
			font-weight: 600;
			z-index: 99999;
		}
	</style>
	<?php
}

// ──────────────────────────────────────────────
// 6. BANNIÈRE BAS DE PAGE dans l'admin quand on est switché
// ──────────────────────────────────────────────

add_action( 'admin_footer', 'pus_admin_footer_banner' );
function pus_admin_footer_banner() {
	$current_id    = get_current_user_id();
	$switched_from = get_user_meta( $current_id, '_pus_switched_from', true );
	$switched_at   = get_user_meta( $current_id, '_pus_switched_at', true );

	if ( ! $switched_from ) {
		return;
	}

	$admin = get_user_by( 'id', (int) $switched_from );
	$login_name = $admin ? esc_html( $admin->user_login ) : '#' . $switched_from;

	$back_url = wp_nonce_url(
		add_query_arg(
			[
				'action'  => 'pus_switch_back',
				'back_to' => (int) $switched_from,
			],
			admin_url( 'users.php' )
		),
		'pus_switch_back_' . (int) $switched_from
	);

	printf(
		'<div id="pus-alert-banner">⚠️ Vous naviguez en tant que <strong>%s</strong> (switché depuis <strong>%s</strong> le %s) — <a href="%s" style="color:#fff;text-decoration:underline;">↩ Revenir sur %s</a></div>',
		esc_html( wp_get_current_user()->user_login ),
		$login_name,
		esc_html( $switched_at ),
		esc_url( $back_url ),
		$login_name
	);
}
