( function () {
	'use strict';

	var cfg = window.SEO_AUTO_TAGS_COLUMNS || {};

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

	function rowTitle( btn ) {
		var tr = btn.closest( 'tr' );
		if ( ! tr ) {
			return '';
		}
		var t = tr.querySelector( '.row-title' );
		return t ? t.textContent.trim() : '';
	}

	function refreshCell( btn, total ) {
		var cell = btn.closest( 'td' );
		if ( ! cell ) {
			return;
		}

		var box = cell.querySelector( '[data-seo-auto-tags-count]' );
		if ( ! box ) {
			return;
		}

		box.textContent = total + ' 个';

		if ( total > ( cfg.tooMany || 8 ) ) {
			var warn = document.createElement( 'span' );
			warn.className = 'seo-auto-tags-col-empty';
			warn.textContent = ' 偏多';
			box.appendChild( warn );
		}
	}

	function handle( btn ) {
		if ( btn.disabled ) {
			return;
		}

		var pid = btn.getAttribute( 'data-seo-auto-tags-list-gen' );
		var label = btn.textContent;

		btn.disabled = true;
		btn.textContent = '分析中…';

		post( 'seo_auto_tags_list_generate', { post_id: pid } )
			.then( function ( res ) {
				if ( ! res || ! res.success ) {
					window.alert( ( res && res.data && res.data.message ) || '生成失败。' );
					return null;
				}

				var d = res.data;
				var note = d.source === 'ai' ? 'AI 生成' : '本地算法生成';
				if ( d.note ) {
					note += '（' + d.note + '）';
				}

				var msg =
					'文章：' + rowTitle( btn ) + '\n\n' +
					'将添加这 ' + d.tags.length + ' 个标签：\n\n' +
					d.tags.join( '、' ) + '\n\n' +
					'（' + note + '）\n\n' +
					'原有标签不会被删除。确认添加吗？';

				if ( ! window.confirm( msg ) ) {
					return null;
				}

				btn.textContent = '添加中…';

				return post( 'seo_auto_tags_list_apply', {
					post_id: pid,
					tags: JSON.stringify( d.tags )
				} );
			} )
			.then( function ( res ) {
				if ( ! res ) {
					return;
				}
				if ( ! res.success ) {
					window.alert( ( res.data && res.data.message ) || '添加失败。' );
					return;
				}
				refreshCell( btn, res.data.total );
				btn.textContent = '已添加';
				window.setTimeout( function () {
					btn.textContent = '重新生成';
					btn.disabled = false;
				}, 1500 );
				return true;
			} )
			.catch( function () {
				window.alert( '请求失败，请刷新页面重试。' );
			} )
			.then( function ( done ) {
				if ( true !== done ) {
					btn.textContent = label;
					btn.disabled = false;
				}
			} );
	}

	document.addEventListener( 'click', function ( e ) {
		var t = e.target;

		if ( ! t || t.nodeType !== 1 || ! t.closest ) {
			return;
		}

		var btn = t.closest( '[data-seo-auto-tags-list-gen]' );

		if ( btn ) {
			e.preventDefault();
			handle( btn );
		}
	} );
} )();
