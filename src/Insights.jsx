import { useState, useRef, useEffect } from '@wordpress/element';
import { CATEGORIES, sourceLabel } from './api';
import { Spinner, Empty } from './ui';

const ORDER = [ 'genuine', 'promotional', 'spam' ]; // Bottom → top in the stack.

export default function Insights( { stats } ) {
	if ( ! stats ) {
		return (
			<section className="ls-panel">
				<div className="ls-loading">
					<Spinner />
				</div>
			</section>
		);
	}

	const t = stats.totals;
	const total = t.genuine.total + t.promotional.total + t.spam.total;
	const last30 = stats.daily.reduce( ( acc, d ) => acc + d.genuine + d.promotional + d.spam, 0 );
	const genuine30 = stats.daily.reduce( ( acc, d ) => acc + d.genuine, 0 );
	const filtered = total - t.genuine.total;
	const maxReason = stats.top_reasons.length ? stats.top_reasons[ 0 ].count : 1;

	return (
		<section className="ls-insights">
			<div className="ls-hero-row">
				<div className="ls-hero">
					<span className="ls-hero__label">Genuine rate · last 30 days</span>
					<span className="ls-hero__value">{ last30 ? Math.round( ( genuine30 / last30 ) * 100 ) : 0 }%</span>
					<span className="ls-muted">
						{ genuine30 } genuine out of { last30 } inquiries
					</span>
				</div>
				<div className="ls-hero">
					<span className="ls-hero__label">Noise filtered out · all time</span>
					<span className="ls-hero__value">{ filtered }</span>
					<span className="ls-muted">promotional + spam inquiries kept out of your way</span>
				</div>
			</div>

			<div className="ls-panel">
				<h3 className="ls-h3">Inquiries per day · last 30 days</h3>
				{ last30 === 0 ? <Empty title="No inquiries in the last 30 days" /> : <DailyChart daily={ stats.daily } /> }
			</div>

			<div className="ls-two-col">
				<div className="ls-panel">
					<h3 className="ls-h3">By form</h3>
					{ stats.sources.length === 0 ? (
						<p className="ls-muted">No data yet.</p>
					) : (
						<table className="ls-mini-table">
							<thead>
								<tr>
									<th>Form plugin</th>
									{ ORDER.map( ( k ) => (
										<th key={ k } className="ls-num">
											{ CATEGORIES[ k ].label }
										</th>
									) ) }
								</tr>
							</thead>
							<tbody>
								{ stats.sources.map( ( s ) => (
									<tr key={ s.source }>
										<td>{ sourceLabel( s.source ) }</td>
										{ ORDER.map( ( k ) => (
											<td key={ k } className="ls-num">
												{ s[ k ] }
											</td>
										) ) }
									</tr>
								) ) }
							</tbody>
						</table>
					) }
				</div>
				<div className="ls-panel">
					<h3 className="ls-h3">Top reasons inquiries were filtered</h3>
					{ stats.top_reasons.length === 0 ? (
						<p className="ls-muted">Nothing filtered yet.</p>
					) : (
						<ul className="ls-reason-bars">
							{ stats.top_reasons.map( ( r ) => (
								<li key={ r.label }>
									<div className="ls-reason-bars__text">
										<span>{ r.label }</span>
										<b>{ r.count }</b>
									</div>
									<div className="ls-reason-bars__track">
										<div style={ { width: `${ ( r.count / maxReason ) * 100 }%` } } />
									</div>
								</li>
							) ) }
						</ul>
					) }
				</div>
			</div>
		</section>
	);
}

/** Stacked daily bar chart: genuine / promotional / spam. */
function DailyChart( { daily } ) {
	const wrap = useRef();
	const [ width, setWidth ] = useState( 720 );
	const [ hover, setHover ] = useState( null );

	useEffect( () => {
		if ( ! wrap.current ) {
			return undefined;
		}
		const measure = () => setWidth( Math.max( 280, wrap.current.clientWidth ) );
		measure();
		if ( typeof ResizeObserver === 'undefined' ) {
			window.addEventListener( 'resize', measure );
			return () => window.removeEventListener( 'resize', measure );
		}
		const ro = new ResizeObserver( measure );
		ro.observe( wrap.current );
		return () => ro.disconnect();
	}, [] );

	const height = 220;
	const pad = { top: 12, right: 8, bottom: 26, left: 32 };
	const innerW = width - pad.left - pad.right;
	const innerH = height - pad.top - pad.bottom;
	const totals = daily.map( ( d ) => d.genuine + d.promotional + d.spam );
	const max = niceMax( Math.max( 1, ...totals ) );
	const step = innerW / daily.length;
	const barW = Math.max( 3, Math.min( 22, step - 4 ) );
	const y = ( v ) => pad.top + innerH - ( v / max ) * innerH;
	const ticks = [ 0, max / 2, max ];
	const labelEvery = Math.ceil( daily.length / Math.max( 2, Math.floor( innerW / 64 ) ) );

	return (
		<div className="ls-chart">
			<div className="ls-legend">
				{ ORDER.map( ( k ) => (
					<span key={ k }>
						<i className={ `ls-swatch ls-swatch--${ k }` } />
						{ CATEGORIES[ k ].label }
					</span>
				) ) }
			</div>
			<div className="ls-chart__plot" ref={ wrap }>
			<svg width={ width } height={ height } role="img" aria-label="Inquiries per day, stacked by category">
				{ ticks.map( ( v ) => (
					<g key={ v }>
						<line x1={ pad.left } x2={ width - pad.right } y1={ y( v ) } y2={ y( v ) } className={ v === 0 ? 'ls-axis' : 'ls-grid' } />
						<text x={ pad.left - 6 } y={ y( v ) + 4 } className="ls-tick" textAnchor="end">
							{ Math.round( v ) }
						</text>
					</g>
				) ) }
				{ daily.map( ( d, i ) => {
					const cx = pad.left + step * i + step / 2;
					const x = cx - barW / 2;
					let acc = 0;
					const visible = ORDER.filter( ( k ) => d[ k ] > 0 );
					return (
						<g key={ d.day } onMouseEnter={ () => setHover( i ) } onMouseLeave={ () => setHover( null ) }>
							<rect x={ pad.left + step * i } y={ pad.top } width={ step } height={ innerH } fill="transparent" />
							{ hover === i && <rect x={ pad.left + step * i } y={ pad.top } width={ step } height={ innerH } className="ls-hover-col" /> }
							{ visible.map( ( k, idx ) => {
								const y0 = y( acc );
								acc += d[ k ];
								const y1 = y( acc );
								// 2px surface gap under every segment except the bottom one.
								const h = Math.max( 1, y0 - y1 - ( idx > 0 ? 2 : 0 ) );
								const top = idx === visible.length - 1;
								return <path key={ k } d={ barPath( x, y1, barW, h, top ? Math.min( 4, barW / 2, h ) : 0 ) } className={ `ls-bar ls-bar--${ k }` } />;
							} ) }
							{ i % labelEvery === 0 && (
								<text x={ cx } y={ height - 8 } className="ls-tick" textAnchor="middle">
									{ shortDay( d.day ) }
								</text>
							) }
						</g>
					);
				} ) }
			</svg>
			{ hover !== null && (
				<div
					className="ls-tooltip"
					style={ {
						left: Math.min( Math.max( pad.left + step * hover + step / 2, 70 ), width - 70 ),
						top: Math.max( 0, y( totals[ hover ] ) - 8 ),
					} }
				>
					<strong>{ longDay( daily[ hover ].day ) }</strong>
					{ [ ...ORDER ].reverse().map( ( k ) => (
						<div key={ k } className="ls-tooltip__row">
							<i className={ `ls-swatch ls-swatch--${ k }` } />
							<span>{ CATEGORIES[ k ].label }</span>
							<b>{ daily[ hover ][ k ] }</b>
						</div>
					) ) }
				</div>
			) }
			</div>
		</div>
	);
}

function barPath( x, y, w, h, r ) {
	if ( r <= 0 ) {
		return `M${ x },${ y }h${ w }v${ h }h${ -w }z`;
	}
	return `M${ x },${ y + h }V${ y + r }Q${ x },${ y } ${ x + r },${ y }H${ x + w - r }Q${ x + w },${ y } ${ x + w },${ y + r }V${ y + h }z`;
}

function niceMax( v ) {
	if ( v <= 4 ) {
		return 4;
	}
	const mag = Math.pow( 10, Math.floor( Math.log10( v ) ) );
	const n = v / mag;
	const nice = n <= 2 ? 2 : n <= 5 ? 5 : 10;
	return nice * mag;
}

function shortDay( day ) {
	const d = new Date( day + 'T00:00:00' );
	return d.toLocaleDateString( undefined, { day: 'numeric', month: 'short' } );
}

function longDay( day ) {
	const d = new Date( day + 'T00:00:00' );
	return d.toLocaleDateString( undefined, { weekday: 'short', day: 'numeric', month: 'short' } );
}
