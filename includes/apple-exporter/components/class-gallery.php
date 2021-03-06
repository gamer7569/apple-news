<?php
/**
 * Publish to Apple News Includes: Apple_Exporter\Components\Gallery class
 *
 * Contains a class which is used to transform galleries into Apple News format.
 *
 * @package Apple_News
 * @subpackage Apple_Exporter
 * @since 0.2.0
 */

namespace Apple_Exporter\Components;

use \DOMDocument;
use \DOMElement;

/**
 * A class to translate the output of [gallery] shortcodes into Apple News format.
 *
 * An image gallery is just a container with 'gallery' class and some images
 * inside. The container should be a div, but can be anything as long as it has
 * a 'gallery' class.
 *
 * @since 0.2.0
 */
class Gallery extends Component {

	/**
	 * Look for node matches for this component.
	 *
	 * @param DOMElement $node The node to examine.
	 *
	 * @access public
	 * @return DOMElement|null The DOMElement on match, false on no match.
	 */
	public static function node_matches( $node ) {
		return ( self::node_has_class( $node, 'gallery' ) ) ? $node : null;
	}

	/**
	 * Register all specs for the component.
	 *
	 * @access public
	 */
	public function register_specs() {
		$this->register_spec(
			'json',
			__( 'JSON', 'apple-news' ),
			array(
				'role' => '#gallery_type#',
				'items' => '#items#',
			)
		);

		$this->register_spec(
			'gallery-layout',
			__( 'Layout', 'apple-news' ),
			array(
				'margin' => array(
					'bottom' => 25,
					'top' => 25,
				),
			)
		);
	}

	/**
	 * Build the component.
	 *
	 * @param string $text The HTML to parse.
	 *
	 * @access protected
	 */
	protected function build( $text ) {

		// Convert the text into a NodeList.
		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $text );
		libxml_clear_errors();
		libxml_use_internal_errors( false );
		$nodes = $dom->getElementsByTagName( 'body' )->item( 0 )->childNodes;

		// Determine if we have items.
		if ( ! $nodes || ! $nodes->item( 0 )->childNodes ) {
			return;
		}

		// Loop through items and construct slides.
		$items = array();
		foreach ( $nodes->item( 0 )->childNodes as $item ) {

			// Convert item into HTML for regex matching.
			$itemHTML = $item->ownerDocument->saveXML( $item );

			// Try to get URL.
			if ( ! preg_match( '/src="([^"]+)"/', $itemHTML, $matches ) ) {
				continue;
			}

			// Start building the item.
			$content = array(
				'URL' => $this->maybe_bundle_source( $matches[1] ),
			);

			// Try to add the caption.
			$caption = $item->getElementsByTagName( 'figcaption' );
			if ( $caption && $caption->length ) {
				$content['caption'] = array(
					'text' => sanitize_text_field(
						trim( $caption->item(0)->nodeValue )
					),
				);
			}

			// Try to add the alt text as the accessibility caption.
			if ( preg_match( '/alt="([^"]+)"/', $itemHTML, $matches ) ) {
				$content['accessibilityCaption'] = sanitize_text_field(
					$matches[1]
				);
			}

			// Add the compiled slide content to the list of items.
			$items[] = $content;
		}

		// Build the JSON
		$this->register_json(
			'json',
			array(
				'#gallery_type#' => $this->get_setting( 'gallery_type' ),
				'#items#' => $items,
			)
	 	);

		// Set the layout.
		$this->set_layout();
	}

	/**
	 * Set the layout for the component.
	 *
	 * @access private
	 */
	private function set_layout() {
		$this->register_full_width_layout(
			'gallery-layout',
			'gallery-layout',
			array(),
			'layout'
		);
	}
}
