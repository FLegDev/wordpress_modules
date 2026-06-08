<?php
/**
 * WordPress admin integration for the OMR Word Importer plugin.
 *
 * @package OMR_Word_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the admin page and handles DOCX uploads.
 */
class OMR_Word_Importer {
	private const MENU_SLUG = 'omr-word-importer';
	private const NONCE_ACTION = 'omr_word_importer_upload';
	private const NONCE_NAME = 'omr_word_importer_nonce';

	/**
	 * Register WordPress hooks.
	 */
	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_front_assets' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
	}

	/**
	 * Register the Tools submenu page.
	 */
	public function register_menu(): void {
		add_management_page(
			__( 'OMR Word Importer', 'omr-word-importer' ),
			__( 'OMR Word Importer', 'omr-word-importer' ),
			'edit_posts',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin styles only on this plugin page.
	 *
	 * @param string $hook_suffix Current admin hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'tools_page_' . self::MENU_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'omr-word-importer-admin',
			OMR_WORD_IMPORTER_URL . 'assets/admin.css',
			array(),
			OMR_WORD_IMPORTER_VERSION
		);
	}

	/**
	 * Enqueue frontend styles for imported terminal blocks.
	 */
	public function enqueue_front_assets(): void {
		wp_enqueue_style(
			'omr-word-importer-front',
			OMR_WORD_IMPORTER_URL . 'assets/admin.css',
			array(),
			OMR_WORD_IMPORTER_VERSION
		);
	}

	/**
	 * Enqueue block editor styles for editable imported blocks.
	 */
	public function enqueue_editor_assets(): void {
		wp_enqueue_style(
			'omr-word-importer-editor',
			OMR_WORD_IMPORTER_URL . 'assets/admin.css',
			array(),
			OMR_WORD_IMPORTER_VERSION
		);
	}

	/**
	 * Render the upload page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You are not allowed to import documents.', 'omr-word-importer' ) );
		}

		$result = null;

		if ( isset( $_POST[ self::NONCE_NAME ] ) ) {
			$result = $this->handle_upload_request();
		}

		?>
		<div class="wrap omr-word-importer">
			<h1><?php esc_html_e( 'OMR Word Importer', 'omr-word-importer' ); ?></h1>

			<?php $this->render_result( $result ); ?>

			<div class="omr-panel">
				<h2><?php esc_html_e( 'Importer un document Word', 'omr-word-importer' ); ?></h2>
				<p>
					<?php esc_html_e( 'Selectionne un fichier .docx. Le plugin creera un brouillon WordPress avec les titres, listes, tableaux simples, images et blocs Terminal/Code.', 'omr-word-importer' ); ?>
				</p>

				<form method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>

					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row">
									<label for="omr_docx_file"><?php esc_html_e( 'Fichier .docx', 'omr-word-importer' ); ?></label>
								</th>
								<td>
									<input id="omr_docx_file" name="omr_docx_file" type="file" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required />
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="omr_post_status"><?php esc_html_e( 'Statut cree', 'omr-word-importer' ); ?></label>
								</th>
								<td>
									<select id="omr_post_status" name="omr_post_status">
										<option value="draft"><?php esc_html_e( 'Brouillon', 'omr-word-importer' ); ?></option>
										<option value="pending"><?php esc_html_e( 'En attente de relecture', 'omr-word-importer' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="omr_terminal_styles"><?php esc_html_e( 'Styles Word terminal/code', 'omr-word-importer' ); ?></label>
								</th>
								<td>
									<input id="omr_terminal_styles" name="omr_terminal_styles" type="text" class="regular-text" value="Terminal,Code,Console,Bash,Shell,PowerShell" />
									<p class="description"><?php esc_html_e( 'Noms de styles Word a convertir en bloc terminal, separes par des virgules.', 'omr-word-importer' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="omr_toc_mode"><?php esc_html_e( 'Table des matieres', 'omr-word-importer' ); ?></label>
								</th>
								<td>
									<select id="omr_toc_mode" name="omr_toc_mode">
										<option value="auto"><?php esc_html_e( 'Automatique si le document Word contient un sommaire', 'omr-word-importer' ); ?></option>
										<option value="always"><?php esc_html_e( 'Toujours generer un sommaire', 'omr-word-importer' ); ?></option>
										<option value="none"><?php esc_html_e( 'Ne pas generer de sommaire', 'omr-word-importer' ); ?></option>
									</select>
									<p class="description"><?php esc_html_e( 'Le sommaire Word est remplace par un sommaire web cliquable, sans numeros de page.', 'omr-word-importer' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="omr_toc_placement"><?php esc_html_e( 'Emplacement du sommaire', 'omr-word-importer' ); ?></label>
								</th>
								<td>
									<select id="omr_toc_placement" name="omr_toc_placement">
										<option value="inline"><?php esc_html_e( 'Dans le contenu, apres le titre', 'omr-word-importer' ); ?></option>
										<option value="sidebar"><?php esc_html_e( 'Dans une sidebar a gauche de l article', 'omr-word-importer' ); ?></option>
									</select>
									<p class="description"><?php esc_html_e( 'La sidebar est creee avec des blocs Gutenberg Colonnes et un sommaire en accordeons par Titre 2.', 'omr-word-importer' ); ?></p>
								</td>
							</tr>
						</tbody>
					</table>

					<?php submit_button( __( 'Importer en brouillon', 'omr-word-importer' ) ); ?>
				</form>
			</div>

			<div class="omr-panel">
				<h2><?php esc_html_e( 'Convention recommandee dans Word', 'omr-word-importer' ); ?></h2>
				<ul>
					<li><?php esc_html_e( 'Utiliser les styles Titre 1, Titre 2, Titre 3 pour structurer l article.', 'omr-word-importer' ); ?></li>
					<li><?php esc_html_e( 'Utiliser un style nomme Terminal ou Code pour les commandes.', 'omr-word-importer' ); ?></li>
					<li><?php esc_html_e( 'Inserer les images dans le document Word, pas seulement des liens externes.', 'omr-word-importer' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle upload POST requests.
	 *
	 * @return array<string,mixed>
	 */
	private function handle_upload_request(): array {
		if ( ! check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Security check failed.', 'omr-word-importer' ),
			);
		}

		if ( empty( $_FILES['omr_docx_file'] ) || ! is_array( $_FILES['omr_docx_file'] ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'No file was uploaded.', 'omr-word-importer' ),
			);
		}

		$file = $_FILES['omr_docx_file'];

		if ( ! empty( $file['error'] ) ) {
			return array(
				'type'    => 'error',
				'message' => sprintf(
					/* translators: %d upload error code. */
					__( 'Upload failed with error code %d.', 'omr-word-importer' ),
					(int) $file['error']
				),
			);
		}

		$extension = strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
		if ( 'docx' !== $extension ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Only .docx files are accepted.', 'omr-word-importer' ),
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$uploaded = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => array(
					'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				),
			)
		);

		if ( isset( $uploaded['error'] ) ) {
			return array(
				'type'    => 'error',
				'message' => $uploaded['error'],
			);
		}

		$terminal_styles = isset( $_POST['omr_terminal_styles'] )
			? sanitize_text_field( wp_unslash( $_POST['omr_terminal_styles'] ) )
			: '';

		$post_status = isset( $_POST['omr_post_status'] ) ? sanitize_key( wp_unslash( $_POST['omr_post_status'] ) ) : 'draft';
		if ( ! in_array( $post_status, array( 'draft', 'pending' ), true ) ) {
			$post_status = 'draft';
		}

		$toc_mode = isset( $_POST['omr_toc_mode'] ) ? sanitize_key( wp_unslash( $_POST['omr_toc_mode'] ) ) : 'auto';
		if ( ! in_array( $toc_mode, array( 'auto', 'always', 'none' ), true ) ) {
			$toc_mode = 'auto';
		}

		$toc_placement = isset( $_POST['omr_toc_placement'] ) ? sanitize_key( wp_unslash( $_POST['omr_toc_placement'] ) ) : 'inline';
		if ( ! in_array( $toc_placement, array( 'inline', 'sidebar' ), true ) ) {
			$toc_placement = 'inline';
		}

		try {
			$converter = new OMR_Docx_Converter(
				array(
					'terminal_styles' => $terminal_styles,
					'toc_mode'        => $toc_mode,
					'toc_placement'   => $toc_placement,
				)
			);

			$document = $converter->convert( $uploaded['file'] );
		} catch ( Exception $exception ) {
			return array(
				'type'    => 'error',
				'message' => $exception->getMessage(),
			);
		}

		$post_id = wp_insert_post(
			array(
				'post_title'   => $document['title'],
				'post_content' => '',
				'post_status'  => $post_status,
				'post_type'    => 'post',
				'post_author'  => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return array(
				'type'    => 'error',
				'message' => $post_id->get_error_message(),
			);
		}

		$content = $converter->replace_image_placeholders( $document['html'], (int) $post_id );

		$updated = wp_update_post(
			array(
				'ID'           => (int) $post_id,
				'post_content' => $content,
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			return array(
				'type'    => 'error',
				'message' => $updated->get_error_message(),
			);
		}

		add_post_meta( (int) $post_id, '_omr_word_import_source_name', sanitize_file_name( (string) $file['name'] ), true );
		add_post_meta( (int) $post_id, '_omr_word_imported_at', current_time( 'mysql' ), true );

		return array(
			'type'     => 'success',
			'message'  => __( 'Document imported successfully.', 'omr-word-importer' ),
			'post_id'  => (int) $post_id,
			'warnings' => $document['warnings'],
			'stats'    => array(
				'images' => count( $converter->get_images() ),
			),
		);
	}

	/**
	 * Render upload result notice.
	 *
	 * @param array<string,mixed>|null $result Result data.
	 */
	private function render_result( ?array $result ): void {
		if ( null === $result ) {
			return;
		}

		$class = 'success' === $result['type'] ? 'notice-success' : 'notice-error';
		?>
		<div class="notice <?php echo esc_attr( $class ); ?> is-dismissible">
			<p><?php echo esc_html( (string) $result['message'] ); ?></p>
			<?php if ( 'success' === $result['type'] && ! empty( $result['post_id'] ) ) : ?>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( get_edit_post_link( (int) $result['post_id'], '' ) ); ?>">
						<?php esc_html_e( 'Ouvrir le brouillon', 'omr-word-importer' ); ?>
					</a>
					<a class="button" href="<?php echo esc_url( get_preview_post_link( (int) $result['post_id'] ) ); ?>">
						<?php esc_html_e( 'Previsualiser', 'omr-word-importer' ); ?>
					</a>
				</p>
				<?php if ( ! empty( $result['stats']['images'] ) ) : ?>
					<p>
						<?php
						printf(
							/* translators: %d number of imported images. */
							esc_html__( '%d image(s) imported into the media library.', 'omr-word-importer' ),
							(int) $result['stats']['images']
						);
						?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
			<?php if ( ! empty( $result['warnings'] ) && is_array( $result['warnings'] ) ) : ?>
				<ul class="omr-warnings">
					<?php foreach ( $result['warnings'] as $warning ) : ?>
						<li><?php echo esc_html( (string) $warning ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}
}
