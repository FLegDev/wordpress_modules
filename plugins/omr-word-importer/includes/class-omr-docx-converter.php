<?php
/**
 * Lightweight DOCX to HTML converter for the OMR Word Importer plugin.
 *
 * @package OMR_Word_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts a DOCX document into conservative WordPress-ready HTML.
 */
class OMR_Docx_Converter {
	private const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
	private const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
	private const NS_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';
	private const IMAGE_PLACEHOLDER_PREFIX = '__OMR_WORD_IMAGE_';
	private const TOC_PLACEHOLDER = '__OMR_WORD_TOC__';

	/**
	 * Converter options.
	 *
	 * @var array<string,mixed>
	 */
	private array $options;

	/**
	 * DOCX relationships keyed by relationship ID.
	 *
	 * @var array<string,array<string,string>>
	 */
	private array $relationships = array();

	/**
	 * Style metadata keyed by Word style ID.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $styles = array();

	/**
	 * Embedded images keyed by relationship ID.
	 *
	 * @var array<string,array<string,string>>
	 */
	private array $images = array();

	/**
	 * Imported headings used to generate a web table of contents.
	 *
	 * @var array<int,array{level:int,title:string,id:string}>
	 */
	private array $headings = array();

	/**
	 * Heading slug counters used to keep anchor IDs unique.
	 *
	 * @var array<string,int>
	 */
	private array $heading_ids = array();

	/**
	 * Whether a Word table of contents was detected.
	 */
	private bool $toc_detected = false;

	/**
	 * Conversion warnings.
	 *
	 * @var string[]
	 */
	private array $warnings = array();

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $options Converter options.
	 */
	public function __construct( array $options = array() ) {
		$this->options = wp_parse_args(
			$options,
			array(
				'terminal_styles' => 'Terminal,Code,Console,Bash,Shell,PowerShell',
				'toc_mode'        => 'auto',
				'toc_placement'   => 'inline',
			)
		);
	}

	/**
	 * Get extracted image metadata.
	 *
	 * @return array<string,array<string,string>>
	 */
	public function get_images(): array {
		return $this->images;
	}

	/**
	 * Convert a DOCX file into HTML and metadata.
	 *
	 * @param string $docx_path Absolute path to the uploaded DOCX file.
	 * @return array{title:string,html:string,warnings:array<int,string>}
	 * @throws RuntimeException When the file cannot be read.
	 */
	public function convert( string $docx_path ): array {
		$archive = new OMR_Docx_Archive( $docx_path );

		$document_xml = $archive->get_from_name( 'word/document.xml' );
		if ( false === $document_xml ) {
			$archive->close();
			throw new RuntimeException( __( 'This .docx file does not contain word/document.xml.', 'omr-word-importer' ) );
		}

		$this->relationships = $this->read_relationships( $archive );
		$this->styles        = $this->read_styles( $archive );
		$this->extract_images( $archive );

		$document = $this->load_xml( $document_xml );
		$xpath    = $this->xpath( $document );
		$body     = $xpath->query( '/w:document/w:body' )->item( 0 );

		if ( ! $body instanceof DOMElement ) {
			$archive->close();
			throw new RuntimeException( __( 'Unable to find the document body.', 'omr-word-importer' ) );
		}

		$html_parts            = array();
		$list_open             = false;
		$code_lines            = array();
		$detected_title        = '';
		$toc_placeholder_added = false;

		foreach ( $body->childNodes as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}

			if ( 'sectPr' === $node->localName ) {
				continue;
			}

			if ( 'p' === $node->localName && $this->is_toc_paragraph( $node, $xpath ) ) {
				$this->toc_detected = true;

				if ( ! empty( $code_lines ) ) {
					$html_parts[] = $this->render_code_block( $code_lines );
					$code_lines   = array();
				}

				if ( $list_open ) {
					$html_parts[] = '</ul>';
					$list_open    = false;
				}

				if ( ! $toc_placeholder_added ) {
					$html_parts[]            = self::TOC_PLACEHOLDER;
					$toc_placeholder_added = true;
				}

				continue;
			}

			if ( ! $this->is_code_paragraph( $node, $xpath ) && ! empty( $code_lines ) ) {
				$html_parts[] = $this->render_code_block( $code_lines );
				$code_lines   = array();
			}

			if ( 'p' === $node->localName ) {
				$paragraph = $this->convert_paragraph( $node, $xpath );

				if ( $paragraph['is_empty'] ) {
					continue;
				}

				if ( $paragraph['is_code'] ) {
					$code_lines[] = $paragraph['plain_text'];
					continue;
				}

				if ( $paragraph['is_list'] && ! $list_open ) {
					$html_parts[] = '<!-- wp:list -->' . "\n" . '<ul>';
					$list_open    = true;
				}

				if ( ! $paragraph['is_list'] && $list_open ) {
					$html_parts[] = '</ul>' . "\n" . '<!-- /wp:list -->';
					$list_open    = false;
				}

				if ( '' === $detected_title && ! empty( $paragraph['plain_text'] ) ) {
					$detected_title = $paragraph['plain_text'];
				}

				$html_parts[] = $paragraph['html'];
				continue;
			}

			if ( $list_open ) {
				$html_parts[] = '</ul>' . "\n" . '<!-- /wp:list -->';
				$list_open    = false;
			}

			if ( 'tbl' === $node->localName ) {
				$html_parts[] = $this->convert_table( $node, $xpath );
			}
		}

		if ( ! empty( $code_lines ) ) {
			$html_parts[] = $this->render_code_block( $code_lines );
		}

		if ( $list_open ) {
			$html_parts[] = '</ul>' . "\n" . '<!-- /wp:list -->';
		}

		$archive->close();

		$title = $detected_title ? wp_strip_all_tags( $detected_title ) : __( 'Imported Word document', 'omr-word-importer' );
		$html  = implode( "\n\n", array_filter( $html_parts ) );
		$html  = $this->finalize_table_of_contents( $html );

		return array(
			'title'    => $title,
			'html'     => $html,
			'warnings' => $this->warnings,
		);
	}

	/**
	 * Replace embedded image placeholders with uploaded media URLs.
	 *
	 * @param string $html    Converted HTML.
	 * @param int    $post_id WordPress post ID.
	 * @return string HTML with media URLs.
	 */
	public function replace_image_placeholders( string $html, int $post_id ): string {
		foreach ( $this->images as $relationship_id => $image ) {
			$url = $this->import_image( $relationship_id, $image, $post_id );
			if ( '' === $url ) {
				continue;
			}

			$html = str_replace( self::IMAGE_PLACEHOLDER_PREFIX . $relationship_id . '__', esc_url( $url ), $html );
		}

		return $html;
	}

	/**
	 * Read document relationships.
	 *
	 * @param OMR_Docx_Archive $archive DOCX archive.
	 * @return array<string,array<string,string>>
	 */
	private function read_relationships( OMR_Docx_Archive $archive ): array {
		$xml = $archive->get_from_name( 'word/_rels/document.xml.rels' );
		if ( false === $xml ) {
			$this->warnings[] = __( 'No document relationships were found.', 'omr-word-importer' );
			return array();
		}

		$document = $this->load_xml( $xml );
		$xpath    = new DOMXPath( $document );
		$xpath->registerNamespace( 'rel', self::NS_REL );

		$relationships = array();
		foreach ( $xpath->query( '/rel:Relationships/rel:Relationship' ) as $relationship ) {
			if ( ! $relationship instanceof DOMElement ) {
				continue;
			}

			$id = $relationship->getAttribute( 'Id' );
			if ( '' === $id ) {
				continue;
			}

			$relationships[ $id ] = array(
				'type'   => $relationship->getAttribute( 'Type' ),
				'target' => $relationship->getAttribute( 'Target' ),
			);
		}

		return $relationships;
	}

	/**
	 * Read Word styles.
	 *
	 * @param OMR_Docx_Archive $archive DOCX archive.
	 * @return array<string,array<string,mixed>>
	 */
	private function read_styles( OMR_Docx_Archive $archive ): array {
		$xml = $archive->get_from_name( 'word/styles.xml' );
		if ( false === $xml ) {
			return array();
		}

		$document = $this->load_xml( $xml );
		$xpath    = $this->xpath( $document );
		$styles   = array();

		foreach ( $xpath->query( '/w:styles/w:style' ) as $style ) {
			if ( ! $style instanceof DOMElement ) {
				continue;
			}

			$style_id = $style->getAttributeNS( self::NS_W, 'styleId' );
			if ( '' === $style_id ) {
				$style_id = $style->getAttribute( 'w:styleId' );
			}

			if ( '' === $style_id ) {
				continue;
			}

			$name_node = $xpath->query( 'w:name', $style )->item( 0 );
			$name      = $name_node instanceof DOMElement ? $name_node->getAttributeNS( self::NS_W, 'val' ) : '';

			$styles[ $style_id ] = array(
				'name'          => $name,
				'heading_level' => $this->detect_heading_level( $style_id, $name ),
				'is_code'       => $this->detect_code_style( $style_id, $name ),
				'is_toc'        => $this->detect_toc_style( $style_id, $name ),
			);
		}

		return $styles;
	}

	/**
	 * Extract embedded images from the DOCX archive.
	 *
	 * @param OMR_Docx_Archive $archive DOCX archive.
	 */
	private function extract_images( OMR_Docx_Archive $archive ): void {
		foreach ( $this->relationships as $relationship_id => $relationship ) {
			if ( empty( $relationship['type'] ) || false === strpos( $relationship['type'], '/image' ) ) {
				continue;
			}

			$target = $this->normalize_word_target( $relationship['target'] ?? '' );
			if ( '' === $target ) {
				continue;
			}

			$data = $archive->get_from_name( $target );
			if ( false === $data ) {
				$this->warnings[] = sprintf(
					/* translators: %s image path inside the DOCX archive. */
					__( 'Embedded image not found: %s', 'omr-word-importer' ),
					$target
				);
				continue;
			}

			$this->images[ $relationship_id ] = array(
				'name' => sanitize_file_name( basename( $target ) ),
				'data' => $data,
			);
		}
	}

	/**
	 * Convert a paragraph node to HTML.
	 *
	 * @param DOMElement $paragraph Paragraph node.
	 * @param DOMXPath   $xpath     XPath helper.
	 * @return array<string,mixed>
	 */
	private function convert_paragraph( DOMElement $paragraph, DOMXPath $xpath ): array {
		$style_id      = $this->get_paragraph_style_id( $paragraph, $xpath );
		$style         = $this->styles[ $style_id ] ?? array();
		$heading_level = (int) ( $style['heading_level'] ?? $this->detect_heading_level( $style_id, $style_id ) );
		$is_code       = $this->is_code_paragraph( $paragraph, $xpath );
		$is_list       = $this->is_list_paragraph( $paragraph, $xpath );

		$inline_html = $this->convert_runs( $paragraph, $xpath );
		$plain_text  = trim( $this->paragraph_text( $paragraph, $xpath ) );
		$is_empty    = '' === trim( wp_strip_all_tags( $inline_html ) ) && false === strpos( $inline_html, '<img ' );

		if ( $is_code ) {
			return array(
				'html'       => '',
				'plain_text' => $plain_text,
				'is_empty'   => '' === $plain_text,
				'is_code'    => true,
				'is_list'    => false,
			);
		}

		if ( $is_list ) {
			$html = '<li>' . $inline_html . '</li>';
		} elseif ( $heading_level >= 1 && $heading_level <= 6 ) {
			$heading_id = $this->register_heading( $heading_level, $plain_text );
			$id_attr    = '' !== $heading_id ? ' id="' . esc_attr( $heading_id ) . '"' : '';
			$attrs      = array(
				'level' => $heading_level,
			);
			if ( '' !== $heading_id ) {
				$attrs['anchor'] = $heading_id;
			}
			$html = $this->render_block(
				'heading',
				$attrs,
				'<h' . $heading_level . $id_attr . '>' . $inline_html . '</h' . $heading_level . '>'
			);
		} elseif ( false !== strpos( $inline_html, '<img ' ) && '' === $plain_text ) {
			$html = $this->render_block(
				'image',
				array(
					'sizeSlug' => 'large',
				),
				'<figure class="wp-block-image size-large">' . $inline_html . '</figure>'
			);
		} else {
			$html = $this->render_block( 'paragraph', array(), '<p>' . $inline_html . '</p>' );
		}

		return array(
			'html'       => $html,
			'plain_text' => $plain_text,
			'is_empty'   => $is_empty,
			'is_code'    => false,
			'is_list'    => $is_list,
		);
	}

	/**
	 * Convert a Word table to HTML.
	 *
	 * @param DOMElement $table Table node.
	 * @param DOMXPath   $xpath XPath helper.
	 * @return string
	 */
	private function convert_table( DOMElement $table, DOMXPath $xpath ): string {
		$rows = array();

		foreach ( $xpath->query( 'w:tr', $table ) as $row ) {
			if ( ! $row instanceof DOMElement ) {
				continue;
			}

			$cells = array();
			foreach ( $xpath->query( 'w:tc', $row ) as $cell ) {
				if ( ! $cell instanceof DOMElement ) {
					continue;
				}

				$cell_parts = array();
				foreach ( $xpath->query( 'w:p', $cell ) as $paragraph ) {
					if ( $paragraph instanceof DOMElement ) {
						$cell_parts[] = $this->convert_runs( $paragraph, $xpath );
					}
				}

				$cells[] = '<td>' . implode( '<br>', array_filter( $cell_parts ) ) . '</td>';
			}

			if ( ! empty( $cells ) ) {
				$rows[] = '<tr>' . implode( '', $cells ) . '</tr>';
			}
		}

		if ( empty( $rows ) ) {
			return '';
		}

		return $this->render_block(
			'table',
			array(),
			'<figure class="wp-block-table"><table><tbody>' . implode( '', $rows ) . '</tbody></table></figure>'
		);
	}

	/**
	 * Convert Word runs inside a paragraph into inline HTML.
	 *
	 * @param DOMElement $paragraph Paragraph node.
	 * @param DOMXPath   $xpath     XPath helper.
	 * @return string
	 */
	private function convert_runs( DOMElement $paragraph, DOMXPath $xpath ): string {
		$parts = array();

		foreach ( $xpath->query( 'w:r|w:hyperlink', $paragraph ) as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}

			if ( 'hyperlink' === $node->localName ) {
				$parts[] = $this->convert_hyperlink( $node, $xpath );
				continue;
			}

			$parts[] = $this->convert_run( $node, $xpath );
		}

		return implode( '', array_filter( $parts, static fn( $part ) => '' !== $part ) );
	}

	/**
	 * Convert a single Word run.
	 *
	 * @param DOMElement $run   Run node.
	 * @param DOMXPath   $xpath XPath helper.
	 * @return string
	 */
	private function convert_run( DOMElement $run, DOMXPath $xpath ): string {
		$parts = array();

		foreach ( $xpath->query( './/a:blip', $run ) as $blip ) {
			if ( ! $blip instanceof DOMElement ) {
				continue;
			}

			$relationship_id = $blip->getAttributeNS( self::NS_R, 'embed' );
			if ( '' !== $relationship_id ) {
				$parts[] = '<img src="' . esc_attr( self::IMAGE_PLACEHOLDER_PREFIX . $relationship_id . '__' ) . '" alt="" />';
			}
		}

		foreach ( $xpath->query( 'w:t|w:tab|w:br', $run ) as $child ) {
			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			if ( 'tab' === $child->localName ) {
				$parts[] = "\t";
				continue;
			}

			if ( 'br' === $child->localName ) {
				$parts[] = '<br>';
				continue;
			}

			$parts[] = esc_html( $child->textContent );
		}

		$text = implode( '', $parts );
		if ( '' === $text ) {
			return '';
		}

		if ( $this->run_has_property( $run, $xpath, 'b' ) ) {
			$text = '<strong>' . $text . '</strong>';
		}

		if ( $this->run_has_property( $run, $xpath, 'i' ) ) {
			$text = '<em>' . $text . '</em>';
		}

		if ( $this->run_has_property( $run, $xpath, 'u' ) ) {
			$text = '<u>' . $text . '</u>';
		}

		return $text;
	}

	/**
	 * Convert a hyperlink node.
	 *
	 * @param DOMElement $hyperlink Hyperlink node.
	 * @param DOMXPath   $xpath     XPath helper.
	 * @return string
	 */
	private function convert_hyperlink( DOMElement $hyperlink, DOMXPath $xpath ): string {
		$relationship_id = $hyperlink->getAttributeNS( self::NS_R, 'id' );
		$url             = '';

		if ( '' !== $relationship_id && isset( $this->relationships[ $relationship_id ]['target'] ) ) {
			$url = $this->relationships[ $relationship_id ]['target'];
		}

		$text = array();
		foreach ( $xpath->query( 'w:r', $hyperlink ) as $run ) {
			if ( $run instanceof DOMElement ) {
				$text[] = $this->convert_run( $run, $xpath );
			}
		}

		$label = implode( '', $text );
		if ( '' === trim( wp_strip_all_tags( $label ) ) ) {
			return '';
		}

		if ( '' === $url ) {
			return $label;
		}

		return '<a href="' . esc_url( $url ) . '">' . $label . '</a>';
	}

	/**
	 * Render a grouped terminal/code block.
	 *
	 * @param string[] $lines Code lines.
	 * @return string
	 */
	private function render_code_block( array $lines ): string {
		$code = implode( "\n", $lines );
		return $this->render_block(
			'code',
			array(
				'className' => 'omr-terminal',
			),
			'<pre class="wp-block-code omr-terminal"><code>' . esc_html( $code ) . '</code></pre>'
		);
	}

	/**
	 * Determine whether a run has a formatting property.
	 *
	 * @param DOMElement $run      Run node.
	 * @param DOMXPath   $xpath    XPath helper.
	 * @param string     $property Word run property local name.
	 * @return bool
	 */
	private function run_has_property( DOMElement $run, DOMXPath $xpath, string $property ): bool {
		$nodes = $xpath->query( 'w:rPr/w:' . $property, $run );
		if ( ! $nodes || 0 === $nodes->length ) {
			return false;
		}

		$node = $nodes->item( 0 );
		if ( ! $node instanceof DOMElement ) {
			return false;
		}

		$value = $node->getAttributeNS( self::NS_W, 'val' );
		return ! in_array( strtolower( $value ), array( '0', 'false', 'off' ), true );
	}

	/**
	 * Get paragraph plain text.
	 *
	 * @param DOMElement $paragraph Paragraph node.
	 * @param DOMXPath   $xpath     XPath helper.
	 * @return string
	 */
	private function paragraph_text( DOMElement $paragraph, DOMXPath $xpath ): string {
		$parts = array();
		foreach ( $xpath->query( './/w:t|.//w:tab|.//w:br', $paragraph ) as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}

			if ( 'tab' === $node->localName ) {
				$parts[] = "\t";
			} elseif ( 'br' === $node->localName ) {
				$parts[] = "\n";
			} else {
				$parts[] = $node->textContent;
			}
		}

		return implode( '', $parts );
	}

	/**
	 * Get the paragraph style ID.
	 *
	 * @param DOMElement $paragraph Paragraph node.
	 * @param DOMXPath   $xpath     XPath helper.
	 * @return string
	 */
	private function get_paragraph_style_id( DOMElement $paragraph, DOMXPath $xpath ): string {
		$style_node = $xpath->query( 'w:pPr/w:pStyle', $paragraph )->item( 0 );
		if ( ! $style_node instanceof DOMElement ) {
			return '';
		}

		$style_id = $style_node->getAttributeNS( self::NS_W, 'val' );
		return '' !== $style_id ? $style_id : $style_node->getAttribute( 'w:val' );
	}

	/**
	 * Determine whether a paragraph uses a configured code/terminal style.
	 *
	 * @param DOMElement $paragraph Paragraph node.
	 * @param DOMXPath   $xpath     XPath helper.
	 * @return bool
	 */
	private function is_code_paragraph( DOMElement $paragraph, DOMXPath $xpath ): bool {
		if ( 'p' !== $paragraph->localName ) {
			return false;
		}

		$style_id = $this->get_paragraph_style_id( $paragraph, $xpath );
		$style    = $this->styles[ $style_id ] ?? array();

		return (bool) ( $style['is_code'] ?? $this->detect_code_style( $style_id, $style_id ) );
	}

	/**
	 * Determine whether a paragraph belongs to a generated Word table of contents.
	 *
	 * @param DOMElement $paragraph Paragraph node.
	 * @param DOMXPath   $xpath     XPath helper.
	 * @return bool
	 */
	private function is_toc_paragraph( DOMElement $paragraph, DOMXPath $xpath ): bool {
		if ( 'p' !== $paragraph->localName ) {
			return false;
		}

		$style_id = $this->get_paragraph_style_id( $paragraph, $xpath );
		$style    = $this->styles[ $style_id ] ?? array();

		if ( (bool) ( $style['is_toc'] ?? $this->detect_toc_style( $style_id, $style_id ) ) ) {
			return true;
		}

		foreach ( $xpath->query( './/w:instrText', $paragraph ) as $field ) {
			if ( $field instanceof DOMElement && preg_match( '/\\bTOC\\b/i', $field->textContent ) ) {
				return true;
			}
		}

		$text = $this->normalize_for_match( trim( $this->paragraph_text( $paragraph, $xpath ) ) );
		return in_array( $text, array( 'tabledesmatieres', 'sommaire', 'tableofcontents', 'contents' ), true );
	}

	/**
	 * Determine whether a paragraph is part of a Word list.
	 *
	 * @param DOMElement $paragraph Paragraph node.
	 * @param DOMXPath   $xpath     XPath helper.
	 * @return bool
	 */
	private function is_list_paragraph( DOMElement $paragraph, DOMXPath $xpath ): bool {
		$nodes = $xpath->query( 'w:pPr/w:numPr', $paragraph );
		return $nodes && $nodes->length > 0;
	}

	/**
	 * Register a heading and return its HTML anchor ID.
	 *
	 * @param int    $level Heading level.
	 * @param string $title Heading text.
	 * @return string
	 */
	private function register_heading( int $level, string $title ): string {
		$title = trim( wp_strip_all_tags( $title ) );
		if ( '' === $title ) {
			return '';
		}

		$slug = sanitize_title( $title );
		if ( '' === $slug ) {
			$slug = 'section';
		}

		if ( ! isset( $this->heading_ids[ $slug ] ) ) {
			$this->heading_ids[ $slug ] = 0;
		}

		$this->heading_ids[ $slug ]++;
		$id = 1 === $this->heading_ids[ $slug ] ? $slug : $slug . '-' . $this->heading_ids[ $slug ];

		if ( $level >= 2 && $level <= 4 ) {
			$this->headings[] = array(
				'level' => $level,
				'title' => $title,
				'id'    => $id,
			);
		}

		return $id;
	}

	/**
	 * Insert or remove the generated table of contents.
	 *
	 * @param string $html Converted HTML.
	 * @return string
	 */
	private function finalize_table_of_contents( string $html ): string {
		$mode = (string) $this->options['toc_mode'];

		if ( 'none' === $mode ) {
			return str_replace( self::TOC_PLACEHOLDER, '', $html );
		}

		$placement       = (string) $this->options['toc_placement'];
		$should_generate = 'always' === $mode || ( 'auto' === $mode && $this->toc_detected );
		$toc             = '';

		if ( $should_generate ) {
			$toc = 'sidebar' === $placement ? $this->render_sidebar_accordion_table_of_contents() : $this->render_table_of_contents();
		}

		if ( 'sidebar' === $placement && '' !== $toc ) {
			return $this->render_sidebar_table_of_contents( str_replace( self::TOC_PLACEHOLDER, '', $html ), $toc );
		}

		if ( false !== strpos( $html, self::TOC_PLACEHOLDER ) ) {
			return str_replace( self::TOC_PLACEHOLDER, $toc, $html );
		}

		if ( '' === $toc ) {
			return $html;
		}

		return preg_replace( '/(<h1[^>]*>.*?<\\/h1>)/s', '$1' . "\n\n" . $toc, $html, 1 ) ?: $toc . "\n\n" . $html;
	}

	/**
	 * Render the table of contents in a Gutenberg columns sidebar.
	 *
	 * @param string $html Converted article HTML.
	 * @param string $toc  Rendered table of contents block.
	 * @return string
	 */
	private function render_sidebar_table_of_contents( string $html, string $toc ): string {
		$lead = '';
		$body = $html;

		if ( preg_match( '/^(<!-- wp:heading\\b[^>]*-->\\s*<h1[^>]*>.*?<\\/h1>\\s*<!-- \\/wp:heading -->)\\s*/s', $html, $matches ) ) {
			$lead = $matches[1] . "\n\n";
			$body = substr( $html, strlen( $matches[0] ) );
		}

		$sidebar_column = $this->render_block(
			'column',
			array(
				'width'     => '28%',
				'className' => 'omr-toc-sidebar-column',
			),
			'<div class="wp-block-column omr-toc-sidebar-column" style="flex-basis:28%">' . "\n" . $toc . "\n" . '</div>'
		);

		$content_column = $this->render_block(
			'column',
			array(
				'width'     => '72%',
				'className' => 'omr-article-main-column',
			),
			'<div class="wp-block-column omr-article-main-column" style="flex-basis:72%">' . "\n" . trim( $body ) . "\n" . '</div>'
		);

		$columns = $this->render_block(
			'columns',
			array(
				'className' => 'omr-layout-with-toc',
			),
			'<div class="wp-block-columns omr-layout-with-toc">' . "\n" . $sidebar_column . "\n" . $content_column . "\n" . '</div>'
		);

		return $lead . $columns;
	}

	/**
	 * Render the sidebar table of contents as H2 accordion sections.
	 *
	 * @return string
	 */
	private function render_sidebar_accordion_table_of_contents(): string {
		if ( empty( $this->headings ) ) {
			return '';
		}

		$sections = $this->group_headings_by_h2();
		if ( empty( $sections ) ) {
			return $this->render_table_of_contents();
		}

		$title = $this->render_block(
			'paragraph',
			array(
				'className' => 'omr-toc-title',
			),
			'<p class="omr-toc-title">' . esc_html__( 'Table des matieres', 'omr-word-importer' ) . '</p>'
		);

		$details = array();
		foreach ( $sections as $section ) {
			$details[] = $this->render_toc_accordion_section( $section );
		}

		return $this->render_block(
			'group',
			array(
				'className' => 'omr-table-of-contents omr-table-of-contents-sidebar omr-toc-accordion',
				'layout'    => array(
					'type' => 'constrained',
				),
			),
			'<div class="wp-block-group omr-table-of-contents omr-table-of-contents-sidebar omr-toc-accordion">' . "\n" . $title . "\n" . implode( "\n", array_filter( $details ) ) . "\n" . '</div>'
		);
	}

	/**
	 * Group recorded headings into H2-led sections for the sidebar accordion.
	 *
	 * @return array<int,array{heading:array<string,mixed>,children:array<int,array<string,mixed>>}>
	 */
	private function group_headings_by_h2(): array {
		$sections = array();
		$current  = null;

		foreach ( $this->headings as $heading ) {
			$level = (int) $heading['level'];

			if ( 2 === $level || null === $current ) {
				if ( null !== $current ) {
					$sections[] = $current;
				}

				if ( 2 === $level ) {
					$current = array(
						'heading'  => $heading,
						'children' => array(),
					);
					continue;
				}

				$current = array(
					'heading'  => array(
						'level' => 2,
						'title' => __( 'Sections', 'omr-word-importer' ),
						'id'    => '',
					),
					'children' => array( $heading ),
				);
				continue;
			}

			$current['children'][] = $heading;
		}

		if ( null !== $current ) {
			$sections[] = $current;
		}

		return $sections;
	}

	/**
	 * Render one H2 accordion section for the sidebar table of contents.
	 *
	 * @param array{heading:array<string,mixed>,children:array<int,array<string,mixed>>} $section TOC section.
	 * @return string
	 */
	private function render_toc_accordion_section( array $section ): string {
		$heading  = $section['heading'];
		$children = $section['children'];

		$list = '<ol class="omr-toc-accordion-list">';

		if ( ! empty( $heading['id'] ) ) {
			$list .= '<li class="omr-toc-level-2 omr-toc-section-link">';
			$list .= '<a href="#' . esc_attr( (string) $heading['id'] ) . '">' . esc_html__( 'Voir la section', 'omr-word-importer' ) . '</a>';
			$list .= '</li>';
		}

		foreach ( $children as $child ) {
			$list .= '<li class="omr-toc-level-' . (int) $child['level'] . '">';
			$list .= '<a href="#' . esc_attr( (string) $child['id'] ) . '">' . esc_html( (string) $child['title'] ) . '</a>';
			$list .= '</li>';
		}

		$list .= '</ol>';

		$list_block = $this->render_block(
			'list',
			array(
				'ordered'   => true,
				'className' => 'omr-toc-accordion-list',
			),
			$list
		);

		return $this->render_block(
			'details',
			array(
				'className' => 'omr-toc-accordion-section',
			),
			'<details class="wp-block-details omr-toc-accordion-section"><summary>' . esc_html( (string) $heading['title'] ) . '</summary>' . "\n" . $list_block . "\n" . '</details>'
		);
	}

	/**
	 * Render the generated table of contents.
	 *
	 * @return string
	 */
	private function render_table_of_contents(): string {
		if ( empty( $this->headings ) ) {
			return '';
		}

		$list = '<ol class="omr-toc-list">';

		foreach ( $this->headings as $heading ) {
			$list .= '<li class="omr-toc-level-' . (int) $heading['level'] . '">';
			$list .= '<a href="#' . esc_attr( $heading['id'] ) . '">' . esc_html( $heading['title'] ) . '</a>';
			$list .= '</li>';
		}

		$list .= '</ol>';

		$title = $this->render_block(
			'paragraph',
			array(
				'className' => 'omr-toc-title',
			),
			'<p class="omr-toc-title">' . esc_html__( 'Table des matieres', 'omr-word-importer' ) . '</p>'
		);

		$list_block = $this->render_block(
			'list',
			array(
				'ordered'   => true,
				'className' => 'omr-toc-list',
			),
			$list
		);

		return $this->render_block(
			'group',
			array(
				'className' => 'omr-table-of-contents',
				'layout'    => array(
					'type' => 'constrained',
				),
			),
			'<div class="wp-block-group omr-table-of-contents">' . "\n" . $title . "\n" . $list_block . "\n" . '</div>'
		);
	}

	/**
	 * Render Gutenberg block comments around inner HTML.
	 *
	 * @param string              $name       Core block name without the wp: prefix.
	 * @param array<string,mixed> $attributes Block attributes.
	 * @param string              $inner_html Inner HTML.
	 * @return string
	 */
	private function render_block( string $name, array $attributes, string $inner_html ): string {
		$attrs = '';
		if ( ! empty( $attributes ) ) {
			$encoded = wp_json_encode( $attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( is_string( $encoded ) && '{}' !== $encoded ) {
				$attrs = ' ' . $encoded;
			}
		}

		return '<!-- wp:' . $name . $attrs . ' -->' . "\n" . $inner_html . "\n" . '<!-- /wp:' . $name . ' -->';
	}

	/**
	 * Detect heading level from a style ID/name.
	 *
	 * @param string $style_id   Word style ID.
	 * @param string $style_name Word style display name.
	 * @return int
	 */
	private function detect_heading_level( string $style_id, string $style_name ): int {
		$candidates = array( $style_id, $style_name );
		foreach ( $candidates as $candidate ) {
			if ( preg_match( '/(?:heading|titre)\\s*([1-6])/i', $candidate, $matches ) ) {
				return (int) $matches[1];
			}
		}

		return 0;
	}

	/**
	 * Detect whether a style should become a terminal/code block.
	 *
	 * @param string $style_id   Word style ID.
	 * @param string $style_name Word style display name.
	 * @return bool
	 */
	private function detect_code_style( string $style_id, string $style_name ): bool {
		$configured = array_filter(
			array_map(
				static fn( $value ) => strtolower( trim( (string) $value ) ),
				explode( ',', (string) $this->options['terminal_styles'] )
			)
		);

		$candidates = array(
			strtolower( $style_id ),
			strtolower( $style_name ),
		);

		foreach ( $configured as $style ) {
			foreach ( $candidates as $candidate ) {
				if ( '' !== $candidate && false !== strpos( $candidate, $style ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Detect whether a style is a Word table-of-contents style.
	 *
	 * @param string $style_id   Word style ID.
	 * @param string $style_name Word style display name.
	 * @return bool
	 */
	private function detect_toc_style( string $style_id, string $style_name ): bool {
		$candidates = array(
			$this->normalize_for_match( $style_id ),
			$this->normalize_for_match( $style_name ),
		);

		foreach ( $candidates as $candidate ) {
			$compact = str_replace( array( ' ', '-', '_' ), '', $candidate );
			if ( preg_match( '/^(toc|tdm)[0-9]*$/', $compact ) ) {
				return true;
			}

			if ( false !== strpos( $compact, 'tocheading' ) || false !== strpos( $compact, 'tableofcontents' ) || false !== strpos( $compact, 'tabledesmatieres' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalize a text value for loose style/title matching.
	 *
	 * @param string $value Raw text.
	 * @return string
	 */
	private function normalize_for_match( string $value ): string {
		if ( function_exists( 'remove_accents' ) ) {
			$value = remove_accents( $value );
		} else {
			$value = strtr(
				$value,
				array(
					'à' => 'a',
					'â' => 'a',
					'ä' => 'a',
					'ç' => 'c',
					'é' => 'e',
					'è' => 'e',
					'ê' => 'e',
					'ë' => 'e',
					'î' => 'i',
					'ï' => 'i',
					'ô' => 'o',
					'ö' => 'o',
					'ù' => 'u',
					'û' => 'u',
					'ü' => 'u',
				)
			);
		}

		$value = strtolower( $value );
		return preg_replace( '/[^a-z0-9]+/', '', $value ) ?: '';
	}

	/**
	 * Normalize a relationship target path into a DOCX archive path.
	 *
	 * @param string $target Relationship target.
	 * @return string
	 */
	private function normalize_word_target( string $target ): string {
		$target = trim( $target );
		if ( '' === $target || preg_match( '#^https?://#i', $target ) ) {
			return '';
		}

		$target = ltrim( $target, '/' );
		if ( 0 === strpos( $target, 'word/' ) ) {
			return $target;
		}

		return 'word/' . $target;
	}

	/**
	 * Import an embedded image into the media library.
	 *
	 * @param string              $relationship_id Relationship ID.
	 * @param array<string,mixed> $image           Image metadata and binary data.
	 * @param int                 $post_id         Parent post ID.
	 * @return string Attachment URL or empty string.
	 */
	private function import_image( string $relationship_id, array $image, int $post_id ): string {
		$filename = sanitize_file_name( (string) ( $image['name'] ?? ( $relationship_id . '.png' ) ) );
		$data     = (string) ( $image['data'] ?? '' );

		if ( '' === $data ) {
			return '';
		}

		$upload = wp_upload_bits( $filename, null, $data );
		if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
			$this->warnings[] = sprintf(
				/* translators: %s image filename. */
				__( 'Unable to upload image: %s', 'omr-word-importer' ),
				$filename
			);
			return '';
		}

		$filetype = wp_check_filetype( $upload['file'], null );
		$mime     = $filetype['type'] ?: 'image/png';

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => sanitize_text_field( pathinfo( $filename, PATHINFO_FILENAME ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$upload['file'],
			$post_id,
			true
		);

		if ( is_wp_error( $attachment_id ) ) {
			$this->warnings[] = $attachment_id->get_error_message();
			return '';
		}

		$metadata = wp_generate_attachment_metadata( (int) $attachment_id, $upload['file'] );
		if ( is_array( $metadata ) ) {
			wp_update_attachment_metadata( (int) $attachment_id, $metadata );
		}

		$url = wp_get_attachment_url( (int) $attachment_id );
		return $url ? $url : '';
	}

	/**
	 * Load XML safely.
	 *
	 * @param string $xml Raw XML.
	 * @return DOMDocument
	 */
	private function load_xml( string $xml ): DOMDocument {
		$previous = libxml_use_internal_errors( true );

		$document = new DOMDocument();
		$document->loadXML( $xml, LIBXML_NONET );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $document;
	}

	/**
	 * Create a namespace-aware XPath helper.
	 *
	 * @param DOMDocument $document XML document.
	 * @return DOMXPath
	 */
	private function xpath( DOMDocument $document ): DOMXPath {
		$xpath = new DOMXPath( $document );
		$xpath->registerNamespace( 'w', self::NS_W );
		$xpath->registerNamespace( 'r', self::NS_R );
		$xpath->registerNamespace( 'a', 'http://schemas.openxmlformats.org/drawingml/2006/main' );

		return $xpath;
	}
}

/**
 * Small archive reader with ZipArchive first and WordPress PclZip fallback.
 */
class OMR_Docx_Archive {
	/**
	 * Native ZipArchive instance when available.
	 *
	 * @var ZipArchive|null
	 */
	private ?ZipArchive $zip = null;

	/**
	 * PclZip instance when ZipArchive is unavailable.
	 *
	 * @var PclZip|null
	 */
	private $pclzip = null;

	/**
	 * Constructor.
	 *
	 * @param string $path DOCX path.
	 * @throws RuntimeException When no archive backend can open the file.
	 */
	public function __construct( string $path ) {
		if ( class_exists( 'ZipArchive' ) ) {
			$this->zip = new ZipArchive();
			if ( true === $this->zip->open( $path ) ) {
				return;
			}

			$this->zip = null;
		}

		if ( ! class_exists( 'PclZip' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/class-pclzip.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		}

		if ( class_exists( 'PclZip' ) ) {
			$this->pclzip = new PclZip( $path );
			return;
		}

		throw new RuntimeException( __( 'No ZIP reader is available to open the .docx file.', 'omr-word-importer' ) );
	}

	/**
	 * Get a file from the archive.
	 *
	 * @param string $name Internal archive path.
	 * @return string|false
	 */
	public function get_from_name( string $name ) {
		if ( $this->zip instanceof ZipArchive ) {
			return $this->zip->getFromName( $name );
		}

		if ( null === $this->pclzip ) {
			return false;
		}

		$result = $this->pclzip->extract(
			PCLZIP_OPT_BY_NAME,
			$name,
			PCLZIP_OPT_EXTRACT_AS_STRING
		);

		if ( ! is_array( $result ) || empty( $result[0]['content'] ) ) {
			return false;
		}

		return $result[0]['content'];
	}

	/**
	 * Close the archive when needed.
	 */
	public function close(): void {
		if ( $this->zip instanceof ZipArchive ) {
			$this->zip->close();
		}
	}
}
