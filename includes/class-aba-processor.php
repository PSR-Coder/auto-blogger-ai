<?php
defined( 'ABSPATH' ) || exit;

/**
 * ABA_Processor
 *
 * Cleans scraped HTML using DOMDocument, applies filters, sends content to AI if enabled,
 * and optionally downloads/attaches images.
 */
class ABA_Processor {

	/**
	 * Main entry: process content and return processed content and optional featured image ID.
	 *
	 * @param int    $campaign_id
	 * @param string $title
	 * @param string $html_content
	 * @param string $source_url
	 * @return array ['content' => string|null, 'featured_attachment_id' => int|null]
	 */
	public static function process_content( $campaign_id, $title, $html_content, $source_url = '' ) {
		$result = array(
			'content'                => null,
			'featured_attachment_id' => null,
		);

		if ( empty( $html_content ) ) {
			return $result;
		}

		// Step 1: Clean HTML using DOMDocument
		$clean_html = self::clean_html( $campaign_id, $html_content );

		// Step 2: Optionally check length / required / banned keywords
		if ( ! self::passes_filters( $campaign_id, $clean_html ) ) {
			// filtered out
			return $result;
		}

		// Step 3: AI rewrite (optional)
		$enable_ai = get_post_meta( $campaign_id, '_aba_enable_ai', true );
		if ( '1' === $enable_ai ) {
			$ai_prompt = get_post_meta( $campaign_id, '_aba_ai_prompt', true );
			$ai_model  = get_post_meta( $campaign_id, '_aba_ai_model', true );

			$rewritten = self::call_ai_rewrite( $campaign_id, $ai_model, $ai_prompt, $clean_html, $title, $source_url );
			if ( $rewritten && is_string( $rewritten ) ) {
				$clean_html = $rewritten;
			}
		}

		// Step 4: Image handling
		$download_images = get_post_meta( $campaign_id, '_aba_download_images', true );
		$set_featured    = get_post_meta( $campaign_id, '_aba_set_featured_image', true );
		$featured_attachment_id = null;

		if ( '1' === $download_images ) {
			$first_img_url = self::find_first_image_url( $clean_html );
			if ( $first_img_url ) {
				$attachment_id = self::sideload_image_to_media( $first_img_url );
				if ( $attachment_id && ! is_wp_error( $attachment_id ) ) {
					$featured_attachment_id = intval( $attachment_id );
				}
			}
		}

		$result['content'] = $clean_html;
		$result['featured_attachment_id'] = $featured_attachment_id;

		return $result;
	}

	/**
	 * Clean HTML: remove unwanted tags, elements by class/id, strip links/images if requested.
	 *
	 * @param int    $campaign_id
	 * @param string $html
	 * @return string Clean HTML
	 */
	private static function clean_html( $campaign_id, $html ) {
		// Use DOMDocument to manipulate safely.
		libxml_use_internal_errors( true );

		$dom = new DOMDocument();
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput = false;
		$loaded = $dom->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ) );
		if ( ! $loaded ) {
			libxml_clear_errors();
			return wp_strip_all_tags( $html );
		}

		$xpath = new DOMXPath( $dom );

		// Remove script, style, noscript, iframe elements
		$remove_tags = array( 'script', 'style', 'noscript', 'iframe', 'aside', 'form' );
		foreach ( $remove_tags as $tag ) {
			$nodes = $dom->getElementsByTagName( $tag );
			// Removing nodes while iterating - we clone to array first
			$to_remove = array();
			foreach ( $nodes as $n ) {
				$to_remove[] = $n;
			}
			foreach ( $to_remove as $r ) {
				if ( $r && $r->parentNode ) {
					$r->parentNode->removeChild( $r );
				}
			}
		}

		// Remove elements by class (comma-separated)
		$by_class = get_post_meta( $campaign_id, '_aba_remove_elements_by_class', true );
		if ( $by_class ) {
			$classes = array_map( 'trim', explode( ',', $by_class ) );
			foreach ( $classes as $cls ) {
				if ( empty( $cls ) ) {
					continue;
				}
				$query = "//*[contains(concat(' ', normalize-space(@class), ' '), ' {$cls} ')]";
				$nodes = $xpath->query( $query );
				$to_remove = array();
				if ( $nodes ) {
					foreach ( $nodes as $n ) {
						$to_remove[] = $n;
					}
					foreach ( $to_remove as $r ) {
						if ( $r && $r->parentNode ) {
							$r->parentNode->removeChild( $r );
						}
					}
				}
			}
		}

		// Remove elements by id
		$by_id = get_post_meta( $campaign_id, '_aba_remove_elements_by_id', true );
		if ( $by_id ) {
			$ids = array_map( 'trim', explode( ',', $by_id ) );
			foreach ( $ids as $id ) {
				if ( empty( $id ) ) {
					continue;
				}
				$node = $dom->getElementById( $id );
				if ( $node && $node->parentNode ) {
					$node->parentNode->removeChild( $node );
				}
			}
		}

		// Strip links? Replace <a> with their inner text
		$strip_links = get_post_meta( $campaign_id, '_aba_strip_links', true );
		$add_nofollow = get_post_meta( $campaign_id, '_aba_add_nofollow', true );

		if ( '1' === $strip_links ) {
			$as = $dom->getElementsByTagName( 'a' );
			$to_replace = array();
			foreach ( $as as $a ) {
				$to_replace[] = $a;
			}
			foreach ( $to_replace as $a ) {
				$text_node = $dom->createTextNode( $a->textContent );
				$a->parentNode->replaceChild( $text_node, $a );
			}
		} elseif ( '1' === $add_nofollow ) {
			// Add rel="nofollow" to external links
			$as = $dom->getElementsByTagName( 'a' );
			$home_host = parse_url( home_url(), PHP_URL_HOST );
			$to_update = array();
			foreach ( $as as $a ) {
				$to_update[] = $a;
			}
			foreach ( $to_update as $a ) {
				$href = $a->getAttribute( 'href' );
				$host = parse_url( $href, PHP_URL_HOST );
				if ( $host && $host !== $home_host ) {
					$rel = $a->getAttribute( 'rel' );
					$rels = array_filter( array_map( 'trim', explode( ' ', $rel ) ) );
					if ( ! in_array( 'nofollow', $rels, true ) ) {
						$rels[] = 'nofollow';
					}
					$a->setAttribute( 'rel', implode( ' ', $rels ) );
				}
			}
		}

		// Strip images?
		$strip_images = get_post_meta( $campaign_id, '_aba_strip_images', true );
		if ( '1' === $strip_images ) {
			$imgs = $dom->getElementsByTagName( 'img' );
			$to_remove = array();
			foreach ( $imgs as $img ) {
				$to_remove[] = $img;
			}
			foreach ( $to_remove as $img ) {
				if ( $img->parentNode ) {
					$img->parentNode->removeChild( $img );
				}
			}
		}

		// Final HTML
		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		$inner = '';
		if ( $body ) {
			foreach ( $body->childNodes as $child ) {
				$inner .= $dom->saveHTML( $child );
			}
		} else {
			$inner = $dom->saveHTML();
		}

		libxml_clear_errors();

		// Return sanitized HTML (allow some tags)
		$allowed = wp_kses_allowed_html( 'post' );
		$clean_html = wp_kses( $inner, $allowed );

		return $clean_html;
	}

	/**
	 * Check word count and keyword filters.
	 *
	 * @param int    $campaign_id
	 * @param string $html
	 * @return bool True if passes filters
	 */
	private static function passes_filters( $campaign_id, $html ) {
		$text = wp_strip_all_tags( $html );
		$words = str_word_count( $text );

		$min_wc = intval( get_post_meta( $campaign_id, '_aba_min_word_count', true ) );
		$max_wc = intval( get_post_meta( $campaign_id, '_aba_max_word_count', true ) );

		if ( $min_wc > 0 && $words < $min_wc ) {
			return false;
		}
		if ( $max_wc > 0 && $words > $max_wc ) {
			return false;
		}

		$required_raw = get_post_meta( $campaign_id, '_aba_required_keywords', true );
		if ( $required_raw ) {
			$required = array_map( 'trim', explode( ',', $required_raw ) );
			foreach ( $required as $kw ) {
				if ( $kw && false === stripos( $text, $kw ) ) {
					return false;
				}
			}
		}

		$banned_raw = get_post_meta( $campaign_id, '_aba_banned_keywords', true );
		if ( $banned_raw ) {
			$banned = array_map( 'trim', explode( ',', $banned_raw ) );
			foreach ( $banned as $kw ) {
				if ( $kw && false !== stripos( $text, $kw ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Call AI provider to rewrite content.
	 *
	 * For now we support 'gemini' and 'gpt4' model strings.
	 *
	 * @param int    $campaign_id
	 * @param string $model
	 * @param string $prompt_template
	 * @param string $content
	 * @param string $title
	 * @param string $source_url
	 * @return string|null rewritten content
	 */
	private static function call_ai_rewrite( $campaign_id, $model, $prompt_template, $content, $title = '', $source_url = '' ) {
		$settings = get_option( ABA_Plugin::OPTION_KEY, array() );

		// Example: pick API key based on model
		$api_key = '';
		$api_url = ''; // endpoint

		if ( 'gpt4' === $model ) {
			$api_key = isset( $settings['openai_api_key'] ) ? $settings['openai_api_key'] : '';
			$api_url = 'https://api.openai.com/v1/chat/completions'; // update if required
		} else {
			$api_key = isset( $settings['gemini_api_key'] ) ? $settings['gemini_api_key'] : '';
			$api_url = 'https://generativelanguage.googleapis.com/models:generateContent'; // placeholder — replace with actual Gemini endpoint
		}

		if ( empty( $api_key ) || empty( $api_url ) ) {
			// If no API configured, return original content
			return $content;
		}

		// Prepare the prompt by inserting content safely (truncate if too long).
		$max_length = 5000;
		$plain = wp_strip_all_tags( $content );
		if ( mb_strlen( $plain ) > $max_length ) {
			$plain = mb_substr( $plain, 0, $max_length ) . '...';
		}

		$prompt = str_replace( '[content]', $plain, $prompt_template );
		$prompt = str_replace( '[title]', $title, $prompt );
		$prompt = str_replace( '[source_url]', $source_url, $prompt );

		$headers = array(
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $api_key,
		);

		$body = array();

		// Example request body for OpenAI ChatCompletion
		if ( 'gpt4' === $model ) {
			$body = array(
				'model'     => 'gpt-4',
				'messages'  => array(
					array( 'role' => 'system', 'content' => 'You are a helpful assistant that rewrites articles.' ),
					array( 'role' => 'user', 'content' => $prompt ),
				),
				'temperature' => 0.7,
				'max_tokens'  => 1200,
			);
		} else {
			// Gemini placeholder — adapt to real API payload
			$body = array(
				'prompt' => $prompt,
				'max_output_tokens' => 1200,
			);
		}

		$args = array(
			'headers' => $headers,
			'body'    => wp_json_encode( $body ),
			'timeout' => 30,
		);

		$response = wp_remote_post( $api_url, $args );
		if ( is_wp_error( $response ) ) {
			error_log( 'ABA: AI request failed: ' . $response->get_error_message() );
			return $content;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$resp_body = wp_remote_retrieve_body( $response );
		if ( 200 !== intval( $code ) && 201 !== intval( $code ) ) {
			error_log( 'ABA: AI returned non-200: ' . $code . ' body: ' . substr( $resp_body, 0, 300 ) );
			return $content;
		}

		$decoded = json_decode( $resp_body, true );
		if ( ! is_array( $decoded ) ) {
			// best-effort: return entire response as text
			return wp_strip_all_tags( $resp_body );
		}

		// Parse response for known shape: OpenAI chat completions
		if ( isset( $decoded['choices'][0]['message']['content'] ) ) {
			return wp_kses_post( $decoded['choices'][0]['message']['content'] );
		}

		// For Gemini placeholder, maybe 'text' or 'output' keys
		if ( isset( $decoded['output'] ) && is_string( $decoded['output'] ) ) {
			return wp_kses_post( $decoded['output'] );
		}

		// Fall back to original content
		return $content;
	}

	/**
	 * Find first image URL in HTML content.
	 *
	 * @param string $html
	 * @return string|null
	 */
	private static function find_first_image_url( $html ) {
		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$loaded = $dom->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ) );
		if ( ! $loaded ) {
			libxml_clear_errors();
			return null;
		}

		$imgs = $dom->getElementsByTagName( 'img' );
		foreach ( $imgs as $img ) {
			$src = $img->getAttribute( 'src' );
			$src = trim( $src );
			if ( $src && ( 0 === strpos( $src, 'http' ) || 0 === strpos( $src, '//' ) ) ) {
				// Normalize protocol-relative URLs
				if ( 0 === strpos( $src, '//' ) ) {
					$src = ( is_ssl() ? 'https:' : 'http:' ) . $src;
				}
				libxml_clear_errors();
				return esc_url_raw( $src );
			}
		}
		libxml_clear_errors();
		return null;
	}

	/**
	 * Download remote image and add to Media Library; returns attachment ID or WP_Error.
	 *
	 * @param string $image_url
	 * @return int|WP_Error
	 */
	private static function sideload_image_to_media( $image_url ) {
		if ( empty( $image_url ) ) {
			return new WP_Error( 'no_image', 'No image URL provided' );
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Use media_sideload_image to save to library; it returns HTML on success or WP_Error
		$tmp = media_sideload_image( esc_url_raw( $image_url ), 0, null );

		// media_sideload_image returns <img> HTML string if successful. We need to find the attachment id.
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		// Try to find the attachment by source URL
		$attachments = get_posts( array(
			'post_type' => 'attachment',
			'numberposts' => 1,
			'orderby' => 'post_date',
			'order' => 'DESC',
			'meta_query' => array(
				array(
					'key' => '_wp_attachment_metadata',
					'value' => '',
					'compare' => '!=',
				),
			),
		) );

		// Best-effort: attempt to match by URL (heavy but practical)
		$uploads = wp_upload_dir();
		$basename = wp_basename( $image_url );
		$found = null;

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 5,
			'orderby'        => 'date',
		);
		$recent = get_posts( $args );
		foreach ( $recent as $att ) {
			$att_url = wp_get_attachment_url( $att->ID );
			if ( ! $att_url ) {
				continue;
			}
			if ( false !== stripos( $att_url, $basename ) ) {
				$found = $att->ID;
				break;
			}
		}

		if ( $found ) {
			return $found;
		}

		// If not found, return a WP_Error to allow caller to ignore featured image
		return new WP_Error( 'not_found', 'Attachment not found' );
	}
}
