<?php
declare( strict_types = 1 );

namespace Wikimedia\Parsoid\Ext\Cite;

use Exception;
use Wikimedia\Parsoid\DOM\DocumentFragment;
use Wikimedia\Parsoid\DOM\Element;
use Wikimedia\Parsoid\DOM\Node;
use Wikimedia\Parsoid\Ext\DOMDataUtils;
use Wikimedia\Parsoid\Ext\DOMUtils;
use Wikimedia\Parsoid\Ext\ExtensionTagHandler;
use Wikimedia\Parsoid\Ext\ParsoidExtensionAPI;
use Wikimedia\Parsoid\Ext\WTUtils;
use Wikimedia\Parsoid\Utils\DOMCompat;

/**
 * Simple token transform version of the Ref extension tag.
 */
class Ref extends ExtensionTagHandler {

	/** @inheritDoc */
	public function sourceToDom(
		ParsoidExtensionAPI $extApi, string $txt, array $extArgs
	): ?DocumentFragment {
		// Drop nested refs entirely, unless we've explicitly allowed them
		$parentExtTag = $extApi->parentExtTag();
		if ( $parentExtTag === 'ref' && empty( $extApi->parentExtTagOpts()['allowNestedRef'] ) ) {
			return null;
		}

		// The one supported case for nested refs is from the {{#tag:ref}} parser
		// function.  However, we're overly permissive here since we can't
		// distinguish when that's nested in another template.
		// The php preprocessor did our expansion.
		$allowNestedRef = !empty( $extApi->inTemplate() ) && $parentExtTag !== 'ref';

		return $extApi->extTagToDOM(
			$extArgs,
			'',
			$txt,
			[
				// NOTE: sup's content model requires it only contain phrasing
				// content, not flow content. However, since we are building an
				// in-memory DOM which is simply a tree data structure, we can
				// nest flow content in a <sup> tag.
				'wrapperTag' => 'sup',
				'parseOpts' => [
					'extTag' => 'ref',
					'extTagOpts' => [ 'allowNestedRef' => $allowNestedRef ],
					// Ref content doesn't need p-wrapping or indent-pres.
					// Treat this as inline-context content to get that b/c behavior.
					'context' => 'inline',
				],
			]
		);
	}

	/** @inheritDoc */
	public function lintHandler(
		ParsoidExtensionAPI $extApi, Element $ref, callable $defaultHandler
	): ?Node {
		// Don't lint the content of ref in ref, since it can lead to cycles
		// using named refs
		if ( WTUtils::fromExtensionContent( $ref, 'references' ) ) {
			return $ref->nextSibling;
		}
		// Ignore content from reference errors
		if ( DOMUtils::hasTypeOf( $ref, 'mw:Error' ) ) {
			return $ref->nextSibling;
		}
		$refFirstChild = $ref->firstChild;
		DOMUtils::assertElt( $refFirstChild );
		$linkBackId = preg_replace( '/[^#]*#/', '', $refFirstChild->getAttribute( 'href' ) ?? '', 1 );
		$refNode = $ref->ownerDocument->getElementById( $linkBackId );
		if ( $refNode ) {
			// Ex: Buggy input wikitext without ref content
			$defaultHandler( $refNode->lastChild );
		}
		return $ref->nextSibling;
	}

	/** @inheritDoc */
	public function domToWikitext(
		ParsoidExtensionAPI $extApi, Element $node, bool $wrapperUnmodified
	) {
		$startTagSrc = $extApi->extStartTagToWikitext( $node );
		$dataMw = DOMDataUtils::getDataMw( $node );
		$html = null;
		if ( !isset( $dataMw->body ) ) {
			return $startTagSrc; // We self-closed this already.
		}

		$html2wtOpts = [
			'extName' => $dataMw->name,
			// FIXME: One-off PHP parser state leak. This needs a better solution.
			'inPHPBlock' => true
		];

		if ( is_string( $dataMw->body->html ?? null ) ) {
			// First look for the extension's content in data-mw.body.html
			$src = $extApi->htmlToWikitext( $html2wtOpts, $dataMw->body->html );
		} elseif ( is_string( $dataMw->body->id ?? null ) ) {
			// If the body isn't contained in data-mw.body.html, look if
			// there's an element pointed to by body->id.
			$bodyElt = DOMCompat::getElementById( $extApi->getTopLevelDoc(), $dataMw->body->id );

			// So far, this is specified for Cite and relies on the "id"
			// referring to an element in the top level dom, even though the
			// <ref> itself may be in embedded content,
			// https://www.mediawiki.org/wiki/Specs/HTML/Extensions/Cite#Ref_and_References
			// FIXME: This doesn't work if the <references> section
			// itself is in embedded content, since we aren't traversing
			// in there.

			// If we couldn't find a body element, this is a bug.
			// Add some extra debugging for the editing client (ex: VisualEditor)
			if ( !$bodyElt ) {
				$extraDebug = '';
				$firstA = DOMCompat::querySelector( $node, 'a[href]' );
				if ( $firstA && preg_match( '/^#/', $firstA->getAttribute( 'href' ) ?? '' ) ) {
					$href = $firstA->getAttribute( 'href' ) ?? '';
					try {
						$ref = DOMCompat::querySelector( $extApi->getTopLevelDoc(), $href );
						if ( $ref ) {
							$extraDebug .= ' [doc: ' . DOMCompat::getOuterHTML( $ref ) . ']';
						}
					} catch ( Exception $e ) {
						// We are just providing VE with debugging info.
						// So, ignore all exceptions / errors in this code.
					}
					if ( !$extraDebug ) {
						$extraDebug = ' [reference ' . $href . ' not found]';
					}
				}
				$extApi->log(
					'error/' . $dataMw->name,
					'extension src id ' . $dataMw->body->id . ' points to non-existent element for:',
					DOMCompat::getOuterHTML( $node ),
					'. More debug info: ',
					$extraDebug
				);
				return ''; // Drop it!
			}

			$hasRefName = strlen( $dataMw->attrs->name ?? '' ) > 0;
			$hasFollow = strlen( $dataMw->attrs->follow ?? '' ) > 0;

			if ( $hasFollow ) {
				$about = $node->getAttribute( 'about' ) ?? '';
				$followNode = DOMCompat::querySelector(
					$bodyElt, "span[typeof~='mw:Cite/Follow'][about='{$about}']"
				);
				if ( $followNode ) {
					$src = $extApi->domToWikitext( $html2wtOpts, $followNode, true );
					$src = ltrim( $src, ' ' );
				} else {
					$src = '';
				}
			} else {
				if ( $hasRefName ) {
					// Follow content may have been added as spans, so drop it
					if ( DOMCompat::querySelector( $bodyElt, "span[typeof~='mw:Cite/Follow']" ) ) {
						$bodyElt = $bodyElt->cloneNode( true );
						foreach ( $bodyElt->childNodes as $child ) {
							if ( DOMUtils::hasTypeOf( $child, 'mw:Cite/Follow' ) ) {
								DOMCompat::remove( $child );
							}
						}
					}
				}

				$src = $extApi->domToWikitext( $html2wtOpts, $bodyElt, true );
			}
		} else {
			$extApi->log( 'error', 'Ref body unavailable for: ' . DOMCompat::getOuterHTML( $node ) );
			return ''; // Drop it!
		}

		return $startTagSrc . $src . '</' . $dataMw->name . '>';
	}
}
