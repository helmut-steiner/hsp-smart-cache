/**
 * Resumes authorized rebuild progress and results across navigation.
 */
( function () {
	'use strict';

	const config = window.HSPSCRebuild;
	const notice = document.getElementById( 'hspsc-rebuild-notice' );
	if ( ! config || ! notice || ! window.fetch ) {
		return;
	}

	const message = notice.querySelector( '.hspsc-rebuild-notice-message' );
	const progress = notice.querySelector( 'progress' );
	const dismiss = notice.querySelector( '.hspsc-rebuild-dismiss' );
	const retryForm = notice.querySelector( '.hspsc-rebuild-retry-form' );
	const retryFeedback = notice.querySelector(
		'.hspsc-rebuild-notice-feedback'
	);
	const report = document.querySelector( '[data-hspsc-rebuild-report]' );
	const feedback = document.getElementById( 'hspsc-rebuild-action-message' );
	let job = null;
	let timer;
	let refreshing = false;
	let refreshPending = false;
	let reportUpdate = null;
	let previousReport = null;
	let dismissedJob = '';

	try {
		dismissedJob = window.localStorage.getItem( config.dismissKey ) || '';
	} catch {
		// Progress remains available when storage is unavailable.
	}

	async function request( action, data ) {
		const body =
			data instanceof FormData ? data : new URLSearchParams( data );
		body.set( 'action', action );
		const response = await fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			cache: 'no-store',
			body,
		} ).catch( () => {
			throw new Error( config.requestFailed );
		} );
		const payload = await response.json().catch( () => null );
		if ( ! payload || ! payload.success ) {
			const error = new Error(
				payload?.data?.message || config.requestFailed
			);
			error.status = response.status;
			throw error;
		}
		return payload.data || {};
	}

	function updateNotice( nextJob ) {
		if ( job?.id !== nextJob?.id ) {
			retryFeedback.hidden = true;
		}
		job = nextJob;
		notice.hidden = ! job || dismissedJob === job.id;
		retryForm.hidden = ! job?.retryNonce;
		retryForm.elements.job_id.value = job?.id || '';
		retryForm.elements._wpnonce.value = job?.retryNonce || '';
		if ( ! job ) {
			return;
		}
		notice.dataset.tone = job.tone;
		if ( message.textContent !== job.message ) {
			message.textContent = job.message;
		}
		progress.hidden = ! job.active;
		if ( job.progressMax > 0 ) {
			progress.max = job.progressMax;
			progress.value = job.progressValue;
		} else {
			progress.removeAttribute( 'value' );
		}
	}

	function updateReport() {
		if ( ! reportUpdate || report.contains( document.activeElement ) ) {
			return;
		}
		report.dataset.page = reportUpdate.page;
		if ( previousReport !== reportUpdate.report ) {
			// The settings-only response contains the server-escaped report.
			report.innerHTML = reportUpdate.report;
			previousReport = reportUpdate.report;
		}
		reportUpdate = null;
	}

	async function refresh( advance = false ) {
		window.clearTimeout( timer );
		if ( refreshing ) {
			refreshPending = true;
			return;
		}
		refreshing = true;
		let retryDelay = 2000;
		try {
			if ( advance && job?.active && ! document.hidden ) {
				await request( 'hspsc_rebuild_tick', {
					_wpnonce: config.tickNonce,
					job_id: job.id,
				} ).catch( () => {
					// A concurrent worker or a replaced job may win the tick.
					// The status request below always reads the current job.
				} );
			}
			const data = await request( 'hspsc_rebuild_status', {
				_wpnonce: config.statusNonce,
				page: report?.dataset.page || '1',
				include_report: report ? '1' : '0',
			} );
			updateNotice( data.job );
			if ( report && typeof data.report === 'string' ) {
				reportUpdate = data;
				updateReport();
			}
		} catch ( error ) {
			if ( job?.active ) {
				message.textContent = config.requestFailed;
			}
			retryDelay = error.status === 403 ? 0 : 6000;
		} finally {
			refreshing = false;
			if ( refreshPending ) {
				refreshPending = false;
				refresh();
			} else if ( job?.active && ! document.hidden && retryDelay ) {
				timer = window.setTimeout( () => refresh( true ), retryDelay );
			}
		}
	}

	dismiss.addEventListener( 'click', () => {
		if ( ! job ) {
			return;
		}
		dismissedJob = job.id;
		try {
			window.localStorage.setItem( config.dismissKey, dismissedJob );
		} catch {
			// The dismissal still applies on this page.
		}
		notice.hidden = true;
		document.querySelector( '#wp-admin-bar-hspsc > a' )?.focus();
	} );

	report?.addEventListener( 'focusout', () =>
		window.setTimeout( updateReport, 0 )
	);

	document.addEventListener( 'submit', async ( event ) => {
		const form = event.target;
		if ( ! form.matches( '.hspsc-rebuild-form' ) ) {
			return;
		}
		event.preventDefault();
		const button = form.querySelector( 'button[type="submit"]' );
		if ( button.disabled ) {
			return;
		}
		const body = new FormData( form );
		const action = body.get( 'action' );
		if ( form === retryForm ) {
			retryFeedback.hidden = true;
		}
		if (
			action === 'hspsc_run_preload' &&
			typeof config.navigationAccept === 'string'
		) {
			body.set( 'navigation_accept', config.navigationAccept );
		}
		button.disabled = true;
		form.setAttribute( 'aria-busy', 'true' );
		try {
			const data = await request( action, body );
			updateNotice( data.job );
			if ( report ) {
				report.dataset.page = '1';
			}
			if ( feedback ) {
				feedback.classList.remove( 'hspsc-rebuild-warning' );
				feedback.textContent = data.message || config.requestComplete;
				feedback.hidden = false;
			}
		} catch ( error ) {
			if ( form === retryForm ) {
				retryFeedback.textContent =
					error.message || config.requestFailed;
				retryFeedback.hidden = false;
			} else if ( feedback ) {
				feedback.classList.add( 'hspsc-rebuild-warning' );
				feedback.textContent = error.message || config.requestFailed;
				feedback.hidden = false;
			}
		} finally {
			button.disabled = false;
			form.removeAttribute( 'aria-busy' );
			refresh();
		}
	} );

	document.addEventListener( 'visibilitychange', () => {
		if ( document.hidden ) {
			window.clearTimeout( timer );
		} else {
			refresh();
		}
	} );
	window.addEventListener( 'pageshow', ( event ) => {
		if ( event.persisted ) {
			refresh();
		}
	} );

	refresh();
} )();
