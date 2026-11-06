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
	var feedback = document.getElementById( 'hspsc-feedback' );
	var settingsForm = document.getElementById( 'hspsc-settings-form' );
	var settingsPrefix = config.optionKey + '[';
	var settingsFields = new Map();
	var busyForms = new WeakMap();
	const settingsWriteActions = [
		'hspsc_restore_defaults',
		'hspsc_apply_bricks_preset',
	];
	const settingsWriteForms = Array.from(
		document.querySelectorAll( '.hspsc-admin .hspsc-ajax-form' )
	).filter( ( form ) =>
		settingsWriteActions.includes(
			form.elements.namedItem( 'action' )?.value
		)
	);
	if ( settingsForm ) {
		settingsWriteForms.push( settingsForm );
	}
	var unsavedLabels = document.querySelectorAll(
		'.hspsc-admin [data-hspsc-unsaved-settings]'
	);

	if ( settingsForm ) {
		Array.from( settingsForm.elements ).forEach( function ( field ) {
			if ( field.name && field.name.startsWith( settingsPrefix ) ) {
				settingsFields.set( field.name, field );
			}
		} );
	}
	var savedSettings = captureSettings();

	function initializeTabs() {
		const tablist = document.querySelector( '.hspsc-admin .hspsc-tabs' );
		if ( ! tablist ) {
			return;
		}
		const admin = tablist.closest( '.hspsc-admin' );
		const items = Array.from(
			tablist.querySelectorAll( 'a[data-hspsc-tab]' ),
			function ( tab ) {
				return { tab, panel: anchorTarget( tab.hash ) };
			}
		);
		if (
			! items.length ||
			items.some(
				( { tab, panel } ) =>
					! panel ||
					! admin.contains( panel ) ||
					panel.dataset.hspscPanel !== tab.dataset.hspscTab
			)
		) {
			return;
		}

		const first =
			items.find( ( item ) => item.tab.dataset.hspscTab === 'cache' ) ||
			items[ 0 ];
		let active = null;
		let showingInvalid = false;

		function anchorTarget( hash ) {
			try {
				return document.getElementById(
					decodeURIComponent( hash.slice( 1 ) )
				);
			} catch {
				return null;
			}
		}

		function itemForTarget( target ) {
			return items.find(
				( item ) => item.tab === target || item.panel.contains( target )
			);
		}

		function activate( item, historyMethod, focus ) {
			const previous = active;
			const focused = document.activeElement;
			active = item;
			items.forEach( function ( entry ) {
				const selected = entry === item;
				entry.tab.setAttribute( 'aria-selected', String( selected ) );
				entry.tab.tabIndex = selected ? 0 : -1;
				entry.panel.hidden = ! selected;
			} );
			if ( historyMethod && window.location.hash !== item.tab.hash ) {
				window.history[ historyMethod ](
					window.history.state,
					'',
					item.tab.hash
				);
			}
			if (
				focus ||
				( previous &&
					previous !== item &&
					( previous.tab === focused ||
						previous.panel.contains( focused ) ) )
			) {
				item.tab.focus( { preventScroll: true } );
			}
		}

		function syncHash() {
			const target = anchorTarget( window.location.hash );
			const item = itemForTarget( target );
			const wasHidden = item && item.panel.hidden;
			activate( item || first );
			if ( wasHidden ) {
				target.scrollIntoView();
			}
		}

		tablist.setAttribute( 'role', 'tablist' );
		items.forEach( function ( { tab, panel } ) {
			tab.setAttribute( 'role', 'tab' );
			tab.setAttribute( 'aria-controls', panel.id );
			panel.setAttribute( 'role', 'tabpanel' );
			panel.setAttribute( 'aria-labelledby', tab.id );
			panel.tabIndex = 0;
		} );

		tablist.addEventListener( 'keydown', function ( event ) {
			const index = items.findIndex(
				( item ) => item.tab === event.target
			);
			if ( index < 0 || event.altKey || event.ctrlKey || event.metaKey ) {
				return;
			}
			let next = index;
			if ( event.key === 'ArrowLeft' || event.key === 'ArrowRight' ) {
				const forward = event.key === 'ArrowRight' ? 1 : -1;
				const direction =
					window.getComputedStyle( tablist ).direction === 'rtl'
						? -forward
						: forward;
				next = ( index + direction + items.length ) % items.length;
			} else if ( event.key === 'Home' ) {
				next = 0;
			} else if ( event.key === 'End' ) {
				next = items.length - 1;
			} else if ( event.key !== 'Enter' && event.key !== ' ' ) {
				return;
			}
			event.preventDefault();
			activate( items[ next ], 'pushState', true );
		} );

		document.addEventListener( 'click', function ( event ) {
			if (
				event.defaultPrevented ||
				event.button !== 0 ||
				event.altKey ||
				event.ctrlKey ||
				event.metaKey ||
				event.shiftKey ||
				! ( event.target instanceof Element )
			) {
				return;
			}
			const link = event.target.closest( 'a[href]' );
			const item = items.find( ( entry ) => entry.tab === link );
			if ( item ) {
				event.preventDefault();
				activate( item, 'pushState', true );
			} else if (
				link &&
				link.hash &&
				link.origin === window.location.origin &&
				link.pathname === window.location.pathname &&
				link.search === window.location.search &&
				! link.hasAttribute( 'download' ) &&
				( ! link.target || link.target === '_self' )
			) {
				const target = anchorTarget( link.hash );
				const destination = itemForTarget( target );
				if ( destination ) {
					activate( destination );
					// A repeated fragment may not dispatch hashchange.
					if ( link.hash === window.location.hash ) {
						target.scrollIntoView();
					}
				}
			}
		} );

		document.addEventListener(
			'invalid',
			function ( event ) {
				if (
					! settingsForm ||
					event.target.form !== settingsForm ||
					showingInvalid
				) {
					return;
				}
				showingInvalid = true;
				// Leave the first invalid control visible for native focus.
				window.setTimeout( () => ( showingInvalid = false ), 0 );
				const item = itemForTarget( event.target );
				if ( item && item !== active ) {
					activate( item, 'replaceState' );
				}
			},
			true
		);

		window.addEventListener( 'hashchange', syncHash );
		window.addEventListener( 'popstate', syncHash );
		syncHash();
	}

	function fieldValue( field ) {
		return field.type === 'checkbox' ? field.checked : field.value;
	}

	function captureSettings() {
		var values = new Map();
		settingsFields.forEach( function ( field ) {
			values.set( field, fieldValue( field ) );
		} );
		return values;
	}

	function updateUnsavedStatus() {
		if ( ! config.unsavedChanges || ! unsavedLabels.length ) {
			return;
		}
		var changed = Array.from( savedSettings ).some( function ( entry ) {
			return fieldValue( entry[ 0 ] ) !== entry[ 1 ];
		} );
		unsavedLabels.forEach( function ( label ) {
			var message = changed ? config.unsavedChanges : '';
			label.hidden = false;
			if ( label.textContent !== message ) {
				label.textContent = message;
			}
		} );
	}

	function getVal( name ) {
		var field = settingsFields.get( settingsPrefix + name + ']' );
		return field ? fieldValue( field ) : null;
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

	function onSettingChange( event ) {
		const field = event.target;
		if ( settingsFields.get( field.name ) !== field ) {
			return;
		}
		if ( field.name.startsWith( settingsPrefix + 'static_asset_' ) ) {
			updatePreview();
		}
		updateUnsavedStatus();
	}

	document.addEventListener( 'input', onSettingChange );
	document.addEventListener( 'change', onSettingChange );
	initializeTabs();
	updatePreview();
	updateUnsavedStatus();

	function showFeedback( message, type, details ) {
		if ( ! feedback ) {
			return;
		}
		const notice = document.createElement( 'div' );
		const summary = document.createElement( 'p' );
		notice.className = 'notice notice-' + type;
		summary.textContent = message;
		notice.append( summary );
		if ( Array.isArray( details ) && details.length ) {
			const list = document.createElement( 'ul' );
			list.className = 'hspsc-notice-details';
			details.forEach( function ( detail ) {
				const item = document.createElement( 'li' );
				item.textContent = detail;
				list.append( item );
			} );
			notice.append( list );
		}
		feedback.replaceChildren( notice );
	}

	function setFormBusy( form, busy, showLoading ) {
		if ( busy ) {
			const buttons = new Map();
			Array.from( form.elements ).forEach( function ( button ) {
				if ( button.type !== 'submit' ) {
					return;
				}
				const property =
					button.tagName === 'INPUT' ? 'value' : 'textContent';
				buttons.set( button, {
					disabled: button.disabled,
					property,
					label: button[ property ],
				} );
				if ( showLoading && button.dataset.loadingText ) {
					button[ property ] = button.dataset.loadingText;
				}
				button.disabled = true;
			} );
			busyForms.set( form, buttons );
			form.setAttribute( 'aria-busy', 'true' );
		} else {
			busyForms.get( form ).forEach( function ( state, button ) {
				if ( button.dataset.loadingText ) {
					button[ state.property ] = state.label;
				}
				button.disabled = state.disabled;
			} );
			busyForms.delete( form );
			form.removeAttribute( 'aria-busy' );
		}
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

	function applyOptions( options, submittedSettings ) {
		if ( ! options ) {
			return;
		}

		Object.keys( options ).forEach( function ( key ) {
			const field = settingsFields.get( settingsPrefix + key + ']' );
			if ( ! field ) {
				return;
			}

			const checkbox = field.type === 'checkbox';
			let value = checkbox
				? Boolean( options[ key ] )
				: String( options[ key ] ?? '' );
			if ( field.tagName === 'TEXTAREA' ) {
				// The textarea DOM value normalizes submitted CRLF sequences.
				value = value.replace( /\r\n?/g, '\n' );
			}
			savedSettings.set( field, value );
			// Keep changes made after the request started.
			if ( fieldValue( field ) === submittedSettings.get( field ) ) {
				field[ checkbox ? 'checked' : 'value' ] = value;
			}
		} );
		document
			.querySelectorAll( '.hspsc-admin [data-hspsc-saved-option]' )
			.forEach( function ( badge ) {
				const key = badge.dataset.hspscSavedOption;
				if ( Object.prototype.hasOwnProperty.call( options, key ) ) {
					const enabled = Boolean( options[ key ] );
					badge.dataset.enabled = enabled ? '1' : '0';
					badge.textContent = enabled
						? config.savedEnabled
						: config.savedDisabled;
				}
			} );

		updatePreview();
		updateUnsavedStatus();
	}

	function submitAjaxForm( form, ajaxAction ) {
		if ( busyForms.has( form ) ) {
			return;
		}
		const formData = new FormData( form );
		const submittedSettings = captureSettings();
		if ( ajaxAction ) {
			formData.set( 'action', ajaxAction );
		}

		// Settings writes share the same option and saved status display.
		const forms = settingsWriteForms.includes( form )
			? settingsWriteForms
			: [ form ];
		forms.forEach( ( entry ) =>
			setFormBusy( entry, true, entry === form )
		);

		fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData,
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( response ) {
				const data = response && response.data ? response.data : {};
				if ( ! response || ! response.success ) {
					showFeedback(
						data.message || config.actionFailed,
						'error',
						data.details
					);
					return;
				}

				showFeedback(
					data.message || config.actionCompleted,
					'success',
					data.details || []
				);
				applyOptions( data.options, submittedSettings );
				updatePanels( data );
			} )
			.catch( function () {
				showFeedback( config.actionFailed, 'error' );
			} )
			.finally( function () {
				forms.forEach( ( entry ) => setFormBusy( entry, false ) );
			} );
	}

	document.addEventListener( 'submit', function ( event ) {
		const form = event.target;
		if ( ! ( form instanceof HTMLFormElement ) ) {
			return;
		}

		if ( form !== settingsForm ) {
			if ( ! form.classList.contains( 'hspsc-ajax-form' ) ) {
				return;
			}
			if (
				form.dataset.confirm &&
				! window.confirm( form.dataset.confirm )
			) {
				event.preventDefault();
				return;
			}
		}

		if ( window.fetch && window.FormData ) {
			event.preventDefault();
			submitAjaxForm(
				form,
				form === settingsForm ? 'hspsc_save_settings' : ''
			);
		}
	} );
} )();
