<?php
declare( strict_types = 1 );

namespace Wikimedia\Parsoid\Wt2Html\TT;

use Wikimedia\Assert\Assert;
use Wikimedia\Parsoid\Config\WikitextConstants as Consts;
use Wikimedia\Parsoid\Tokens\CommentTk;
use Wikimedia\Parsoid\Tokens\EndTagTk;
use Wikimedia\Parsoid\Tokens\EOFTk;
use Wikimedia\Parsoid\Tokens\NlTk;
use Wikimedia\Parsoid\Tokens\SelfclosingTagTk;
use Wikimedia\Parsoid\Tokens\TagTk;
use Wikimedia\Parsoid\Tokens\Token;
use Wikimedia\Parsoid\Utils\PHPUtils;
use Wikimedia\Parsoid\Utils\TokenUtils;
use Wikimedia\Parsoid\Wt2Html\TokenTransformManager;

/**
 * Insert paragraph tags where needed -- smartly and carefully
 * -- there is much fun to be had mimicking "wikitext visual newlines"
 * behavior as implemented by the PHP parser.
 */
class ParagraphWrapper extends TokenHandler {
	/** @var bool */
	private $inPre;

	/** @var bool */
	private $hasOpenPTag;

	/** @var bool */
	private $inBlockElem;

	/** @var bool */
	private $inBlockquote;

	/**
	 * The state machine in the PreHandler is line based and only suppresses
	 * indent-pres when encountering blocks on a line.  However, the legacy
	 * parser's `doBlockLevels` has a concept of being "$inBlockElem", which
	 * is mimicked here.  Rather than replicate that awareness in both passes,
	 * we piggyback on it here to undo indent-pres when they're found to be
	 * undesirable.
	 *
	 * @var bool
	 */
	private $undoIndentPre;

	/** @var array */
	private $tokenBuffer;

	/** @var array */
	private $nlWsTokens;

	/** @var int */
	private $newLineCount;

	/** @var array */
	private $currLine;

	/**
	 * Constructor for paragraph wrapper.
	 * @param TokenTransformManager $manager manager enviroment
	 * @param array $options various configuration options
	 */
	public function __construct( TokenTransformManager $manager, array $options ) {
		parent::__construct( $manager, $options );
		$this->inPre = false;
		$this->undoIndentPre = false;
		$this->hasOpenPTag = false;
		$this->inBlockElem = false;
		$this->inBlockquote = false;
		$this->tokenBuffer = [];
		$this->nlWsTokens = [];
		$this->newLineCount = 0;
		$this->currLine = null;
		// Disable p-wrapper
		$this->disabled = !empty( $this->options['inlineContext'] );
		$this->reset();
	}

	/**
	 * @inheritDoc
	 */
	public function onNewline( NlTk $token ) {
		return $this->inPre ? $token : $this->onNewlineOrEOF( $token );
	}

	/**
	 * @inheritDoc
	 */
	public function onEnd( EOFTk $token ) {
		return $this->onNewlineOrEOF( $token );
	}

	/**
	 * Reset the token buffer and related info
	 * This is the ordering of buffered tokens and how they should get emitted:
	 *
	 * token-buffer         (from previous lines if newLineCount > 0)
	 * newline-ws-tokens    (buffered nl+sol-transparent tokens since last non-nl-token)
	 * current-line-tokens  (all tokens after newline-ws-tokens)
	 *
	 * newline-token-count is > 0 only when we encounter multiple "empty lines".
	 *
	 * Periodically, when it is clear where an open/close p-tag is required, the buffers
	 * are collapsed and emitted. Wherever tokens are buffered/emitted, verify that this
	 *  order is preserved.
	 */
	private function reset(): void {
		$this->resetBuffers();
		$this->resetCurrLine();
		$this->hasOpenPTag = false;
		$this->inPre = false;
		$this->undoIndentPre = false;
		// NOTE: This flag is the local equivalent of what we're mimicking with
		// the 'inlineContext' pipeline option.
		$this->inBlockElem = false;
		$this->inBlockquote = false;
	}

	/**
	 * Reset the token buffer and new line info
	 *
	 */
	private function resetBuffers(): void {
		$this->tokenBuffer = [];
		$this->nlWsTokens = [];
		$this->newLineCount = 0;
	}

	/**
	 * Reset the current line info
	 *
	 */
	private function resetCurrLine(): void {
		if ( $this->currLine && $this->currLine['blockTagSeen'] ) {
			$this->inBlockElem = $this->currLine['blockTagOpen'];
		}
		$this->currLine = [
			'tokens' => [],
			'hasWrappableTokens' => false,
			'blockTagSeen' => false,
			'blockTagOpen' => false,
		];
	}

	/**
	 * Process the current buffer contents and token provided
	 *
	 * @param Token|string $token token
	 * @param bool $flushCurrentLine option to flush current line or preserve it
	 * @return array
	 */
	private function processBuffers( $token, bool $flushCurrentLine ): array {
		$res = $this->processPendingNLs();
		$this->currLine['tokens'][] = $token;
		if ( $flushCurrentLine ) {
			$res = array_merge( $res, $this->currLine['tokens'] );
			$this->resetCurrLine();
		}
		$this->env->log( 'trace/p-wrap', $this->manager->pipelineId, '---->  ', static function () use( $res ) {
			return PHPUtils::jsonEncode( $res );
		} );
		return $res;
	}

	/**
	 * Process and flush existing buffer contents
	 *
	 * @param Token|string $token token
	 * @return array
	 */
	private function flushBuffers( $token ): array {
		Assert::invariant( $this->newLineCount === 0, "PWrap: Trying to flush buffers with pending newlines" );

		$this->currLine['tokens'][] = $token;
		$resToks = array_merge( $this->tokenBuffer, $this->nlWsTokens );
		$this->resetBuffers();
		$this->env->log( 'trace/p-wrap', $this->manager->pipelineId, '---->  ',
			static function () use( $resToks ) {
				return PHPUtils::jsonEncode( $resToks );
			} );
		return $resToks;
	}

	/**
	 * Discard a newline token from buffer
	 *
	 * @param array &$out array to process and update
	 * @return Token|string
	 */
	public function discardOneNlTk( array &$out ) {
		$i = 0;
		$n = count( $this->nlWsTokens );
		while ( $i < $n ) {
			$t = array_shift( $this->nlWsTokens );
			if ( $t instanceof NlTk ) {
				return $t;
			} else {
				$out[] = $t;
			}
			$i++;
		}

		// FIXME: We should return null and fix callers
		return "";
	}

	/**
	 * Search for the opening paragraph tag
	 *
	 * @param array &$out array to process and update
	 */
	private function openPTag( array &$out ): void {
		if ( !$this->hasOpenPTag ) {
			$tplStartIndex = -1;
			// Be careful not to expand template ranges unnecessarily.
			// Look for open markers before starting a p-tag.
			$countOut = count( $out );
			for ( $i = 0; $i < $countOut; $i++ ) {
				$t = $out[$i];
				if ( !is_string( $t ) && $t->getName() === 'meta' ) {
					if ( TokenUtils::hasTypeOf( $t, 'mw:Transclusion' ) ) {
						// We hit a start tag and everything before it is sol-transparent.
						$tplStartIndex = $i;
						continue;
					} elseif ( TokenUtils::matchTypeOf( $t, '#^mw:Transclusion/#' ) ) {
						// End tag. All tokens before this are sol-transparent.
						// Let us leave them all out of the p-wrapping.
						$tplStartIndex = -1;
						continue;
					}
				}
				// Not a transclusion meta; Check for nl/sol-transparent tokens
				// and leave them out of the p-wrapping.
				if ( !TokenUtils::isSolTransparent( $this->env, $t ) && !$t instanceof NlTk ) {
					break;
				}
			}
			if ( $tplStartIndex > -1 ) {
				$i = $tplStartIndex;
			}
			array_splice( $out, $i, 0, [ new TagTk( 'p' ) ] );
			$this->hasOpenPTag = true;
		}
	}

	/**
	 * Search for the closing paragraph tag
	 *
	 * @param array &$out array to process and update
	 */
	private function closeOpenPTag( array &$out ): void {
		if ( $this->hasOpenPTag ) {
			$tplEndIndex = -1;
			// Be careful not to expand template ranges unnecessarily.
			// Look for open markers before closing.
			for ( $i = count( $out ) - 1; $i > -1; $i-- ) {
				$t = $out[$i];
				if ( !is_string( $t ) && $t->getName() === 'meta' ) {
					if ( TokenUtils::hasTypeOf( $t, 'mw:Transclusion' ) ) {
						// We hit a start tag and everything after it is sol-transparent.
						// Don't include the sol-transparent tags OR the start tag.
						$tplEndIndex = -1;
						continue;
					} elseif ( TokenUtils::matchTypeOf( $t, '#^mw:Transclusion/#' ) ) {
						// End tag. The rest of the tags past this are sol-transparent.
						// Let us leave them all out of the p-wrapping.
						$tplEndIndex = $i;
						continue;
					}
				}
				// Not a transclusion meta; Check for nl/sol-transparent tokens
				// and leave them out of the p-wrapping.
				if ( !TokenUtils::isSolTransparent( $this->env, $t ) && !$t instanceof NlTk ) {
					break;
				}
			}
			if ( $tplEndIndex > -1 ) {
				$i = $tplEndIndex;
			}
			array_splice( $out, $i + 1, 0, [ new EndTagTk( 'p' ) ] );
			$this->hasOpenPTag = false;
		}
	}

	/**
	 * Handle newline tokens
	 *
	 * @param Token $token token
	 * @return array
	 */
	private function onNewlineOrEOF( Token $token ): array {
		$this->manager->env->log( 'trace/p-wrap', $this->manager->pipelineId, 'NL    |',
			static function () use( $token ) {
				return PHPUtils::jsonEncode( $token );
			} );
		$l = $this->currLine;
		if ( $l['blockTagSeen'] ) {
			$this->closeOpenPTag( $l['tokens'] );
		} elseif ( !$this->inBlockElem && !$this->hasOpenPTag && $l['hasWrappableTokens'] ) {
			$this->openPTag( $l['tokens'] );
		}

		// Assertion to catch bugs in p-wrapping; both cannot be true.
		if ( $this->newLineCount > 0 && count( $l['tokens'] ) > 0 ) {
			$this->env->log( 'error/p-wrap', 'Failed assertion in onNewlineOrEOF: newline-count:',
			$this->newLineCount, '; current line tokens: ', PHPUtils::jsonEncode( $l['tokens'] ) );
		}

		$this->tokenBuffer = array_merge( $this->tokenBuffer, $l['tokens'] );

		if ( $token instanceof EOFTk ) {
			$this->nlWsTokens[] = $token;
			$this->closeOpenPTag( $this->tokenBuffer );
			$res = $this->processPendingNLs();
			$this->reset();
			$this->env->log( 'trace/p-wrap', $this->manager->pipelineId, '---->  ', static function () use( $res ) {
				return PHPUtils::jsonEncode( $res );
			} );
			return [ 'tokens' => $res, 'skipOnAny' => true ];
		} else {
			$this->resetCurrLine();
			$this->newLineCount++;
			$this->nlWsTokens[] = $token;
			if ( $this->undoIndentPre ) {
				$this->nlWsTokens[] = ' ';
			}
			return [ 'tokens' => [] ];
		}
	}

	/**
	 * Process pending newlines
	 *
	 * @return array
	 */
	private function processPendingNLs(): array {
		$resToks = $this->tokenBuffer;
		$newLineCount = $this->newLineCount;
		$nlTk = null;

		$this->manager->env->log( 'trace/p-wrap', $this->manager->pipelineId, '        NL-count: ',
			$newLineCount );

		if ( $newLineCount >= 2 && !$this->inBlockElem ) {
			$this->closeOpenPTag( $resToks );

			// First is emitted as a literal newline
			$resToks[] = $this->discardOneNlTk( $resToks );
			$newLineCount -= 1;

			$remainder = $newLineCount % 2;

			while ( $newLineCount > 0 ) {
				$nlTk = $this->discardOneNlTk( $resToks );
				if ( $newLineCount % 2 === $remainder ) {
					if ( $this->hasOpenPTag ) {
						$resToks[] = new EndTagTk( 'p' );
						$this->hasOpenPTag = false;
					}
					if ( $newLineCount > 1 ) {
						$resToks[] = new TagTk( 'p' );
						$this->hasOpenPTag = true;
					}
				} else {
					$resToks[] = new SelfclosingTagTk( 'br' );
				}
				$resToks[] = $nlTk;
				$newLineCount -= 1;
			}
		}

		if ( $this->currLine['blockTagSeen'] ) {
			$this->closeOpenPTag( $resToks );
			if ( $newLineCount === 1 ) {
				$resToks[] = $this->discardOneNlTk( $resToks );
			}
		}

		// Gather remaining ws and nl tokens

		$resToks = array_merge( $resToks, $this->nlWsTokens );

		// reset buffers
		$this->resetBuffers();

		return $resToks;
	}

	/**
	 * @inheritDoc
	 */
	public function onAny( $token ): array {
		$this->manager->env->log( 'trace/p-wrap', $this->manager->pipelineId, 'ANY   |',
			static function () use( $token ) {
				return PHPUtils::jsonEncode( $token );
			} );
		$res = null;
		if ( $token instanceof TagTk && $token->getName() === 'pre'
			 && !TokenUtils::isHTMLTag( $token )
		) {
			if ( $this->inBlockElem || $this->inBlockquote ) {
				$this->undoIndentPre = true;
				if ( $this->newLineCount === 0 ) {
					return [ 'tokens' => $this->flushBuffers( ' ' ), 'skipOnAny' => true ];
				} else {
					$this->nlWsTokens[] = ' ';
					return [ 'tokens' => [] ];
				}
			} else {
				$this->inPre = true;
				// This will put us `inBlockElem`, so we need the extra `!inPre`
				// condition below.  Presumably, we couldn't have entered
				// `inBlockElem` while being `inPre`.  Alternatively, we could say
				// that indent-pre is "never suppressing" and set the `blockTagOpen`
				// flag to false. The point of all this is that we want to close
				// any open p-tags.
				$this->currLine['blockTagSeen'] = true;
				$this->currLine['blockTagOpen'] = true;
				// skip ensures this doesn't hit the AnyHandler
				return [ 'tokens' => $this->processBuffers( $token, true ), 'skipOnAny' => true ];
			}
		} elseif ( $token instanceof EndTagTk && $token->getName() === 'pre' &&
			!TokenUtils::isHTMLTag( $token )
		) {
			if ( ( $this->inBlockElem && !$this->inPre ) || $this->inBlockquote ) {
				$this->undoIndentPre = false;
				// No pre-tokens inside block tags -- swallow it.
				return [ 'tokens' => [] ];
			} else {
				$this->inPre = false;
				$this->currLine['blockTagSeen'] = true;
				$this->currLine['blockTagOpen'] = false;
				$this->env->log( 'trace/p-wrap', $this->manager->pipelineId, '---->  ',
					static function () use( $token ) {
						return PHPUtils::jsonEncode( $token );
					} );
				$res = [ $token ];
				// skip ensures this doesn't hit the AnyHandler
				return [ 'tokens' => $res, 'skipOnAny' => true ];
			}
		} elseif ( $token instanceof EOFTk || $this->inPre ) {
			$this->env->log( 'trace/p-wrap', $this->manager->pipelineId, '---->  ',
				static function () use( $token ) {
					return PHPUtils::jsonEncode( $token );
				}
			 );
			$res = [ $token ];
			// skip ensures this doesn't hit the AnyHandler
			return [ 'tokens' => $res, 'skipOnAny' => true ];
		} elseif ( $token instanceof CommentTk
			|| is_string( $token ) && preg_match( '/^[\t ]*$/D', $token )
			|| TokenUtils::isEmptyLineMetaToken( $token )
		) {
			if ( $this->newLineCount === 0 ) {
				// Since we have no pending newlines to trip us up,
				// no need to buffer -- just flush everything
				return [ 'tokens' => $this->flushBuffers( $token ), 'skipOnAny' => true ];
			} else {
				// We are in buffering mode waiting till we are ready to
				// process pending newlines.
				$this->nlWsTokens[] = $token;
				return [ 'tokens' => [] ];
			}
		} elseif ( !is_string( $token ) &&
			// T186965: <style> behaves similarly to sol transparent tokens in
			// that it doesn't open/close paragraphs, but also doesn't induce
			// a new paragraph by itself.
			( TokenUtils::isSolTransparent( $this->env, $token ) || $token->getName() === 'style' )
		) {
			if ( $this->newLineCount === 0 ) {
				// Since we have no pending newlines to trip us up,
				// no need to buffer -- just flush everything
				return [ 'tokens' => $this->flushBuffers( $token ), 'skipOnAny' => true ];
			} elseif ( $this->newLineCount === 1 ) {
				// Swallow newline, whitespace, comments, and the current line
				$this->tokenBuffer = array_merge( $this->tokenBuffer, $this->nlWsTokens );
				$this->tokenBuffer = array_merge( $this->tokenBuffer, $this->currLine['tokens'] );
				$this->newLineCount = 0;
				$this->nlWsTokens = [];
				$this->resetCurrLine();

				// But, don't process the new token yet.
				$this->currLine['tokens'][] = $token;
				return [ 'tokens' => [] ];
			} else {
				return [ 'tokens' => $this->processBuffers( $token, false ), 'skipOnAny' => true ];
			}
		} else {
			if ( !is_string( $token ) ) {
				$name = $token->getName();
				if ( isset( Consts::$wikitextBlockElems[$name] ) ) {
					$this->currLine['blockTagSeen'] = true;
					$this->currLine['blockTagOpen'] = true;
					if (
						( isset( Consts::$blockElems[$name] ) && $token instanceof EndTagTk ) ||
						( isset( Consts::$antiBlockElems[$name] ) && !$token instanceof EndTagTk ) ||
						isset( Consts::$neverBlockElems[$name] )
					) {
						$this->currLine['blockTagOpen'] = false;
					}
				}
				if ( $name === 'blockquote' ) {
					$this->inBlockquote = ( !$token instanceof EndTagTk );
				}
			}
			$this->currLine['hasWrappableTokens'] = true;
			return [ 'tokens' => $this->processBuffers( $token, false ), 'skipOnAny' => true ];
		}
	}
}
