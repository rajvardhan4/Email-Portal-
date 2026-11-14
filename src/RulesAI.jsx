import { useState, useEffect } from '@wordpress/element';
import { api, cfg, CATEGORIES, rescanAll } from './api';
import { Button, Spinner, CategoryBadge, ScoreMeter, Reason } from './ui';
import { MyRules, FIELDS, OPERATORS } from './Rules';

const MODELS = [
	[ 'claude-opus-5-5', 'Claude Opus 5.5 – most accurate (recommended)' ],
	[ 'claude-sonnet-5-5', 'Claude Sonnet 5.5 – faster, lower cost' ],
	[ 'claude-haiku-5-5', 'Claude Haiku 5.5 – fastest, lowest cost' ],
];


export default function RulesAI( { onSaved } ) {
	const [ s, setS ] = useState( null );
	const [ rules, setRules ] = useState( [] );
	const [ key, setKey ] = useState( '' );
	const [ compatKey, setCompatKey ] = useState( '' );
	const [ models, setModels ] = useState( [] );
	const [ modelsBusy, setModelsBusy ] = useState( false );
	const [ modelsMsg, setModelsMsg ] = useState( '' );
	const [ secret, setSecret ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( '' );

	useEffect( () => {
		api( 'settings' ).then( ( r ) => {
			setS( r.settings );
			setRules( r.settings.custom_rules || [] );
		} );
	}, [] );

	if ( ! s ) {
		return (
			<section className="ls-panel">
				<div className="ls-loading">
					<Spinner />
				</div>
			</section>
		);
	}

	const set = ( k, v ) => setS( { ...s, [ k ]: v } );

	// Which AI is set up (saved key + address + model).
	const presets = cfg.aiPresets || {};
	const preset = presets[ s.ai_compat_preset ] || {};
	const compatReady = !! s.ai_compat_base_url && !! s.ai_compat_model && ( s.ai_compat_key_set || ! preset.needs_key );
	const aiReady = ( s.ai_provider === 'claude' && s.ai_key_set ) || ( s.ai_provider === 'compat' && compatReady );
	const aiOn = aiReady || ( s.ai_provider === 'custom' && !! s.ai_custom_url );
	let aiLabel = 'your API';
	if ( s.ai_provider === 'claude' ) {
		aiLabel = 'Claude AI (Anthropic)';
	} else if ( s.ai_provider === 'compat' ) {
		aiLabel = s.ai_compat_preset === 'other' ? `your AI (${ s.ai_compat_model || 'model' })` : ( preset.label || 'your AI' ).replace( / \(.*\)$/, '' );
	}

	const pickPreset = ( k ) => {
		const p = presets[ k ] || {};
		setS( { ...s, ai_compat_preset: k, ai_compat_base_url: p.base_url || s.ai_compat_base_url, ai_compat_model: '' } );
		setModels( [] );
		setModelsMsg( '' );
	};

	const loadModels = () => {
		setModelsBusy( true );
		setModelsMsg( '' );
		api( 'ai/models', { method: 'POST', data: { preset: s.ai_compat_preset, base_url: s.ai_compat_base_url, key: compatKey } } )
			.then( ( r ) => {
				setModels( r.models );
				setModelsMsg( r.models.length ? `${ r.models.length } models found – pick one below.` : 'No models returned. Type the model name yourself.' );
			} )
			.catch( ( e ) => setModelsMsg( e.message ) )
			.finally( () => setModelsBusy( false ) );
	};
	const save = ( extra = {} ) => {
		setSaving( true );
		setNotice( '' );
		api( 'settings', {
			method: 'POST',
			data: {
				custom_rules: rules,
				ai_provider: s.ai_provider,
				ai_model: s.ai_model,
				ai_mode: s.ai_mode,
				ai_business_context: s.ai_business_context,
				ai_custom_url: s.ai_custom_url,
				ai_api_key: key,
				ai_custom_secret: secret,
				ai_compat_preset: s.ai_compat_preset,
				ai_compat_base_url: s.ai_compat_base_url,
				ai_compat_model: s.ai_compat_model,
				ai_compat_key: compatKey,
				ai_price_in: s.ai_price_in,
				ai_price_out: s.ai_price_out,
				promo_keywords: s.promo_keywords,
				genuine_keywords: s.genuine_keywords,
				trusted_senders: s.trusted_senders,
				blocked_senders: s.blocked_senders,
				...extra,
			},
		} )
			.then( ( r ) => {
				setS( r.settings );
				setRules( r.settings.custom_rules || [] );
				setKey( '' );
				setCompatKey( '' );
				setSecret( '' );
				const bad = ( r.settings.custom_rules || [] ).filter( ( x ) => x.invalid ).length;
				setNotice( bad ? `Saved. ${ bad } rule(s) need a fix (marked in red) and are skipped until then.` : 'Saved. Re-checking your existing inquiries with the new rules…' );
				if ( ! bad ) {
					rescanAll()
						.then( ( res ) => setNotice( `Saved. New inquiries use these rules, and ${ res.processed } existing inquiries were re-checked (${ res.changed } changed).` ) )
						.catch( () => setNotice( 'Saved.' ) );
				}
				onSaved();
			} )
			.catch( ( e ) => setNotice( e.message ) )
			.finally( () => setSaving( false ) );
	};

	return (
		<section className="ls-settings">
			{ notice && <div className="ls-notice">{ notice }</div> }

			<BusinessReader
				aiReady={ aiReady }
				aiLabel={ aiLabel }
				onApply={ ( summary, picked ) => {
					const sig = ( r ) => [ r.field, r.operator, ( r.value || '' ).toLowerCase(), r.action ].join( '|' );
					const have = new Set( rules.map( sig ) );
					const fresh = picked.filter( ( r ) => ! have.has( sig( r ) ) ).map( ( { why, ...r } ) => r ); // eslint-disable-line no-unused-vars
					const merged = [ ...rules, ...fresh ];
					setRules( merged );
					set( 'ai_business_context', summary );
					save( { custom_rules: merged, ai_business_context: summary } );
				} }
			/>

			<HowItWorks />

			<MyRules rules={ rules } setRules={ setRules } />

			<div className="ls-two-col">
				<div className="ls-panel">
					<h3 className="ls-h3">Quick keywords</h3>
					<label className="ls-field">
						<span>Always count as promotional (one per line)</span>
						<textarea className="ls-input" rows="4" value={ s.promo_keywords } onChange={ ( e ) => set( 'promo_keywords', e.target.value ) } placeholder={ 'instagram followers\nwebsite audit' } />
					</label>
					<label className="ls-field">
						<span>Words your real customers use (one per line)</span>
						<textarea className="ls-input" rows="4" value={ s.genuine_keywords } onChange={ ( e ) => set( 'genuine_keywords', e.target.value ) } placeholder={ 'site visit\n2 bhk\nbulk order' } />
					</label>
				</div>
				<div className="ls-panel">
					<h3 className="ls-h3">Sender lists</h3>
					<label className="ls-field">
						<span>Trusted emails or domains — always Genuine</span>
						<textarea className="ls-input" rows="4" value={ s.trusted_senders } onChange={ ( e ) => set( 'trusted_senders', e.target.value ) } placeholder={ 'client@company.com\nmypartner.in' } />
					</label>
					<label className="ls-field">
						<span>Blocked emails or domains — always Promotional</span>
						<textarea className="ls-input" rows="4" value={ s.blocked_senders } onChange={ ( e ) => set( 'blocked_senders', e.target.value ) } placeholder={ 'seo-agency.com' } />
					</label>
				</div>
			</div>

			{ /* ------------------------- AI check ------------------------- */ }
			<div className="ls-panel">
				<h3 className="ls-h3">AI check (optional)</h3>
				<p className="ls-muted">
					After the rules decide, the AI reads the inquiry like a human would and gives a second opinion in the background (your visitors never wait). If the AI is at least 70% sure, its verdict is used; otherwise the inquiry is marked <b>⚠ Needs a look</b>.
				</p>

				<div className="ls-radio-cards">
					{ [
						[ 'none', 'Off', 'Rules only. Free, instant, nothing leaves your site.' ],
						[ 'claude', 'Claude AI', 'Anthropic’s Claude reads each inquiry. Needs an API key (paid per use).' ],
						[ 'compat', 'Any other AI', 'OpenAI (ChatGPT), Google Gemini, Groq, Mistral, DeepSeek, OpenRouter, Grok, Ollama… Use your own key.' ],
						[ 'custom', 'My own API', 'Send inquiries to any URL you control and use its answer.' ],
					].map( ( [ v, label, help ] ) => (
						<label key={ v } className={ `ls-radio-card${ s.ai_provider === v ? ' is-active' : '' }` }>
							<input type="radio" name="ls-ai-provider" checked={ s.ai_provider === v } onChange={ () => set( 'ai_provider', v ) } />
							<strong>{ label }</strong>
							<span>{ help }</span>
						</label>
					) ) }
				</div>

				{ s.ai_provider === 'claude' && (
					<div className="ls-ai-form">
						<label className="ls-field">
							<span>Claude API key { s.ai_key_set && <em className="ls-saved">saved ({ s.ai_key_hint })</em> }</span>
							{ s.ai_key_locked ? (
								<p className="ls-muted">The key is set in wp-config.php (LEADSIFT_CLAUDE_API_KEY).</p>
							) : (
								<div className="ls-row">
									<input type="password" autoComplete="off" className="ls-input ls-grow" placeholder={ s.ai_key_set ? 'Type a new key to replace the saved one' : 'sk-ant-…' } value={ key } onChange={ ( e ) => setKey( e.target.value ) } />
									{ s.ai_key_set && (
										<Button variant="danger" onClick={ () => save( { ai_api_key_clear: true } ) }>
											Remove key
										</Button>
									) }
								</div>
							) }
							<small className="ls-muted">
								Create one at console.anthropic.com → API keys. Billing is on your Anthropic account.
							</small>
						</label>
						<label className="ls-field">
							<span>Model</span>
							<select className="ls-input" value={ s.ai_model } onChange={ ( e ) => set( 'ai_model', e.target.value ) }>
								{ MODELS.map( ( [ v, l ] ) => (
									<option key={ v } value={ v }>
										{ l }
									</option>
								) ) }
							</select>
						</label>
					</div>
				) }

				{ s.ai_provider === 'compat' && (
					<div className="ls-ai-form">
						<label className="ls-field">
							<span>AI provider</span>
							<select className="ls-input" value={ s.ai_compat_preset } onChange={ ( e ) => pickPreset( e.target.value ) }>
								{ Object.keys( presets ).map( ( k ) => (
									<option key={ k } value={ k }>
										{ presets[ k ].label }
									</option>
								) ) }
							</select>
						</label>
						<label className="ls-field">
							<span>API address (base URL)</span>
							<input className="ls-input" placeholder="https://api.example.com/v1" value={ s.ai_compat_base_url } onChange={ ( e ) => set( 'ai_compat_base_url', e.target.value ) } />
							<small className="ls-muted">
								Filled in for you. For any other service, paste its OpenAI-compatible address (it usually ends with /v1).
								{ s.ai_compat_preset === 'ollama' && ' Ollama must be reachable from your web server.' }
							</small>
						</label>
						<label className="ls-field">
							<span>
								API key { s.ai_compat_key_set && <em className="ls-saved">saved ({ s.ai_compat_key_hint })</em> } { preset.needs_key === false && <em className="ls-muted">(optional)</em> }
							</span>
							<div className="ls-row">
								<input type="password" autoComplete="off" className="ls-input ls-grow" placeholder={ s.ai_compat_key_set ? 'Type a new key to replace the saved one' : 'Paste your API key' } value={ compatKey } onChange={ ( e ) => setCompatKey( e.target.value ) } />
								{ s.ai_compat_key_set && (
									<Button variant="danger" onClick={ () => save( { ai_compat_key_clear: true } ) }>
										Remove key
									</Button>
								) }
							</div>
							{ preset.key_url && (
								<small className="ls-muted">
									Get a key:{ ' ' }
									<a href={ preset.key_url } target="_blank" rel="noreferrer">
										{ preset.key_url.replace( /^https?:\/\//, '' ) }
									</a>
									. Billing is on your account with that provider.
								</small>
							) }
						</label>
						<label className="ls-field">
							<span>Model</span>
							<div className="ls-row">
								<input className="ls-input ls-grow" list="ls-model-list" placeholder="Type a model name or click “Load models”" value={ s.ai_compat_model } onChange={ ( e ) => set( 'ai_compat_model', e.target.value ) } />
								<Button onClick={ loadModels } disabled={ modelsBusy || ! s.ai_compat_base_url }>
									{ modelsBusy ? 'Loading…' : 'Load models' }
								</Button>
							</div>
							<datalist id="ls-model-list">
								{ models.map( ( m ) => (
									<option key={ m } value={ m } />
								) ) }
							</datalist>
							{ models.length > 0 && (
								<select className="ls-input" value={ models.includes( s.ai_compat_model ) ? s.ai_compat_model : '' } onChange={ ( e ) => set( 'ai_compat_model', e.target.value ) }>
									<option value="">— pick a model ({ models.length }) —</option>
									{ models.map( ( m ) => (
										<option key={ m } value={ m }>
											{ m }
										</option>
									) ) }
								</select>
							) }
							{ modelsMsg && <small className="ls-muted">{ modelsMsg }</small> }
							<small className="ls-muted">Tip: a bigger, newer model is more accurate; a small one is cheaper and faster. Use “Test it” below after saving to check the answers.</small>
							{ s.ai_compat_preset === 'openai' && (
								<small className="ls-muted">
									For sorting inquiries a small model is plenty: <b>gpt-5-mini</b> ($0.25 in / $2 out per 1M tokens) or <b>gpt-5-nano</b> ($0.05 / $0.40). Big models like gpt-5.5 cost 10–20× more for little gain here.
								</small>
							) }
						</label>
					</div>
				) }

				{ s.ai_provider === 'custom' && (
					<div className="ls-ai-form">
						<label className="ls-field">
							<span>API URL</span>
							<input className="ls-input" placeholder="https://your-api.example.com/classify" value={ s.ai_custom_url } onChange={ ( e ) => set( 'ai_custom_url', e.target.value ) } />
						</label>
						<label className="ls-field">
							<span>Secret token (optional, sent as “Authorization: Bearer …”) { s.ai_secret_set && <em className="ls-saved">saved</em> }</span>
							<input type="password" autoComplete="off" className="ls-input" value={ secret } onChange={ ( e ) => setSecret( e.target.value ) } placeholder={ s.ai_secret_set ? 'Type a new token to replace it' : '' } />
						</label>
						<details className="ls-dev">
							<summary>What your API receives and must return</summary>
							<pre>{ `POST <your URL>
{
  "inquiry": { "name": "", "email": "", "phone": "", "subject": "",
               "message": "", "extra_text": "", "form_name": "", "source": "" },
  "rules_verdict": { "category": "promotional", "score": 62 },
  "site": "https://your-site.com"
}

Reply (JSON):
{ "category": "genuine" | "promotional" | "spam",
  "confidence": 0-100,
  "reason": "One short sentence" }` }</pre>
						</details>
					</div>
				) }

				{ s.ai_provider !== 'none' && (
					<>
						<label className="ls-field">
							<span>When should the AI check?</span>
							<select className="ls-input" value={ s.ai_mode } onChange={ ( e ) => set( 'ai_mode', e.target.value ) }>
								<option value="unsure">Only when the rules are unsure (fewer API calls)</option>
								<option value="all">Every inquiry (most accurate, more API calls)</option>
							</select>
						</label>
						<label className="ls-field">
							<span>What does your business do? (helps the AI judge what a real customer looks like)</span>
							<textarea
								className="ls-input"
								rows="3"
								value={ s.ai_business_context }
								onChange={ ( e ) => set( 'ai_business_context', e.target.value ) }
								placeholder="e.g. We are a real-estate agency in Jaipur selling 2/3 BHK flats. Customers ask about prices, site visits and home loans."
							/>
						</label>
						<div className="ls-field">
							<span>Price for the cost estimate (optional, US$ per 1 million tokens)</span>
							<div className="ls-row">
								<input className="ls-input ls-input--num" inputMode="decimal" placeholder="input" value={ s.ai_price_in || '' } onChange={ ( e ) => set( 'ai_price_in', e.target.value ) } />
								<input className="ls-input ls-input--num" inputMode="decimal" placeholder="output" value={ s.ai_price_out || '' } onChange={ ( e ) => set( 'ai_price_out', e.target.value ) } />
							</div>
							<small className="ls-muted">
								LeadSift already knows the prices of OpenAI models (gpt-5, gpt-5-mini, gpt-4.1, gpt-4o…). Fill these in only for other AIs, or if your price is different. The tokens and cost of every AI call are shown on the dashboard page → <b>AI usage</b>.
							</small>
						</div>
						<p className="ls-muted ls-privacy">
							Privacy: when the AI check is on, the inquiry text (name, email, phone, message) is sent to { aiLabel } for checking. Mention this in your privacy policy.
						</p>
					</>
				) }
			</div>

			<div className="ls-savebar">
				<Button variant="primary" onClick={ () => save() } disabled={ saving }>
					{ saving ? 'Saving…' : 'Save rules & AI settings' }
				</Button>
			</div>

			<Tester rules={ rules } aiOn={ aiOn } />
		</section>
	);
}

function describeRule( r ) {
	const f = ( FIELDS.find( ( x ) => x[ 0 ] === r.field ) || [ '', r.field ] )[ 1 ];
	const o = ( OPERATORS.find( ( x ) => x[ 0 ] === r.operator ) || [ '', r.operator ] )[ 1 ];
	const value = r.operator === 'is_empty' ? '' : ` “${ r.value }”`;
	const [ mode, cat ] = r.action.split( '_' );
	const label = cat.charAt( 0 ).toUpperCase() + cat.slice( 1 );
	let then = `Always ${ label }`;
	if ( mode === 'score' ) {
		then = `Leans ${ label } (${ cat === 'genuine' ? '−' : '+' }${ r.points } pts)`;
	}
	return { cond: `If ${ f } ${ o }${ value }`, then };
}

/**
 * "Read my business": scans the website and suggests a summary + rules.
 */
function BusinessReader( { aiReady, aiLabel, onApply } ) {
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ result, setResult ] = useState( null );
	const [ summary, setSummary ] = useState( '' );
	const [ picked, setPicked ] = useState( [] );

	const read = () => {
		setBusy( true );
		setError( '' );
		setResult( null );
		api( 'business/analyze', { method: 'POST' } )
			.then( ( r ) => {
				setResult( r );
				setSummary( r.summary );
				setPicked( r.rules.map( ( x ) => x.id ) );
			} )
			.catch( ( e ) => setError( e.message || 'Could not read the website.' ) )
			.finally( () => setBusy( false ) );
	};

	const toggle = ( id ) => setPicked( picked.includes( id ) ? picked.filter( ( x ) => x !== id ) : [ ...picked, id ] );

	return (
		<div className="ls-panel ls-business">
			<div className="ls-business__head">
				<div>
					<h3 className="ls-h3">📖 Read my business</h3>
					<p className="ls-muted">
						LeadSift reads this website (pages, menus, products, forms), understands what you sell and suggests rules made for your business. You choose which ones to keep.
						{ aiReady ? ` Using ${ aiLabel }.` : ' Without an AI it does a basic scan; with an AI (Claude, OpenAI, Gemini…) the rules are much smarter.' }
					</p>
				</div>
				<Button variant="primary" onClick={ read } disabled={ busy }>
					{ busy ? 'Reading your website…' : result ? 'Read again' : 'Read my business' }
				</Button>
			</div>

			{ busy && (
				<div className="ls-business__busy">
					<Spinner /> Reading pages and { aiReady ? `asking ${ aiLabel } to understand your business (can take up to a minute)…` : 'finding what you offer…' }
				</div>
			) }
			{ error && <div className="ls-notice ls-notice--error">{ error }</div> }

			{ result && (
				<div className="ls-business__result">
					<p className="ls-muted">
						Read { result.stats.pages } pages, { result.stats.menu_items } menu items, { result.stats.categories } categories, { result.stats.products } products and { result.stats.forms } forms ·{ ' ' }
						<b>{ result.source === 'ai' ? `Understood by ${ result.ai_label || 'AI' }` : 'Basic scan' }</b>
					</p>
					{ result.note && <div className="ls-notice">{ result.note }</div> }

					<label className="ls-field">
						<span>Your business, in a few lines (you can edit this – it is given to the AI with every check)</span>
						<textarea className="ls-input" rows="4" value={ summary } onChange={ ( e ) => setSummary( e.target.value ) } />
					</label>

					<div className="ls-block__label">Suggested rules ({ result.rules.length })</div>
					{ result.rules.length === 0 && <p className="ls-muted">No business-specific rules found. The built-in engine still works as usual.</p> }
					<ul className="ls-suggestions">
						{ result.rules.map( ( r ) => {
							const d = describeRule( r );
							const kind = r.action.endsWith( 'genuine' ) ? 'genuine' : r.action.endsWith( 'spam' ) ? 'spam' : 'promotional';
							return (
								<li key={ r.id } className={ picked.includes( r.id ) ? 'is-picked' : '' }>
									<label>
										<input type="checkbox" checked={ picked.includes( r.id ) } onChange={ () => toggle( r.id ) } />
										<div>
											<b>{ r.name.replace( /^Auto:\s*/, '' ) }</b>
											<span className="ls-suggestion__rule">
												{ d.cond } → <span className={ `ls-count ls-count--${ kind }` }>{ d.then }</span>
											</span>
											{ r.why && <span className="ls-muted">{ r.why }</span> }
										</div>
									</label>
								</li>
							);
						} ) }
					</ul>

					<div className="ls-business__actions">
						<Button variant="primary" onClick={ () => onApply( summary, result.rules.filter( ( r ) => picked.includes( r.id ) ) ) }>
							Save summary & add { picked.length } rule{ picked.length === 1 ? '' : 's' }
						</Button>
						<Button onClick={ () => setResult( null ) }>Discard</Button>
						<span className="ls-muted">Added rules appear under “My rules” – you can edit or switch them off any time.</span>
					</div>
				</div>
			) }
		</div>
	);
}

function HowItWorks() {
	const steps = [
		[ '1', 'Your lists', 'Trusted / blocked senders (Settings).' ],
		[ '2', 'Your rules', 'The rules on this page, top to bottom.' ],
		[ '3', 'Your corrections', 'Senders you moved by hand before.' ],
		[ '4', 'Built-in engine', '70+ checks: sales pitches, SEO offers, bots, links, fake emails, Hindi/Hinglish customers… → risk score 0–100.' ],
		[ '5', 'AI (optional)', 'Second opinion in the background. Used when ≥70% sure.' ],
		[ '6', 'You', 'Unsure ones go to ⚠ Needs a look. One click moves an inquiry and teaches LeadSift.' ],
	];
	return (
		<details className="ls-panel ls-how" open>
			<summary className="ls-h3">How LeadSift decides</summary>
			<ol className="ls-steps">
				{ steps.map( ( [ n, t, d ] ) => (
					<li key={ n }>
						<span className="ls-steps__n">{ n }</span>
						<div>
							<b>{ t }</b>
							<span>{ d }</span>
						</div>
					</li>
				) ) }
			</ol>
		</details>
	);
}

const SAMPLES = {
	genuine: { name: 'Priya Sharma', email: 'priya.sharma@gmail.com', phone: '98765 43210', form_name: 'Contact us', message: 'Hi, I need a quotation for 50 corporate gift boxes for Diwali. What is the price and can you deliver to Jaipur by next week?' },
	promotional: { name: 'Mike', email: 'mike@rankboost-agency.com', phone: '', form_name: 'Contact us', message: 'Hi, I was checking your website and noticed it is not ranking on the first page of Google. We are a leading SEO agency and can help you get more organic traffic. Would you be interested in a free audit?' },
	spam: { name: 'XkJhqwTbRz', email: 'abc@mailinator.com', phone: '', form_name: 'Contact us', message: 'Earn $5000 weekly with crypto trading!!! Click here http://bit.ly/x1 http://bit.ly/x2 http://bit.ly/x3' },
};

function Tester( { rules, aiOn } ) {
	const [ form, setForm ] = useState( SAMPLES.promotional );
	const [ withAi, setWithAi ] = useState( false );
	const [ result, setResult ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const run = () => {
		setBusy( true );
		setError( '' );
		api( 'test', { method: 'POST', data: { ...form, custom_rules: rules, with_ai: withAi && aiOn } } )
			.then( setResult )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<div className="ls-panel">
			<h3 className="ls-h3">Test it</h3>
			<p className="ls-muted">Paste any inquiry to see the verdict. Uses the rules on this page even before you save them. Nothing is stored.</p>
			<div className="ls-samples">
				Load example:
				{ Object.keys( SAMPLES ).map( ( k ) => (
					<button
						key={ k }
						type="button"
						className="ls-link"
						onClick={ () => {
							setForm( SAMPLES[ k ] );
							setResult( null );
						} }
					>
						{ CATEGORIES[ k ].label }
					</button>
				) ) }
			</div>
			<div className="ls-tester">
				<div className="ls-tester__inputs">
					<input className="ls-input" placeholder="Name" value={ form.name } onChange={ ( e ) => setForm( { ...form, name: e.target.value } ) } />
					<input className="ls-input" placeholder="Email" value={ form.email } onChange={ ( e ) => setForm( { ...form, email: e.target.value } ) } />
					<input className="ls-input" placeholder="Phone" value={ form.phone } onChange={ ( e ) => setForm( { ...form, phone: e.target.value } ) } />
					<input className="ls-input" placeholder="Form name" value={ form.form_name } onChange={ ( e ) => setForm( { ...form, form_name: e.target.value } ) } />
					<textarea className="ls-input" rows="5" placeholder="Message" value={ form.message } onChange={ ( e ) => setForm( { ...form, message: e.target.value } ) } />
					<label className="ls-check" title={ aiOn ? '' : 'Save an AI provider first' }>
						<input type="checkbox" checked={ withAi && aiOn } disabled={ ! aiOn } onChange={ ( e ) => setWithAi( e.target.checked ) } /> Also ask the AI { ! aiOn && <span className="ls-muted">(set up and save AI first)</span> }
					</label>
					<Button variant="primary" onClick={ run } disabled={ busy }>
						{ busy ? 'Checking…' : 'Check inquiry' }
					</Button>
				</div>
				<div className="ls-tester__result">
					{ error && <div className="ls-notice ls-notice--error">{ error }</div> }
					{ ! result && ! error && <p className="ls-muted">The verdict and reasons appear here.</p> }
					{ result && (
						<>
							<div className="ls-tester__verdict">
								<CategoryBadge category={ result.category } />
								<ScoreMeter score={ result.score } />
							</div>
							{ result.ai && result.ai.error && <div className="ls-notice ls-notice--error">AI: { result.ai.error }</div> }
							<ul className="ls-reasons">
								{ result.reasons.map( ( r, i ) => (
									<Reason key={ i } r={ r } />
								) ) }
							</ul>
						</>
					) }
				</div>
			</div>
		</div>
	);
}
