import { cfg } from './api';

/** Dashboard → How it works: the whole system in plain language (for owners and bosses). */
export default function Guide( { onTab } ) {
	const link = ( tab, label ) => (
		<button type="button" className="ls-linkbtn" onClick={ () => onTab( tab ) }>
			{ label }
		</button>
	);

	return (
		<section className="ls-guide-page">
			<div className="ls-panel ls-guide">
				<h3 className="ls-h3">What LeadSift does</h3>
				<p>
					Every message people send through the forms on <b>{ cfg.siteName || 'your website' }</b> is caught, saved and sorted into three groups – so real customers never get lost between sales pitches and junk:
				</p>
				<div className="ls-guide-cats">
					<div className="ls-guide-cat ls-guide-cat--genuine">
						<b>Genuine</b>
						<span>Real people who may buy or need help – prices, bookings, orders, support, jobs.</span>
					</div>
					<div className="ls-guide-cat ls-guide-cat--promotional">
						<b>Promotional</b>
						<span>People selling <i>to</i> you – SEO, marketing, websites, apps, loans, “partnerships”.</span>
					</div>
					<div className="ls-guide-cat ls-guide-cat--spam">
						<b>Spam</b>
						<span>Bots and junk – gibberish, fake phone numbers, links, scams, test entries.</span>
					</div>
				</div>
				<p className="ls-muted">Nothing is deleted or blocked. Everything is kept, and any inquiry can be moved with one click.</p>
			</div>

			<div className="ls-panel ls-guide">
				<h3 className="ls-h3">What happens when someone sends a form</h3>
				<ol className="ls-steps">
					<li>
						<span className="ls-steps__n">1</span>
						<div>
							<b>Caught.</b> LeadSift listens to the form plugins on the website (Contact Form 7, WPForms, Gravity Forms, Elementor, Fluent Forms, Ninja Forms, Formidable, or a custom form). The form still works exactly as before.
						</div>
					</li>
					<li>
						<span className="ls-steps__n">2</span>
						<div>
							<b>Saved.</b> The whole submission is stored in your own WordPress database – not on any outside server.
						</div>
					</li>
					<li>
						<span className="ls-steps__n">3</span>
						<div>
							<b>Checked by the rules (free, instant).</b> LeadSift knows which field of each form is the name, email, phone and message ({ link( 'forms', 'Forms tab' ) }). It then checks: are required fields real (no fake phone like 9999999999, no “asdf”, no one-word “hi”)? Does it look like a bot, a link dump or a scam? Is it someone selling a service (“we can rank your website…”)? Or does it sound like a customer (“what is the price”, “I want to book”)? Plus the website owner&apos;s own rules. Each signal adds or removes points: <b>0–100 risk score</b>.
						</div>
					</li>
					<li>
						<span className="ls-steps__n">4</span>
						<div>
							<b>Sorted.</b> Low score = Genuine. High score = Promotional or Spam, whichever signals were stronger. Every inquiry shows the reasons in plain words, so you can see <i>why</i>.
						</div>
					</li>
					<li>
						<span className="ls-steps__n">5</span>
						<div>
							<b>AI second opinion (optional).</b> If an AI (e.g. ChatGPT) is connected, unsure inquiries are also read by the AI in the background. Every AI call, its tokens and its cost are on the { link( 'usage', 'AI usage tab' ) }.
						</div>
					</li>
					<li>
						<span className="ls-steps__n">6</span>
						<div>
							<b>Shown here, live.</b> This dashboard checks for new inquiries every 10 seconds. If the owner turned it on, an email goes out for genuine inquiries only.
						</div>
					</li>
					<li>
						<span className="ls-steps__n">7</span>
						<div>
							<b>Learns.</b> When you move an inquiry (e.g. Promotional → Genuine), future inquiries from that email go to the same place.
						</div>
					</li>
				</ol>
			</div>

			<div className="ls-two-col">
				<div className="ls-panel ls-guide">
					<h3 className="ls-h3">Where everything is</h3>
					<p>
						<b>This dashboard page</b> (needs a Login ID &amp; password):
					</p>
					<ul className="ls-guide-list">
						<li>{ link( 'inquiries', 'Inquiries' ) } – every inquiry, filters, search, details, move, export to CSV</li>
						<li>{ link( 'forms', 'Forms' ) } – every form on the website, its fields, and its numbers</li>
						<li>{ link( 'insights', 'Insights' ) } – charts: genuine rate, per day, per form, top reasons</li>
						<li>{ link( 'usage', 'AI usage' ) } – AI calls, tokens and cost</li>
						<li>{ link( 'settings', 'Settings' ) } – filter strength, emails, phone check, earlier entries</li>
					</ul>
					<p>
						<b>WordPress admin → LeadSift</b> (website manager only):
					</p>
					<ul className="ls-guide-list">
						<li>Dashboard page &amp; login – this page&apos;s link, Login IDs and passwords</li>
						<li>Rules &amp; AI – own rules, keywords, sender lists, “Read my business”, the AI key</li>
					</ul>
				</div>
				<div className="ls-panel ls-guide">
					<h3 className="ls-h3">Data, safety and cost</h3>
					<ul className="ls-guide-list">
						<li>
							<b>Your data stays on your website.</b> Only when an AI is connected, the text of the inquiries the AI checks is sent to that AI company.
						</li>
						<li>
							<b>Earlier entries:</b> form entries saved before LeadSift was installed are imported and sorted too (no emails are sent for them).
						</li>
						<li>
							<b>Logins</b> are separate from WordPress accounts. Passwords are stored encrypted, 8 wrong tries lock sign-in for 15 minutes, and the page is hidden from Google.
						</li>
						<li>
							<b>Cost:</b> LeadSift and its rules are free. The AI is optional and billed by the AI company per use – see { link( 'usage', 'AI usage' ) }.
						</li>
					</ul>
				</div>
			</div>
		</section>
	);
}
