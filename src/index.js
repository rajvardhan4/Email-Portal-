import { render, createRoot } from '@wordpress/element';
import AdminApp from './AdminApp';
import Portal from './Portal';
import { isPortal } from './api';
import './style.css';

function mount() {
	const el = document.getElementById( 'leadsift-root' );
	if ( ! el ) {
		return;
	}
	const root = isPortal ? <Portal /> : <AdminApp />;
	if ( createRoot ) {
		createRoot( el ).render( root );
	} else {
		render( root, el );
	}
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
