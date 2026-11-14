import apiFetch from '@wordpress/api-fetch';

export const cfg = window.LeadSiftConfig || {};

let ready = false;

export const isPortal = cfg.context === 'portal';

/** What the signed-in person may change on the dashboard (set from the login's access after sign-in). */
export const perms = { canEdit: ! isPortal };

/* Client Portal sign-in token (kept in this browser only). */
const TOKEN_KEY = 'leadsift_portal_' + ( cfg.siteUrl || window.location.host );
export function getToken() {
	try {
		return window.localStorage.getItem( TOKEN_KEY ) || '';
	} catch ( e ) {
		return '';
	}
}
export function setToken( token ) {
	try {
		if ( token ) {
			window.localStorage.setItem( TOKEN_KEY, token );
		} else {
			window.localStorage.removeItem( TOKEN_KEY );
		}
	} catch ( e ) {}
}

/**
 * Small wrapper around wp.apiFetch that talks to /wp-json/leadsift/v1/.
 */
export function api( path, { method = 'GET', data, query } = {} ) {
	if ( ! ready && cfg.nonce ) {
		apiFetch.use( apiFetch.createNonceMiddleware( cfg.nonce ) );
		ready = true;
	}
	const headers = {};
	const token = isPortal ? getToken() : '';
	if ( token ) {
		headers[ 'X-LeadSift-Token' ] = token;
	}
	let url = ( cfg.restUrl || '/wp-json/leadsift/v1/' ) + path;
	if ( query ) {
		const qs = new URLSearchParams(
			Object.entries( query ).filter( ( [ , v ] ) => v !== '' && v !== null && v !== undefined && v !== false )
		).toString();
		if ( qs ) {
			url += ( url.includes( '?' ) ? '&' : '?' ) + qs;
		}
	}
	return apiFetch( { url, method, data, headers } ).catch( ( e ) => {
		const status = e && e.data && e.data.status;
		// Refused? Only sign out if the session itself is no longer valid.
		if ( isPortal && token && ( 401 === status || 403 === status ) && ! path.startsWith( 'portal/' ) ) {
			apiFetch( { url: ( cfg.restUrl || '/wp-json/leadsift/v1/' ) + 'portal/me', headers } ).catch( () => window.dispatchEvent( new window.Event( 'leadsift-signed-out' ) ) );
		}
		throw e;
	} );
}

/**
 * Re-checks every saved inquiry with the current rules & settings (in batches).
 * Inquiries you moved by hand are kept as they are.
 *
 * @return {Promise<{processed: number, changed: number}>}
 */
export async function rescanAll() {
	let after = 0;
	let processed = 0;
	let changed = 0;
	do {
		const res = await api( 'rescan', { method: 'POST', data: { after } } );
		processed += res.processed;
		changed += res.changed;
		after = res.next;
	} while ( after );
	return { processed, changed };
}

export const CATEGORIES = {
	genuine: { label: 'Genuine', hint: 'Real customers' },
	promotional: { label: 'Promotional', hint: 'Sales pitches & marketing' },
	spam: { label: 'Spam', hint: 'Bots, junk & scams' },
};

export function sourceLabel( key ) {
	return ( cfg.sources && cfg.sources[ key ] ) || key || 'Unknown';
}

export function formatDate( value, withTime = true ) {
	if ( ! value ) {
		return '';
	}
	const d = new Date( value.replace( ' ', 'T' ) );
	if ( Number.isNaN( d.getTime() ) ) {
		return value;
	}
	const opts = { day: 'numeric', month: 'short', year: 'numeric' };
	if ( withTime ) {
		opts.hour = 'numeric';
		opts.minute = '2-digit';
	}
	return d.toLocaleString( undefined, opts );
}

export function timeAgo( value ) {
	if ( ! value ) {
		return '';
	}
	const d = new Date( value.replace( ' ', 'T' ) );
	const diff = ( Date.now() - d.getTime() ) / 1000;
	if ( Number.isNaN( diff ) ) {
		return value;
	}
	if ( diff < 60 ) {
		return 'just now';
	}
	if ( diff < 3600 ) {
		return Math.floor( diff / 60 ) + 'm ago';
	}
	if ( diff < 86400 ) {
		return Math.floor( diff / 3600 ) + 'h ago';
	}
	if ( diff < 86400 * 7 ) {
		return Math.floor( diff / 86400 ) + 'd ago';
	}
	return formatDate( value, false );
}

export function downloadText( filename, text, type = 'text/csv;charset=utf-8' ) {
	const blob = new Blob( [ text ], { type } );
	const link = document.createElement( 'a' );
	link.href = URL.createObjectURL( blob );
	link.download = filename;
	document.body.appendChild( link );
	link.click();
	link.remove();
	setTimeout( () => URL.revokeObjectURL( link.href ), 1000 );
}
