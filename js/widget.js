/**
 * AI FAQ Chatbot: the chat window.
 *
 * Builds the window with DOM methods and textContent only, so nothing from
 * the server or the visitor is ever parsed as HTML. Source numbers in the
 * answer ([1], [2]) become links to the pages listed under it.
 */
( function () {
	'use strict';

	var config = window.aifaqChat;
	if ( ! config ) {
		return;
	}
	var t = config.i18n;

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text ) {
			node.textContent = text;
		}
		return node;
	}

	// Only http(s) links; an empty value stays empty (new URL( '' ) would resolve to this page).
	function safeUrl( url ) {
		if ( ! url ) {
			return '';
		}
		try {
			var parsed = new URL( url, window.location.href );
			return parsed.protocol === 'https:' || parsed.protocol === 'http:' ? parsed.href : '';
		} catch ( e ) {
			return '';
		}
	}

	// Answer text with [n] markers turned into links to source n.
	function answerNode( text, sources ) {
		var node = el( 'p', 'aifaq-chat__text' );
		var byNumber = {};
		sources.forEach( function ( s ) {
			byNumber[ s.n ] = s;
		} );
		text.split( /(\[\d+\])/ ).forEach( function ( part ) {
			var match = part.match( /^\[(\d+)\]$/ );
			var source = match ? byNumber[ match[ 1 ] ] : null;
			var url = source ? safeUrl( source.url ) : '';
			if ( url ) {
				var link = el( 'a', 'aifaq-chat__cite', match[ 1 ] );
				link.href = url;
				link.title = source.title + ( source.heading ? ' › ' + source.heading : '' );
				node.appendChild( link );
			} else if ( ! match ) {
				node.appendChild( document.createTextNode( part ) );
			}
		} );
		return node;
	}

	function sourcesNode( sources ) {
		var wrap = el( 'div', 'aifaq-chat__sources' );
		wrap.appendChild( el( 'span', 'aifaq-chat__sources-label', t.sources ) );
		var list = el( 'ol', 'aifaq-chat__source-list' );
		sources.forEach( function ( s ) {
			var url = safeUrl( s.url );
			var item = el( 'li' );
			var label = s.title + ( s.heading ? ' › ' + s.heading : '' );
			if ( url ) {
				var link = el( 'a', '', label );
				link.href = url;
				item.appendChild( link );
			} else {
				item.textContent = label;
			}
			list.appendChild( item );
		} );
		wrap.appendChild( list );
		return wrap;
	}

	function build( root ) {
		var floating = root.getAttribute( 'data-aifaq-mode' ) === 'floating';
		var panelId = 'aifaq-chat-panel-' + Math.random().toString( 36 ).slice( 2 );

		var panel = el( 'section', 'aifaq-chat__panel' );
		panel.id = panelId;
		panel.setAttribute( 'aria-label', config.title );

		var header = el( 'div', 'aifaq-chat__header' );
		header.appendChild( el( 'h2', 'aifaq-chat__title', config.title ) );
		panel.appendChild( header );

		var log = el( 'div', 'aifaq-chat__log' );
		log.setAttribute( 'role', 'log' );
		log.setAttribute( 'aria-live', 'polite' );
		panel.appendChild( log );

		var form = el( 'form', 'aifaq-chat__form' );
		var input = el( 'textarea', 'aifaq-chat__input' );
		input.rows = 2;
		input.maxLength = config.maxLength;
		input.placeholder = t.placeholder;
		input.setAttribute( 'aria-label', t.placeholder );
		var send = el( 'button', 'aifaq-chat__send', t.send );
		send.type = 'submit';
		form.appendChild( input );
		form.appendChild( send );
		panel.appendChild( form );
		panel.appendChild( el( 'p', 'aifaq-chat__note', t.note ) );

		function add( who, nodes ) {
			var bubble = el( 'div', 'aifaq-chat__msg aifaq-chat__msg--' + who );
			nodes.forEach( function ( n ) {
				bubble.appendChild( n );
			} );
			log.appendChild( bubble );
			log.scrollTop = log.scrollHeight;
			return bubble;
		}

		if ( config.welcome ) {
			add( 'bot', [ el( 'p', 'aifaq-chat__text', config.welcome ) ] );
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var question = input.value.trim();
			if ( ! question || send.disabled ) {
				return;
			}
			add( 'visitor', [ el( 'p', 'aifaq-chat__text', question ) ] );
			input.value = '';
			send.disabled = true;
			var waiting = add( 'bot', [ el( 'p', 'aifaq-chat__text aifaq-chat__thinking', t.thinking ) ] );

			fetch( config.endpoint, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				credentials: 'same-origin',
				body: JSON.stringify( { question: question } ),
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( data ) {
					waiting.remove();
					var sources = Array.isArray( data.sources ) ? data.sources : [];
					var nodes = [ answerNode( String( data.answer || t.error ), sources ) ];
					if ( sources.length ) {
						nodes.push( sourcesNode( sources ) );
					}
					var contact = safeUrl( data.contact_url || '' );
					if ( contact ) {
						var link = el( 'a', 'aifaq-chat__contact', t.contact );
						link.href = contact;
						nodes.push( link );
					}
					add( data.status === 'answered' ? 'bot' : 'bot aifaq-chat__msg--muted', nodes );
				} )
				.catch( function () {
					waiting.remove();
					add( 'bot aifaq-chat__msg--muted', [ el( 'p', 'aifaq-chat__text', t.error ) ] );
				} )
				.finally( function () {
					send.disabled = false;
					input.focus();
				} );
		} );

		input.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Enter' && ! event.shiftKey ) {
				event.preventDefault();
				form.requestSubmit();
			}
		} );

		if ( ! floating ) {
			root.appendChild( panel );
			return;
		}

		var toggle = el( 'button', 'aifaq-chat__toggle' );
		toggle.type = 'button';
		toggle.setAttribute( 'aria-expanded', 'false' );
		toggle.setAttribute( 'aria-controls', panelId );
		toggle.setAttribute( 'aria-label', t.open );
		toggle.appendChild( el( 'span', 'aifaq-chat__toggle-icon' ) );
		toggle.appendChild( el( 'span', 'aifaq-chat__toggle-label', config.title ) );

		var close = el( 'button', 'aifaq-chat__close', '×' );
		close.type = 'button';
		close.setAttribute( 'aria-label', t.close );
		header.appendChild( close );

		panel.hidden = true;
		root.appendChild( panel );
		root.appendChild( toggle );

		function setOpen( open ) {
			panel.hidden = ! open;
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			root.classList.toggle( 'is-open', open );
			if ( open ) {
				input.focus();
			} else {
				toggle.focus();
			}
		}

		toggle.addEventListener( 'click', function () {
			setOpen( panel.hidden );
		} );
		close.addEventListener( 'click', function () {
			setOpen( false );
		} );
		panel.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' ) {
				setOpen( false );
			}
		} );
	}

	function init() {
		document.querySelectorAll( '.aifaq-chat[data-aifaq-mode]' ).forEach( build );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
