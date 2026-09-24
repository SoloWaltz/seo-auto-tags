( function () {
	'use strict';

	var cfg = window.SEO_AUTO_TAGS_EDITOR || {};
	var busy = false;
	var TOO_MANY = 8;


	function panel() {
		return document.querySelector( '[data-seo-auto-tags-panel]' );
	}

	function q( sel ) {
		var p = panel();
		return ( p || document ).querySelector( sel );
	}

	function status( msg, type ) {
		var el = q( '[data-seo-auto-tags-status]' );
		if ( ! el ) {
			return;
		}
		el.textContent = msg || '';
		el.className = 'seo-auto-tags-status' + ( type ? ' is-' + type : '' );
	}

	function post( action, data ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce || '' );

		Object.keys( data || {} ).forEach( function ( k ) {
			body.append( k, data[ k ] );
		} );

		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} ).then( function ( r ) {
			return r.json();
		} );
	}

	function postId() {
		var p = panel();
		return p ? ( p.getAttribute( 'data-post-id' ) || '0' ) : '0';
	}


	function isBlockEditor() {
		try {
			return !! (
				window.wp &&
				wp.data &&
				wp.data.select &&
				wp.data.select( 'core/editor' ) &&
				document.body &&
				document.body.classList.contains( 'block-editor-page' )
			);
		} catch ( e ) {
			return false;
		}
	}

	function getTitle() {
		if ( isBlockEditor() ) {
			try {
				var t = wp.data.select( 'core/editor' ).getEditedPostAttribute( 'title' );
				if ( t ) {
					return t;
				}
			} catch ( e ) {}
		}
		var el = document.getElementById( 'title' );
		return el ? el.value : '';
	}

	function getContent() {
		if ( isBlockEditor() ) {
			try {
				var c = wp.data.select( 'core/editor' ).getEditedPostContent();
				if ( c && c.length ) {
					return c;
				}
			} catch ( e ) {}
			try {
				var c2 = wp.data.select( 'core/editor' ).getEditedPostAttribute( 'content' );
				if ( c2 && c2.length ) {
					return c2;
				}
			} catch ( e ) {}
		}

		if ( window.tinymce && window.tinymce.get ) {
			var ed = window.tinymce.get( 'content' );
			if ( ed && ! ed.isHidden() ) {
				var v = ed.getContent();
				if ( v ) {
					return v;
				}
			}
		}

		var ta = document.getElementById( 'content' );
		return ta ? ta.value : '';
	}


	function renderList( tags, existing ) {
		var list = q( '[data-seo-auto-tags-list]' );
		if ( ! list ) {
			return;
		}

		list.textContent = '';

		( tags || [] ).forEach( function ( name, i ) {
			var wrap = document.createElement( 'label' );
			wrap.className = 'seo-auto-tags-item';
			wrap.setAttribute( 'data-seo-auto-tags-item', '' );
			wrap.setAttribute( 'for', 'seo-auto-tags-tag-' + i );

			var cb = document.createElement( 'input' );
			cb.type = 'checkbox';
			cb.id = 'seo-auto-tags-tag-' + i;
			cb.checked = true;
			cb.value = name;
			cb.setAttribute( 'data-seo-auto-tags-check', '' );

			var input = document.createElement( 'input' );
			input.type = 'text';
			input.className = 'seo-auto-tags-name';
			input.value = name;
			input.maxLength = 40;
			input.setAttribute( 'data-seo-auto-tags-name', '' );

			wrap.appendChild( cb );
			wrap.appendChild( input );

			if ( existing && existing[ name ] ) {
				var badge = document.createElement( 'span' );
				badge.className = 'seo-auto-tags-badge';
				badge.textContent = '站内已有';
				wrap.appendChild( badge );
			}

			list.appendChild( wrap );
		} );

		var box = q( '[data-seo-auto-tags-apply]' );
		if ( box ) {
			box.hidden = false;
		}

		var copy = q( '[data-seo-auto-tags-copy]' );
		if ( copy ) {
			copy.hidden = false;
		}
	}

	function setAll( checked ) {
		var items = document.querySelectorAll( '[data-seo-auto-tags-check]' );
		Array.prototype.forEach.call( items, function ( c ) {
			c.checked = !! checked;
		} );
	}

	function pickedTags() {
		var out = [];
		var items = document.querySelectorAll( '[data-seo-auto-tags-check]' );
		Array.prototype.forEach.call( items, function ( c ) {
			if ( c.checked ) {
				var item = c.closest( '[data-seo-auto-tags-item]' );
				var input = item ? item.querySelector( '[data-seo-auto-tags-name]' ) : null;
				var value = input ? input.value.trim() : c.value;
				if ( value ) {
					out.push( value );
				}
			}
		} );
		return out;
	}

	function copyTags() {
		var tags = pickedTags();
		if ( ! tags.length ) {
			status( '没有可复制的标签。', 'error' );
			return;
		}

		var text = tags.join( '、' );
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( function () {
				status( '标签已复制。', 'ok' );
			} ).catch( function () {
				status( '复制失败，请手动选择标签。', 'warn' );
			} );
			return;
		}

		status( '当前浏览器不支持自动复制：' + text, 'warn' );
	}


	function writeToBlockEditor( terms ) {
		try {
			var sel = wp.data.select( 'core/editor' );
			var disp = wp.data.dispatch( 'core/editor' );
			var cur = sel.getEditedPostAttribute( 'tags' ) || [];
			var merged = cur.slice();

			terms.forEach( function ( t ) {
				if ( merged.indexOf( t.id ) === -1 ) {
					merged.push( t.id );
				}
			} );

			disp.editPost( { tags: merged } );

			return { ok: true, total: merged.length };
		} catch ( e ) {
			return { ok: false, total: 0 };
		}
	}

	function writeToClassicEditor( terms ) {
		var input = document.getElementById( 'new-tag-post_tag' );
		if ( ! input ) {
			return { ok: false, total: 0 };
		}

		var box = document.getElementById( 'post_tag' );
		var btn = box ? box.querySelector( '.tagadd' ) : null;
		if ( ! btn ) {
			btn = document.querySelector( '.tagadd' );
		}
		if ( ! btn ) {
			return { ok: false, total: 0 };
		}

		input.value = terms
			.map( function ( t ) {
				return t.name;
			} )
			.join( ',' );

		btn.click();

		return { ok: true, total: 0 };
	}

	function updateCurrent( names, total ) {
		var el = q( '[data-seo-auto-tags-current-value]' );
		if ( el ) {
			el.textContent = names;
		}

		if ( typeof total !== 'number' || total <= 0 ) {
			return;
		}

		var badge = q( '[data-seo-auto-tags-current-count]' );
		if ( badge ) {
			badge.textContent = total + ' 个';
			if ( total > TOO_MANY ) {
				badge.classList.add( 'is-warn' );
			} else {
				badge.classList.remove( 'is-warn' );
			}
		}

		var warn = q( '[data-seo-auto-tags-toomany]' );
		if ( warn ) {
			warn.hidden = total <= TOO_MANY;
		}
	}


	function doGenerate() {
		if ( busy ) {
			return;
		}

		var btn = q( '[data-seo-auto-tags-generate]' );
		var content = getContent();

		if ( ! content || content.replace( /\s/g, '' ).length < 40 ) {
			status( '正文太短了，先写点内容再点生成。', 'error' );
			return;
		}

		busy = true;
		if ( btn ) {
			btn.disabled = true;
		}
		status( '正在分析正文…', 'loading' );

		post( 'seo_auto_tags_generate', {
			title: getTitle(),
			content: content,
			post_id: postId()
		} )
			.then( function ( res ) {
				if ( ! res || ! res.success ) {
					status( ( res && res.data && res.data.message ) || '生成失败。', 'error' );
					return;
				}

				var d = res.data;
				renderList( d.tags, d.existing );

				if ( d.note ) {
					status( '已用本地算法生成 ' + d.tags.length + ' 个候选。' + d.note, 'warn' );
					return;
				}

				var note = d.source === 'ai' ? 'AI 生成' : '本地算法生成';
				if ( d.from_cache ) {
					note += '·读的缓存';
				}

				status( '生成 ' + d.tags.length + ' 个候选 · ' + note, 'ok' );
			} )
			.catch( function () {
				status( '请求失败，请刷新页面重试。', 'error' );
			} )
			.then( function () {
				busy = false;
				if ( btn ) {
					btn.disabled = false;
				}
			} );
	}

	function doApply() {
		var picked = pickedTags();

		if ( ! picked.length ) {
			status( '一个都没勾选，先勾几个再应用。', 'error' );
			return;
		}

		status( '正在创建标签…', 'loading' );

		post( 'seo_auto_tags_apply', {
			post_id: postId(),
			tags: JSON.stringify( picked )
		} )
			.then( function ( res ) {
				if ( ! res || ! res.success ) {
					status( ( res && res.data && res.data.message ) || '应用失败。', 'error' );
					return;
				}

				var terms = res.data.terms;
				var names = terms
					.map( function ( t ) {
						return t.name;
					} )
					.join( '、' );

				var r = isBlockEditor() ? writeToBlockEditor( terms ) : writeToClassicEditor( terms );

				updateCurrent( names, r.total );

				if ( r.ok ) {
					var tooMany = r.total > TOO_MANY;
					status(
						'已应用 ' + terms.length + ' 个标签，保存文章后生效。' + ( tooMany ? ' 注意：标签偏多。' : '' ),
						tooMany ? 'warn' : 'ok'
					);
				} else {
					status( '标签已创建，但没能自动填入标签框。请手动添加：' + names, 'warn' );
				}
			} )
			.catch( function () {
				status( '请求失败，请刷新页面重试。', 'error' );
			} );
	}


	document.addEventListener( 'click', function ( e ) {
		var t = e.target;

		if ( ! t || t.nodeType !== 1 || ! t.closest ) {
			return;
		}

		if ( t.closest( '[data-seo-auto-tags-generate]' ) ) {
			e.preventDefault();
			doGenerate();
			return;
		}
		if ( t.closest( '[data-seo-auto-tags-copy]' ) ) {
			e.preventDefault();
			copyTags();
			return;
		}
		if ( t.closest( '[data-seo-auto-tags-apply-btn]' ) ) {
			e.preventDefault();
			doApply();
			return;
		}
		if ( t.closest( '[data-seo-auto-tags-select-all]' ) ) {
			e.preventDefault();
			setAll( true );
			return;
		}
		if ( t.closest( '[data-seo-auto-tags-select-none]' ) ) {
			e.preventDefault();
			setAll( false );
		}
	} );


	function autoRun( tries ) {
		tries = tries || 0;

		if ( ! cfg.autoSuggest ) {
			return;
		}
		if ( ! panel() ) {
			if ( tries < 20 ) {
				window.setTimeout( function () {
					autoRun( tries + 1 );
				}, 500 );
			}
			return;
		}

		var content = getContent();
		if ( ! content || content.replace( /\s/g, '' ).length < 40 ) {
			if ( tries < 20 ) {
				window.setTimeout( function () {
					autoRun( tries + 1 );
				}, 1000 );
			}
			return;
		}

		doGenerate();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			window.setTimeout( function () {
				autoRun( 0 );
			}, 1200 );
		} );
	} else {
		window.setTimeout( function () {
			autoRun( 0 );
		}, 1200 );
	}
} )();

