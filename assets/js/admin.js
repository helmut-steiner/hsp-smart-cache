/**
 * Settings preview and AJAX interactions for HSP Smart Cache.
 */
( function () {
	'use strict';

	var config = window.HSPSCAdmin;
	if ( ! config ) {
		return;
	}
	var preview = document.getElementById( 'hsp-htaccess-preview' );

	function getVal( name ) {
		var el = document.querySelector(
			'[name="' + config.optionKey + '[' + name + ']"]'
		);
		if ( ! el ) {
			return null;
		}
		if ( el.type === 'checkbox' ) {
			return el.checked;
		}
		return el.value;
	}

	function updatePreview() {
		if ( ! preview ) {
			return;
		}
		var ttl = parseInt( getVal( 'static_asset_ttl' ), 10 );
		if ( isNaN( ttl ) || ttl < 60 ) {
			ttl = 60;
		}

		var immutable = !! getVal( 'static_asset_immutable' );
		var compress = !! getVal( 'static_asset_compression' );
		var enabled = !! getVal( 'static_asset_cache' );

		if ( ! enabled ) {
			preview.value = '';
			return;
		}

		var template = preview.dataset.templateCache;
		if ( immutable && compress ) {
			template = preview.dataset.templateCacheImmutableCompress;
		} else if ( immutable ) {
			template = preview.dataset.templateCacheImmutable;
		} else if ( compress ) {
			template = preview.dataset.templateCacheCompress;
		}

		preview.value = template.replace(
			'public, max-age=0',
			'public, max-age=' + ttl
		);
	}

	document.addEventListener( 'input', function ( e ) {
		if ( ! e.target || ! e.target.name ) {
			return;
		}
		if (
			e.target.name.indexOf(
				'' + config.optionKey + '[static_asset_'
			) === 0
		) {
			updatePreview();
		}
	} );

	document.addEventListener( 'change', function ( e ) {
		if ( ! e.target || ! e.target.name ) {
			return;
		}
		if (
			e.target.name.indexOf(
				'' + config.optionKey + '[static_asset_'
			) === 0
		) {
			updatePreview();
		}
	} );

	updatePreview();

	var feedback = document.getElementById( 'hspsc-feedback' );
	var settingsForm = document.getElementById( 'hspsc-settings-form' );

	function showFeedback( message, type, details ) {
		if ( ! feedback ) {
			return;
		}
		var html =
			'<div class="notice notice-' +
			type +
			' is-dismissible"><p>' +
			escapeHtml( message ) +
			'</p>';
		if ( Array.isArray( details ) && details.length ) {
			html += '<ul class="hspsc-notice-details">';
			details.forEach( function ( detail ) {
				html += '<li>' + escapeHtml( detail ) + '</li>';
			} );
			html += '</ul>';
		}
		html += '</div>';
		feedback.innerHTML = html;
	}

	function escapeHtml( value ) {
		var div = document.createElement( 'div' );
		div.textContent = value || '';
		return div.innerHTML;
	}

	function setFormBusy( form, busy ) {
		var buttons = form.querySelectorAll( 'button, input[type="submit"]' );
		form.setAttribute( 'aria-busy', busy ? 'true' : 'false' );

		buttons.forEach( function ( button ) {
			if ( busy ) {
				button.dataset.originalText =
					button.textContent || button.value || '';
				if ( button.dataset.loadingText ) {
					if ( button.tagName === 'INPUT' ) {
						button.value = button.dataset.loadingText;
					} else {
						button.textContent = button.dataset.loadingText;
					}
				}
				button.disabled = true;
			} else {
				if ( button.dataset.originalText ) {
					if ( button.tagName === 'INPUT' ) {
						button.value = button.dataset.originalText;
					} else {
						button.textContent = button.dataset.originalText;
					}
				}
				button.disabled = false;
			}
		} );
	}

	function updatePanels( data ) {
		var testsPanel = document.getElementById( 'hspsc-tests-panel' );
		var analysisPanel = document.getElementById(
			'hspsc-db-analysis-panel'
		);
		var backupsPanel = document.getElementById( 'hspsc-db-backups-panel' );

		if ( data.html && testsPanel ) {
			testsPanel.innerHTML = data.html;
		}
		if ( data.analysis_html && analysisPanel ) {
			analysisPanel.innerHTML = data.analysis_html;
		}
		if ( data.backups_html && backupsPanel ) {
			backupsPanel.innerHTML = data.backups_html;
		}
	}

	function applyOptions( options ) {
		if ( ! options ) {
			return;
		}

		Object.keys( options ).forEach( function ( key ) {
			var field = document.querySelector(
				'[name="' + config.optionKey + '[' + key + ']"]'
			);
			if ( ! field ) {
				return;
			}

			if ( field.type === 'checkbox' ) {
				field.checked = !! options[ key ];
				return;
			}

			field.value =
				options[ key ] === null || typeof options[ key ] === 'undefined'
					? ''
					: options[ key ];
		} );

		updatePreview();
	}

	function submitAjaxForm( form, ajaxAction ) {
		var formData = new FormData( form );
		formData.set( 'action', ajaxAction || formData.get( 'action' ) );

		setFormBusy( form, true );

		fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData,
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( response ) {
				var data = response && response.data ? response.data : {};
				if ( ! response || ! response.success ) {
					var ajaxError = new Error(
						data.message || config.actionFailed
					);
					ajaxError.details = data.details || [];
					throw ajaxError;
				}

				showFeedback(
					data.message || config.actionCompleted,
					'success',
					data.details || []
				);
				applyOptions( data.options );
				updatePanels( data );
			} )
			.catch( function ( error ) {
				showFeedback(
					error.message || config.actionFailed,
					'error',
					error.details || []
				);
			} )
			.finally( function () {
				setFormBusy( form, false );
			} );
	}

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! form || ! form.classList ) {
			return;
		}

		if ( form.classList.contains( 'hspsc-ajax-form' ) ) {
			if (
				form.dataset.confirm &&
				! window.confirm( form.dataset.confirm )
			) {
				event.preventDefault();
				return;
			}

			event.preventDefault();
			submitAjaxForm( form );
		}
	} );

	if ( settingsForm ) {
		settingsForm.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			submitAjaxForm( settingsForm, 'hspsc_save_settings' );
		} );
	}
} )();
