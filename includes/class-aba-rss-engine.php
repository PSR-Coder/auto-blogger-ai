<?php
defined( 'ABSPATH' ) || exit;

/**
 * ABA_RSS_Engine
 *
 * Responsible for running a campaign: fetching feed, deduping, scraping full content,
 * invoking processor and creating posts.
 */
class ABA_RSS_Engine {

	/**
	 * Run a campaign by ID.
	 *
	 * @param int $campaign_id
	 */
	public static function run_campaign( $campaign_id ) {
		$campaign_id = intval( $campaign_id );
		if ( ! $campaign_id ) {
			return;
		}

		$feed_url  = get_post_meta( $campaign_id, '_aba_feed_url', true );
		$max_posts = intval( get_post_meta( $campaign_id, '_aba_max_posts', true ) );
		$max_posts = $max_posts > 0 ? $max_posts : 2000;
		$check_latest = get_post_meta( $campaign_id, '_aba_check_latest', true );

		if ( ! $feed_url ) {
			error_log( "ABA: Campaign {$campaign_id} has no feed URL configured." );
			return;
		}

		include_once ABSPATH . WPINC . '/feed.php'; // fetch_feed
		$rss = fetch_feed( $feed_url );

		if ( is_wp_error( $rss ) ) {
			error_log( 'ABA: fetch_feed error: ' . $rss->get_error_message() );
			return;
		}

		$maxitems = $rss->get_item_quantity( $max_posts );
		$rss_items = $rss->get_items( 0, $maxitems );

		// Retrieve already imported GUIDs for this campaign for deduplication.
		$imported = get_post_meta( $campaign_id, '_aba_imported_guids', true );
		if ( ! is_array( $imported ) ) {
			$imported = array();
		}

		foreach ( $rss_items as $item ) {
			/** @var SimplePie_Item $item */
			$guid = $item->get_id(); // GUID
			$link = $item->get_link();
			$title = $item->get_title();
			$date = $item->get_date( 'U' );

			if ( empty( $guid ) ) {
				$guid = md5( $link ); // fallback
			}
			if ( in_array( $guid, $imported, true ) ) {
				// Skip duplicates
				continue;
			}

			// Optionally respect only-latest: you could check stored last run timestamp and skip older items.
			// For simplicity, we process every new GUID not yet imported.

			// Scrape full content from the linked page.
			$css_selector = get_post_meta( $campaign_id, '_aba_css_selector', true );
			$method = get_post_meta( $campaign_id, '_aba_extraction_method', true );
			$full_content = self::scrape_full_content( $link, $method, $css_selector );

			if ( ! $full_content ) {
				// As fallback, use the feed's description or content
				$full_content = $item->get_description();
			}

			// Send to processor (cleaning, AI rewriting, image handling), returns array with processed content and optionally image attachment ID.
			$processor_result = ABA_Processor::process_content( $campaign_id, $title, $full_content, $link );

			// Handle post creation if processor returned usable content and not filtered out
			if ( ! empty( $processor_result['content'] ) ) {

				$post_author = get_post_meta( $campaign_id, '_aba_target_author', true );
				$post_author = $post_author ? intval( $post_author ) : get_current_user_id();

				$post_category = get_post_meta( $campaign_id, '_aba_target_category', true );
				$post_status   = get_post_meta( $campaign_id, '_aba_post_status', true );
				$post_status   = $post_status ? $post_status : 'draft';

				$new_post = array(
					'post_title'   => wp_strip_all_tags( $title ),
					'post_content' => $processor_result['content'],
					'post_status'  => $post_status,
					'post_author'  => $post_author,
					'post_category'=> $post_category ? array( intval( $post_category ) ) : array(),
				);

				$post_id = wp_insert_post( $new_post );

				if ( is_wp_error( $post_id ) ) {
					error_log( 'ABA: wp_insert_post failed: ' . $post_id->get_error_message() );
				} else {
					// Set featured image if provided
					if ( ! empty( $processor_result['featured_attachment_id'] ) ) {
						set_post_thumbnail( $post_id, intval( $processor_result['featured_attachment_id'] ) );
					}

					// Save original source link and campaign mapping
					update_post_meta( $post_id, '_aba_source_url', esc_url_raw( $link ) );
					update_post_meta( $post_id, '_aba_campaign_id', $campaign_id );
					update_post_meta( $post_id, '_aba_source_guid', $guid );

					// Mark GUID as imported
					$imported[] = $guid;
					update_post_meta( $campaign_id, '_aba_imported_guids', $imported );

					// Optionally log success
					error_log( "ABA: Imported post {$post_id} from {$link} (campaign {$campaign_id})" );
				}
			}
		}
	}

	/**
	 * Scrape full article HTML/text from a URL.
	 *
	 * This function uses wp_remote_get and DOMDocument/DOMXPath to extract desired element.
	 * If method == 'css' and css_selector is set, supports simple selectors: tag, .class, #id
	 * If method == 'auto', tries common article containers or largest text node.
	 *
	 * @param string $url
	 * @param string $method 'css'|'auto'
	 * @param string $css_selector
	 * @return string|null Cleaned HTML (innerHTML) or null on failure.
	 */
	private static function scrape_full_content( $url, $method = 'auto', $css_selector = '' ) {
		$response = wp_remote_get( esc_url_raw( $url ), array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) ) {
			error_log( 'ABA: wp_remote_get failed: ' . $response->get_error_message() );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 !== intval( $code ) || empty( $body ) ) {
			return null;
		}

		// Suppress warnings while parsing HTML.
		libxml_use_internal_errors( true );

		$dom = new DOMDocument();
		// Ensure proper encoding
		$loaded = $dom->loadHTML( mb_convert_encoding( $body, 'HTML-ENTITIES', 'UTF-8' ) );
		if ( ! $loaded ) {
			libxml_clear_errors();
			return null;
		}

		$xpath = new DOMXPath( $dom );

		// If CSS selector mode and css_selector provided
		if ( 'css' === $method && $css_selector ) {
			$nodes = self::dom_query_selector( $dom, $css_selector );
			if ( ! empty( $nodes ) ) {
				$html = '';
				foreach ( $nodes as $node ) {
					$html .= self::get_inner_html( $node );
				}
				libxml_clear_errors();
				return $html;
			}
		}

		// Auto method: try common article containers
		$common_selectors = array(
			'//article',
			"//div[contains(@class,'entry-content')]",
			"//div[contains(@class,'post-content')]",
			"//div[contains(@class,'article-content')]",
			"//div[contains(@class,'content')]",
		);

		foreach ( $common_selectors as $sel ) {
			$nodes = $xpath->query( $sel );
			if ( $nodes && $nodes->length ) {
				// choose the node with biggest text length
				$best = null;
				$best_len = 0;
				foreach ( $nodes as $node ) {
					$text = trim( $node->textContent );
					if ( mb_strlen( $text ) > $best_len ) {
						$best_len = mb_strlen( $text );
						$best = $node;
					}
				}
				if ( $best && $best_len > 0 ) {
					$html = self::get_inner_html( $best );
					libxml_clear_errors();
					return $html;
				}
			}
		}

		// As last fallback, pick the largest <div> by text length
		$divs = $xpath->query( '//div' );
		$best = null;
		$best_len = 0;
		if ( $divs ) {
			foreach ( $divs as $div ) {
				$text = trim( $div->textContent );
				if ( mb_strlen( $text ) > $best_len ) {
					$best_len = mb_strlen( $text );
					$best = $div;
				}
			}
			if ( $best && $best_len > 0 ) {
				$html = self::get_inner_html( $best );
				libxml_clear_errors();
				return $html;
			}
		}

		libxml_clear_errors();
		return null;
	}

	/**
	 * Convert a very simple CSS selector into DOM queries and return matched nodes.
	 * Supports:
	 *  - tag selectors: article, div, p
	 *  - .classname
	 *  - #idname
	 *  - comma separated selectors (simple)
	 *
	 * If selector is complex this function will attempt a best-effort conversion and return null if unsupported.
	 *
	 * @param DOMDocument $dom
	 * @param string      $selector
	 * @return array|null
	 */
	private static function dom_query_selector( $dom, $selector ) {
		$selector = trim( $selector );
		if ( empty( $selector ) ) {
			return null;
		}

		$xpath = new DOMXPath( $dom );

		$parts = explode( ',', $selector );
		$results = array();

		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( preg_match( '/^\\.([A-Za-z0-9\\-_]+)$/', $part, $m ) ) {
				// .classname
				$classname = $m[1];
				$query = "//*[contains(concat(' ', normalize-space(@class), ' '), ' {$classname} ')]";
			} elseif ( preg_match( '/^#([A-Za-z0-9\\-_]+)$/', $part, $m ) ) {
				// #id
				$id = $m[1];
				$query = "//*[@id='{$id}']";
			} elseif ( preg_match( '/^[A-Za-z0-9]+$/', $part ) ) {
				// tag
				$tag = $part;
				$query = "//{$tag}";
			} else {
				// unsupported complex selector
				continue;
			}

			$nodes = $xpath->query( $query );
			if ( $nodes && $nodes->length ) {
				foreach ( $nodes as $node ) {
					$results[] = $node;
				}
			}
		}

		return ! empty( $results ) ? $results : null;
	}

	/**
	 * Get inner HTML of a DOMNode.
	 *
	 * @param DOMNode $node
	 * @return string
	 */
	private static function get_inner_html( $node ) {
		$innerHTML = '';
		foreach ( $node->childNodes as $child ) {
			$innerHTML .= $node->ownerDocument->saveHTML( $child );
		}
		return $innerHTML;
	}
}
