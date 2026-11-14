// Sanity test for LeadSift_Form_Reader::check_values() logic (JS mirror).
// Keep in sync with includes/class-leadsift-form-reader.php.
function checkValues( def, values ) {
	const issues = [];
	const c = def.checks;
	const filled = [];
	for ( const f of def.fields ) {
		if ( f.role === 'ignore' ) continue;
		const exists = f.key in values;
		const value = exists ? String( values[ f.key ] ).trim() : '';
		if ( value ) filled.push( value.toLowerCase() );
		if ( c.check_required && f.required && exists && value === '' ) issues.push( [ `Required "${ f.label }" empty`, 30 ] );
		const allowed = ( f.options || [] ).map( ( o ) => o.trim().toLowerCase() );
		const hasOther = allowed.some( ( o ) => /^(other|others|anything else|any other)\b/.test( o ) );
		if ( c.check_options && value && allowed.length && ! hasOther ) {
			const given = value.toLowerCase().split( /[,\n]+/ ).map( ( x ) => x.trim() ).filter( Boolean );
			for ( const one of given ) {
				if ( ! allowed.includes( one ) && ! allowed.includes( value.toLowerCase() ) ) { issues.push( [ `"${ f.label }" not an option (${ one })`, 30 ] ); break; }
			}
		}
		if ( value && [ 'number', 'quantity' ].includes( f.type ) && ! /\d/.test( value ) ) issues.push( [ `Number "${ f.label }" has no number`, 20 ] );
		if ( f.role === 'message' && c.min_words > 0 && value ) {
			const words = ( value.match( /\p{L}+/gu ) || [] ).length;
			if ( words < c.min_words ) issues.push( [ `Message ${ words } words < ${ c.min_words }`, 25 ] );
		}
	}
	if ( c.check_same && filled.length >= 3 && new Set( filled ).size === 1 ) issues.push( [ 'Same text in every field', 35 ] );
	return issues;
}

const def = {
	checks: { check_required: true, check_options: true, check_same: true, min_words: 5 },
	fields: [
		{ key: 'your-name', label: 'Name', type: 'text', role: 'name', required: true },
		{ key: 'your-email', label: 'Email', type: 'email', role: 'email', required: true },
		{ key: 'service', label: 'Service', type: 'select', role: 'other', required: true, options: [ 'Website design', 'SEO', 'App development' ] },
		{ key: 'source', label: 'How did you hear', type: 'radio', role: 'other', options: [ 'Google', 'Friend', 'Other' ] },
		{ key: 'qty', label: 'Quantity', type: 'number', role: 'other' },
		{ key: 'your-message', label: 'Message', type: 'textarea', role: 'message', required: true },
		{ key: 'consent', label: 'I agree', type: 'acceptance', role: 'ignore', required: true },
	],
};
const cases = [
	[ 'real customer', { 'your-name': 'Priya', 'your-email': 'p@gmail.com', service: 'SEO', source: 'Google', qty: '2', 'your-message': 'I need SEO for my clinic website please share packages' }, 0 ],
	[ '"Other" field allows free text', { 'your-name': 'Priya', 'your-email': 'p@gmail.com', service: 'SEO', source: 'Instagram ad', 'your-message': 'I need SEO for my clinic website please share packages' }, 0 ],
	[ 'bot: fake dropdown value', { 'your-name': 'Bot', 'your-email': 'b@gmail.com', service: 'cheap viagra', 'your-message': 'I need SEO for my clinic website please share packages' }, 1 ],
	[ 'bot: required field blank', { 'your-name': '', 'your-email': 'b@gmail.com', service: 'SEO', 'your-message': 'I need SEO for my clinic website please share packages' }, 1 ],
	[ 'short message under form minimum', { 'your-name': 'Om', 'your-email': 'o@gmail.com', service: 'SEO', 'your-message': 'price please' }, 1 ],
	[ 'same text everywhere', { 'your-name': 'asdf', 'your-email': 'asdf', service: 'asdf', 'your-message': 'asdf' }, 2 ],
	[ 'number field with letters', { 'your-name': 'Om', 'your-email': 'o@gmail.com', service: 'SEO', qty: 'many', 'your-message': 'I need SEO for my clinic website please share packages' }, 1 ],
];
let ok = 0;
for ( const [ name, values, expectMin ] of cases ) {
	const issues = checkValues( def, values );
	const pass = expectMin === 0 ? issues.length === 0 : issues.length >= expectMin;
	ok += pass ? 1 : 0;
	console.log( `${ pass ? 'ok ' : 'BAD' } ${ name.padEnd( 34 ) } → ${ issues.map( ( i ) => i[ 0 ] ).join( '; ' ) || 'no issues' }` );
}
console.log( `${ ok } / ${ cases.length } form-check cases pass` );
process.exit( ok === cases.length ? 0 : 1 );
