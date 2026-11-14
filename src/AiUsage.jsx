import { useState, useEffect } from '@wordpress/element';
import { api, timeAgo, formatDate } from './api';
import { Spinner, Button } from './ui';

/** Dashboard → AI usage: which AI is connected, every call, tokens, time and cost. */
export default function AiUsage( { liveTick } ) {
	const [ r, setR ] = useState( null );
	const [ error, setError ] = useState( '' );

	const load = () =>
		api( 'ai/usage' )
			.then( ( d ) => {
				setR( d );
				setError( '' );
			} )
			.catch( ( e ) => setError( e.message ) );

	useEffect( () => {
		load();
	}, [ liveTick ] ); // eslint-disable-line react-hooks/exhaustive-deps

	if ( ! r ) {
		return <section className="ls-panel">{ error ? <div className="ls-notice ls-notice--error">{ error }</div> : <Spinner /> }</section>;
	}

	const { setup, periods, avg } = r;
	const lastError = r.recent.find( ( x ) => 'error' === x.status );
	const lastIsError = r.recent.length > 0 && 'error' === r.recent[ 0 ].status;

	return (
		<section className="ls-usage">
			{ /* ---------- What is connected ---------- */ }
			<div className="ls-panel ls-usage-setup">
				<div className="ls-usage-setup__main">
					<h3 className="ls-h3">AI connection</h3>
					{ setup.enabled ? (
						<>
							<p>
								<span className="ls-usage-dot is-on" /> <b>Connected to { setup.label }</b> · model <code>{ setup.model }</code>
							</p>
							<p className="ls-muted">
								{ 'all' === setup.mode ? (
									<>Every new inquiry is sent to the AI for a second opinion.</>
								) : (
									<>
										Only inquiries the rules are <b>unsure</b> about are sent to the AI (risk score { setup.range[ 0 ] }–{ setup.range[ 1 ] }). Clear cases are decided by the free rules alone – that keeps the cost low.
									</>
								) }{ ' ' }
								The AI&apos;s answer is used when it is at least { setup.trust }% sure.
								{ ! setup.has_context && ' Tip: describe your business in Rules & AI (or use “Read my business”) so the AI knows what a real customer looks like.' }
							</p>
							<p className="ls-muted">
								Price used for the estimate:{ ' ' }
								{ setup.price ? (
									<>
										<b>${ fmtPrice( setup.price[ 0 ] ) }</b> per 1M input tokens, <b>${ fmtPrice( setup.price[ 2 ] ) }</b> per 1M output tokens ({ 'yours' === setup.price[ 3 ] ? 'price you entered' : `${ setup.label } list price` }).
									</>
								) : (
									<>unknown for this model – enter it in WordPress → LeadSift → Rules &amp; AI to see costs. Token counts below are exact anyway.</>
								) }
							</p>
						</>
					) : (
						<p>
							<span className="ls-usage-dot" /> <b>AI check is off.</b> Inquiries are sorted only by LeadSift&apos;s built-in rules – free, instant, nothing leaves your website. The website manager can connect an AI in WordPress → LeadSift → Rules &amp; AI.
						</p>
					) }
				</div>
				<Button onClick={ load }>Refresh</Button>
			</div>

			{ lastIsError && (
				<div className="ls-notice ls-notice--error">
					<b>The last AI call failed:</b> { lastError.error } { /credit|quota|rate limit|429|billing/i.test( lastError.error ) && '– check the credit balance / limits on your AI account.' } Inquiries are still sorted by the rules while the AI is unavailable.
				</div>
			) }

			{ /* ---------- Totals ---------- */ }
			<div className="ls-usage-cards">
				{ [
					[ 'today', 'Today' ],
					[ 'd7', 'Last 7 days' ],
					[ 'month', 'This month' ],
					[ 'all', 'All time' ],
				].map( ( [ k, label ] ) => {
					const p = periods[ k ];
					return (
						<div key={ k } className="ls-usage-card">
							<span className="ls-card__label">{ label }</span>
							<span className="ls-usage-card__cost">{ p.cost_known ? money( p.cost, true ) : '–' }</span>
							<span className="ls-usage-card__line">
								<b>{ num( p.calls ) }</b> AI call{ p.calls === 1 ? '' : 's' }
								{ p.errors > 0 && <em className="ls-usage-err"> · { p.errors } failed</em> }
							</span>
							<span className="ls-usage-card__line ls-muted">
								{ num( p.input ) } in · { num( p.output ) } out tokens
							</span>
						</div>
					);
				} ) }
			</div>

			<div className="ls-two-col">
				<div className="ls-panel">
					<h3 className="ls-h3">One AI check, on average</h3>
					{ periods.all.calls - periods.all.errors > 0 ? (
						<ul className="ls-usage-avg">
							<li>
								<b>{ num( avg.input ) }</b> input tokens <span className="ls-muted">– the instructions + the inquiry LeadSift sends</span>
							</li>
							<li>
								<b>{ num( avg.output ) }</b> output tokens <span className="ls-muted">– the AI&apos;s answer{ periods.all.reasoning > 0 ? ' (incl. its thinking)' : '' }</span>
							</li>
							<li>
								<b>{ avg.cost === null ? '–' : money( avg.cost, true ) }</b> per check{ avg.cost ? <span className="ls-muted"> · about { num( Math.floor( 1 / avg.cost ) ) } checks for $1</span> : null }
							</li>
							<li>
								<b>{ ( avg.ms / 1000 ).toFixed( 1 ) } s</b> answer time <span className="ls-muted">– in the background, visitors never wait</span>
							</li>
						</ul>
					) : (
						<p className="ls-muted">No AI calls yet. Numbers appear here after the first inquiry the AI checks.</p>
					) }
				</div>
				<div className="ls-panel">
					<h3 className="ls-h3">Last 30 days</h3>
					<ul className="ls-usage-avg">
						<li>
							<b>{ num( r.coverage.inquiries ) }</b> new inquiries arrived
						</li>
						<li>
							<b>{ num( r.coverage.checked ) }</b> of them were sent to the AI{ r.coverage.inquiries ? ` (${ Math.round( ( r.coverage.checked / r.coverage.inquiries ) * 100 ) }%)` : '' }
						</li>
						<li>
							AI agreed with the rules on <b>{ num( r.agreement.same ) }</b> of { num( r.agreement.n ) } checked inquiries{ r.agreement.diff > 0 ? `, and had a different opinion on ${ num( r.agreement.diff ) }` : '' }
						</li>
						<li>
							<b>{ periods.d30.cost_known ? money( periods.d30.cost ) : '–' }</b> estimated cost
						</li>
					</ul>
				</div>
			</div>

			<UsageChart daily={ r.daily } />

			{ /* ---------- Every call ---------- */ }
			<div className="ls-panel">
				<h3 className="ls-h3">Latest AI calls</h3>
				{ r.recent.length === 0 ? (
					<p className="ls-muted">No AI calls yet.</p>
				) : (
					<div className="ls-table-wrap">
						<table className="ls-table ls-usage-table">
							<thead>
								<tr>
									<th>When</th>
									<th>For</th>
									<th className="ls-hide-sm">Model</th>
									<th className="ls-num">Tokens in</th>
									<th className="ls-num">Tokens out</th>
									<th className="ls-num ls-hide-sm">Time</th>
									<th>Answer</th>
									<th className="ls-num">Cost</th>
								</tr>
							</thead>
							<tbody>
								{ r.recent.map( ( c ) => (
									<tr key={ c.id } className={ 'error' === c.status ? 'is-error' : '' }>
										<td className="ls-col-date" title={ formatDate( c.created_at ) }>
											{ timeAgo( c.created_at ) }
										</td>
										<td>{ purposeLabel( c ) }</td>
										<td className="ls-hide-sm">
											<code>{ c.model }</code>
										</td>
										<td className="ls-num">
											{ num( c.input_tokens ) }
											{ c.cached_tokens > 0 && <small className="ls-muted"> ({ num( c.cached_tokens ) } cached)</small> }
										</td>
										<td className="ls-num">
											{ num( c.output_tokens ) }
											{ c.reasoning_tokens > 0 && <small className="ls-muted"> ({ num( c.reasoning_tokens ) } thinking)</small> }
										</td>
										<td className="ls-num ls-hide-sm">{ ( c.ms / 1000 ).toFixed( 1 ) }s</td>
										<td>
											{ 'error' === c.status ? (
												<span className="ls-usage-err" title={ c.error }>
													Failed: { c.error.slice( 0, 70 ) }
												</span>
											) : c.verdict ? (
												<span className={ `ls-count ls-count--${ c.verdict }` }>
													{ c.verdict } · { c.confidence }%
												</span>
											) : (
												<span className="ls-muted">OK</span>
											) }
										</td>
										<td className="ls-num">{ null === c.cost || undefined === c.cost ? '–' : money( c.cost, true ) }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) }
				{ r.models.length > 1 && (
					<p className="ls-muted">
						By model:{ ' ' }
						{ r.models.map( ( m ) => `${ m.model }: ${ num( m.calls ) } calls${ null !== m.cost ? ' · ' + money( m.cost ) : '' }` ).join( ' · ' ) }
					</p>
				) }
			</div>

			<AiExplained setup={ setup } avg={ avg } />
		</section>
	);
}

function UsageChart( { daily } ) {
	const max = Math.max( 1, ...daily.map( ( d ) => d.calls ) );
	const total = daily.reduce( ( a, d ) => a + d.calls, 0 );
	return (
		<div className="ls-panel">
			<h3 className="ls-h3">AI calls per day (30 days)</h3>
			{ total === 0 ? (
				<p className="ls-muted">No AI calls in the last 30 days.</p>
			) : (
				<div className="ls-usage-chart">
					{ daily.map( ( d ) => (
						<div key={ d.date } className="ls-usage-chart__col" title={ `${ d.date }: ${ d.calls } calls · ${ num( d.tokens ) } tokens · ${ money( d.cost, true ) }` }>
							<div className="ls-usage-chart__bar" style={ { height: `${ Math.max( d.calls ? 4 : 0, ( d.calls / max ) * 100 ) }%` } } />
							<span>{ d.date.slice( 8 ) }</span>
						</div>
					) ) }
				</div>
			) }
		</div>
	);
}

/** Plain-language explanation of how the AI is used. */
function AiExplained( { setup, avg } ) {
	return (
		<div className="ls-panel ls-guide">
			<h3 className="ls-h3">How the AI is used</h3>
			<ol className="ls-steps">
				<li>
					<span className="ls-steps__n">1</span>
					<div>
						<b>A visitor sends a form.</b> LeadSift saves it at once and its built-in rules give it a risk score (0–100). This part is free and runs on your own website.
					</div>
				</li>
				<li>
					<span className="ls-steps__n">2</span>
					<div>
						<b>Only then, maybe, the AI.</b> { 'all' === setup.mode ? 'Every inquiry' : 'Only inquiries the rules are unsure about' } { setup.enabled ? '' : '(when an AI is connected) ' }are sent to the AI – one request per inquiry, in the background a second later, so the visitor never waits.
					</div>
				</li>
				<li>
					<span className="ls-steps__n">3</span>
					<div>
						<b>What is sent:</b> short instructions (what Genuine / Promotional / Spam mean, and what your business does) plus this one inquiry – form name, name, email, phone, subject, message and other fields. Nothing else from your website, never other inquiries, and the API key never reaches anyone&apos;s browser.
					</div>
				</li>
				<li>
					<span className="ls-steps__n">4</span>
					<div>
						<b>What comes back:</b> one word (genuine / promotional / spam), how sure it is (%), and a one-line reason – you see it in the inquiry&apos;s details as “AI says…”. If the AI is at least { setup.trust }% sure its answer is used, otherwise the inquiry is marked “Needs a look”. Inquiries you moved yourself and “Always …” rules are never changed by the AI.
					</div>
				</li>
				<li>
					<span className="ls-steps__n">5</span>
					<div>
						<b>Tokens = what you pay for.</b> AI APIs count text in tokens (about 4 English characters, or ¾ of a word, per token; Hindi uses more). <i>Input tokens</i> are what LeadSift sends, <i>output tokens</i> are the AI&apos;s answer – newer “reasoning” models also bill their hidden thinking as output. Cost = input tokens × input price + output tokens × output price. { avg.input > 0 && `Here one check uses about ${ num( avg.input ) } + ${ num( avg.output ) } tokens.` }
					</div>
				</li>
				<li>
					<span className="ls-steps__n">6</span>
					<div>
						<b>Other AI calls:</b> “Ask AI now” in an inquiry, “Test it” with “Also ask the AI”, and “Read my business” (reads your website pages once – a bigger call, a few thousand tokens). All of them are listed above.
					</div>
				</li>
			</ol>
		</div>
	);
}

function purposeLabel( c ) {
	if ( 'business' === c.purpose ) {
		return 'Read my business';
	}
	if ( 'test' === c.purpose ) {
		return 'Test (nothing saved)';
	}
	if ( c.inquiry_id ) {
		return (
			<span>
				Inquiry #{ c.inquiry_id }
				{ c.inquiry_name ? <span className="ls-muted"> · { c.inquiry_name }</span> : null }
			</span>
		);
	}
	return 'Inquiry';
}

function num( n ) {
	return ( Number( n ) || 0 ).toLocaleString( 'en-IN' );
}

function fmtPrice( n ) {
	const v = Number( n );
	return v >= 1 ? v.toFixed( 2 ) : v.toFixed( 3 ).replace( /0$/, '' );
}

/** US$ with enough decimals for tiny amounts. */
function money( v, precise = false ) {
	const n = Number( v ) || 0;
	if ( n === 0 ) {
		return '$0.00';
	}
	if ( n < 0.01 ) {
		return precise ? '$' + n.toFixed( n < 0.001 ? 5 : 4 ) : '< $0.01';
	}
	return '$' + n.toFixed( n < 1 ? 3 : 2 );
}
