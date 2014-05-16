<?php

/**
 * @author  PressBooks <code@pressbooks.com>
 * @license GPLv2 (or any later version)
 */

namespace PressBooks\Import\Html;

use PressBooks\Import\Import;
use PressBooks\Book;

class Xhtml extends Import {

	/**
	 * @var string 
	 */
	protected $blob;

	/**
	 * 
	 * @param array $current_import
	 */
	function import( array $current_import ) {

		// fetch the remote content
		$html = wp_remote_get( $current_import['file'] );
		$url = parse_url( $current_import['file'] );
		$path = dirname( $url['path'] );

		$domain = $url['scheme'] . '://' . $url['host'] . $path;
		// get parent directory (with forward slash e.g. /parent)
		// get id
		$id = array_keys( $current_import['chapters'] );

		// front-matter, chapter, or back-matter
		$post_type = $this->determinePostType( $id[0] );
		$chapter_parent = $this->getChapterParent();

		$body = $this->kneadandInsert( $html['body'], $post_type, $chapter_parent, $domain );


		// Done
		return $this->revokeCurrentImport();
	}

	/**
	 * Pummel then insert HTML into our database
	 *
	 * @param string $href
	 * @param string $post_type
	 * @param int $chapter_parent
	 */
	function kneadandInsert( $html, $post_type, $chapter_parent, $domain ) {
		$matches = array();

		// get author
		preg_match( '/(<meta name="author" content=")(.+)">/isU', $html, $matches );

		// get the copyright name, if author is empty
		if ( empty( $matches[1] ) ) {
			preg_match( '/(<meta name="copyright" content=")(.+)">/isU', $html, $matches );
		}
		$author = ( ! empty( $matches[1] ) ? wp_strip_all_tags( $matches[1] ) : '');

		// get the title
		preg_match( '/<title>(.+)<\/title>/', $html, $matches );
		$title = ( ! empty( $matches[1] ) ? wp_strip_all_tags( $matches[1] ) : '__UNKNOWN__' );

		// just get the body
		preg_match( '/(?:<body[^>]*>)(.*)<\/body>/isU', $html, $matches );

		// get rid of stuff we don't need
		$body = $this->regexSearchReplace( $matches[1] );

		// clean it up
		$xhtml = $this->tidy( $body );

		$body = $this->kneadHtml( $xhtml, $post_type, $domain );

		$new_post = array(
		    'post_title' => $title,
		    'post_content' => $body,
		    'post_type' => $post_type,
		    'post_status' => 'draft',
		);

		if ( 'chapter' == $post_type ) {
			$new_post['post_parent'] = $chapter_parent;
		}

		$pid = wp_insert_post( $new_post );

		update_post_meta( $pid, 'pb_show_title', 'on' );
		update_post_meta( $pid, 'pb_export', 'on' );

		Book::consolidatePost( $pid, get_post( $pid ) ); // Reorder		
	}

	protected function regexSearchReplace( $html ) {

		// cherry pick likely content areas
		// HTML5, ungreedy
		preg_match( '/(?:<main[^>]*>)(.*)<\/main>/isU', $html, $matches );
		$html = ( ! empty( $matches[1] )) ? $matches[1] : $html;

		// WP content area, greedy
		preg_match( '/(?:<div id="main"[^>]*>)(.*)<\/div>/is', $html, $matches );
		$html = ( ! empty( $matches[1] )) ? $matches[1] : $html;

		// general content area, greedy
		preg_match( '/(?:<div id="content"[^>]*>)(.*)<\/div>/is', $html, $matches );
		$html = ( ! empty( $matches[1] )) ? $matches[1] : $html;

		// cull 
		// get rid of script tags, ungreedy
		$result = preg_replace( '/(?:<script[^>]*>)(.*)<\/script>/isU', '', $html );
		// get rid of forms, ungreedy
		$result = preg_replace( '/(?:<form[^>]*>)(.*)<\/form>/isU', '', $result );
		// get rid of html5 nav content, ungreedy
		$result = preg_replace( '/(?:<nav[^>]*>)(.*)<\/nav>/isU', '', $result );
		// get rid of html5 footer content, ungreedy
		$result = preg_replace( '/(?:<footer[^>]*>)(.*)<\/footer>/isU', '', $result );
		// get rid of sidebar content, greedy
		$result = preg_replace( '/(?:<div id="sidebar\d{0,}"[^>]*>)(.*)<\/div>/is', '', $result );
		// get rid of comments, greedy
		$result = preg_replace( '/(?:<div id="comments"[^>]*>)(.*)<\/div>/is', '', $result );

		return $result;
	}

	/**
	 * Pummel the HTML into WordPress compatible dough.
	 *
	 * @param string $html
	 * @param string $type front-matter, part, chapter, back-matter, ...
	 * @param string $href original filename, with (relative) path
	 *
	 * @return string
	 */
	function kneadHtml( $html, $type, $domain ) {

		libxml_use_internal_errors( true );

		// Load HTMl snippet into DOMDocument using UTF-8 hack
		$utf8_hack = '<?xml version="1.0" encoding="UTF-8"?>';
		$doc = new \DOMDocument();

		$doc->loadHTML( $utf8_hack . $html );

		// Download images, change to relative paths
		$doc = $this->scrapeAndKneadImages( $doc, $domain );

		// If you are storing multi-byte characters in XML, then saving the XML using saveXML() will create problems.
		// Ie. It will spit out the characters converted in encoded format. Instead do the following:
		$html = $doc->saveXML( $doc->documentElement );

		$errors = libxml_get_errors(); // TODO: Handle errors gracefully
		libxml_clear_errors();

		return $html;
	}

	/**
	 * Parse HTML snippet, save all found <img> tags using media_handle_sideload(), return the HTML with changed <img> paths.
	 *
	 * @param \DOMDocument $doc
	 * @param string $href original filename, with (relative) path
	 *
	 * @return \DOMDocument
	 */
	protected function scrapeAndKneadImages( \DOMDocument $doc, $domain ) {

		$images = $doc->getElementsByTagName( 'img' );
		foreach ( $images as $image ) {
			// Fetch image, change src
			$old_src = $image->getAttribute( 'src' );

			// change to absolute links, if relative found
			if ( false === strpos( $old_src, 'http' ) ) {
				$old_src = $domain . '/' . $old_src;
			}

			$new_src = $this->fetchAndSaveUniqueImage( $old_src );

			if ( $new_src ) {
				// Replace with new image
				$image->setAttribute( 'src', $new_src );
			} else {
				// Tag broken image
				$image->setAttribute( 'src', "{$old_src}#fixme" );
			}
		}

		return $doc;
	}

	/**
	 * Extract url and load into WP using media_handle_sideload()
	 * Will return an empty string if something went wrong.
	 *
	 * @param $url         string
	 * @param string $href original filename, with (relative) path
	 *
	 * @see media_handle_sideload
	 *
	 * @return string filename
	 * @throws \Exception
	 */
	protected function fetchAndSaveUniqueImage( $url ) {

		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return '';
		}

		$remote_img_location = $url;

		// Cheap cache
		static $already_done = array();
		if ( isset( $already_done[$remote_img_location] ) ) {
			return $already_done[$remote_img_location];
		}

		/* Process */

		// Basename without query string
		$filename = explode( '?', basename( $url ) );
		$filename = array_shift( $filename );

		$filename = sanitize_file_name( urldecode( $filename ) );

		if ( ! preg_match( '/\.(jpe?g|gif|png)$/i', $filename ) ) {
			// Unsupported image type
			$already_done[$remote_img_location] = '';
			return '';
		}

		$tmp_name = download_url( $remote_img_location );
		if ( is_wp_error( $tmp_name ) ) {
			// Download failed
			$already_done[$remote_img_location] = '';
			return '';
		}

		if ( ! \PressBooks\Image\is_valid_image( $tmp_name, $filename ) ) {

			try { // changing the file name so that extension matches the mime type
				$filename = $this->properImageExtension( $tmp_name, $filename );

				if ( ! \PressBooks\Image\is_valid_image( $tmp_name, $filename ) ) {
					throw new \Exception( 'Image is corrupt, and file extension matches the mime type' );
				}
			} catch ( \Exception $exc ) {
				// Garbage, don't import
				$already_done[$remote_img_location] = '';
				unlink( $tmp_name );
				return '';
			}
		}

		$pid = media_handle_sideload( array( 'name' => $filename, 'tmp_name' => $tmp_name ), 0 );
		$src = wp_get_attachment_url( $pid );
		if ( ! $src ) $src = ''; // Change false to empty string
		$already_done[$remote_img_location] = $src;
		@unlink( $tmp_name );

		return $src;
	}

	/**
	 * 
	 * @param array $upload
	 */
	function setCurrentImportOption( array $upload ) {
		// just get the body of the array
		$html = $upload['body'];

		// safety check if param (character encoding) with `;` isn't set
		// @see http://www.w3.org/Protocols/rfc2616/rfc2616-sec3.html#sec3.7
		$content_type = ( false === strstr( $upload['headers']['content-type'], ';' )) ? $content_type = $upload['headers']['content-type'] : strstr( $upload['headers']['content-type'], ';', true );

		// get the title
		preg_match( '/<title>(.+)<\/title>/', $html, $matches );
		$title = ( ! empty( $matches[1] ) ? wp_strip_all_tags( $matches[1] ) : '__UNKNOWN__' );

		// set the args
		$option = array(
		    'file' => $upload['url'],
		    'file_type' => $content_type,
		    'type_of' => 'html',
		    'chapters' => array(),
		);

		// there will be only one chapter 
		$option['chapters'][1] = $title;

		return update_option( 'pressbooks_current_import', $option );
	}

	/**
	 * Compliance with XTHML standards, rid cruft generated by word processors
	 *
	 * @param string $html
	 *
	 * @return string
	 */
	protected function tidy( $html ) {

		// Reduce the vulnerability for scripting attacks
		// Make XHTML 1.1 strict using htmlLawed

		$config = array(
		    'deny_attribute' => 'style',
		    'comment' => 1,
		    'safe' => 1,
		    'valid_xhtml' => 1,
		    'no_deprecated_attr' => 2,
		    'hook' => '\PressBooks\Sanitize\html5_to_xhtml11',
		);

		return htmLawed( $html, $config );
	}

}
