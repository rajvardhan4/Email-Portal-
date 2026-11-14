<?php
/**
 * The filtering engine.
 *
 * Every inquiry is scored from 0 to 100 ("risk score"):
 *   - Promotional points: sales pitches, SEO/marketing offers, cold outreach lines.
 *   - Spam points: bots, gibberish, scams, adult/crypto/casino, disposable emails, link dumps.
 *   - Genuine points (subtracted): real buying / booking / support intent, phone number, Hindi/Hinglish.
 *
 * risk >= threshold  -> Promotional or Spam (whichever side scored more), otherwise Genuine.
 * Every rule that fires is returned as a "reason" so the dashboard can show WHY.
 *
 * This class is pure PHP (no WordPress calls) so it can be unit-tested on its own.
 *
 * @package LeadSift
 */

defined( 'ABSPATH' ) || exit;

class LeadSift_Classifier {

	const CATEGORIES = array( 'genuine', 'promotional', 'spam' );

	const THRESHOLDS = array(
		'relaxed'  => 55,
		'balanced' => 40,
		'strict'   => 28,
	);

	/**
	 * Sales / marketing keyword patterns. [regex, weight, reason label].
	 * Who-is-talking-to-whom ("we offer… your website… book a call") is handled
	 * separately in voice_analysis().
	 */
	private static function promo_rules() {
		return array(
			// SEO & link selling.
			array( '\bseo\b', 12, 'Mentions SEO services' ),
			array( 'search engine optimi[sz]ation', 15, 'Mentions SEO services' ),
			array( 'back-?links?|do-?follow|link[- ]building|link (insertion|placement)', 18, 'Sells backlinks / link placement' ),
			array( 'guest[- ]?post|sponsored (post|article|content)|publish (a |an |your )?(guest|sponsored) (post|article)', 18, 'Guest-post / sponsored post offer' ),
			array( 'domain authority|high da\b|\bda\s*/\s*pa\b|\bdr\s*\d{2}', 15, 'Talks about domain authority' ),
			array( '(first|1st|top|front) page (of|on|in|pe|par) google|google (pe|par) (top|first)|(first|1st) page (pe|par)', 20, 'Promises Google first-page ranking' ),
			array( '(rank|ranking) (your|higher|better|on google|#?1)|google (ranking|rank|search results)', 12, 'Promises better ranking' ),
			array( 'organic (traffic|growth|followers)|(increase|boost|drive|double|triple) (your )?(website |site |organic )?traffic', 15, 'Offers website traffic / growth' ),

			// Service pitches.
			array( 'web(site)? (design|development|maintenance) (services?|company|agency|packages?)|website (maintenance|security) services', 14, 'Pitches web design / maintenance' ),
			array( 're-?design (of )?your (website|site)|redesign it', 15, 'Pitches a website redesign' ),
			array( '(mobile )?app development|custom (software|app|mobile app) development', 10, 'Pitches app development' ),
			array( 'digital marketing|social media (marketing|management|growth)|online marketing', 12, 'Pitches digital marketing' ),
			array( 'lead generation|qualified leads|more (leads|inquiries|enquiries|customers|clients|sales)\b', 12, 'Pitches lead generation' ),
			array( 'email (marketing|list|campaign)|verified emails|database of|decision makers', 14, 'Sells email lists / marketing' ),
			array( '(\bppc\b|google ads|facebook ads|meta ads|paid ads|google business profiles?|google maps (listing|ranking|optimi[sz]ation))', 10, 'Pitches ads / Google listing' ),
			array( 'virtual assistants?|offshore (staff|team)|white[- ]label|outsourc(e|ing) (your|services)', 12, 'Pitches outsourcing / virtual staff' ),
			array( 'staffing|recruitment agency|manpower (supply|services)|skilled workers for your', 12, 'Pitches staffing / recruitment' ),
			array( '(explainer|animated|promotional) videos?|video (editing|production) services', 10, 'Pitches video services' ),
			array( 'logo design|content writing|copywriting|blog articles|graphic design services|branding services', 10, 'Pitches design / writing services' ),
			array( 'business (loan|funding|financing)|working capital|merchant cash|instant (loan|approval)|low interest', 15, 'Offers loans / funding' ),
			array( 'get \d[\d,]* (real |active |organic |genuine |5[- ]star )?(youtube |instagram |facebook |google )?(subscribers|followers|likes|views|reviews|ratings)', 20, 'Sells followers / reviews' ),
			array( 'bulk (sms|whatsapp|messages?|emails?)|whatsapp marketing|sms marketing|\d+ (paise|ps) per|per (message|sms|lead|click|review)\b|\d+ credits|on sign ?up', 14, 'Pitches bulk SMS / marketing credits' ),
			array( '(hosting|server|domain) (services?|plans?|brokerage)|managed (wordpress )?hosting|migrate (to|your)|translation (services?|at)|professional translation|insurance plans?|group health insurance|premium rates', 10, 'Pitches hosting / insurance / other services' ),
			array( '\b(cut|reduce|save|increase|boost|grow|double|improve) (\w+ ){0,2}(by|upto|up to) \d+ ?%|\d+ ?% (more|less|increase|growth)|(in|within) (just |only )?\d+ (days|minutes|hours|weeks)\b', 12, 'Promises results ("cut costs by 40%", "in 7 days")' ),
			array( 'grow your (business|brand|sales|revenue|instagram|followers)|boost your (sales|business|revenue|online presence|brand)|businesses like yours|companies like yours|brands like yours', 15, 'Generic "grow your business" pitch' ),
			array( 'online presence', 8, 'Generic "grow your business" pitch' ),
			array( 'payroll|\bcrm\b|\berp\b|saas|payment gateway|invoicing software|hr software', 8, 'Pitches business software' ),
			array( 'cctv and security installation|security installation services|site survey', 8, 'Pitches installation services' ),

			// Suppliers / partnership pitches.
			array( 'manufacturers? and suppliers?|leading (supplier|manufacturer|exporter)|wholesale rates|export company|we export|exporters? of', 14, 'Supplier / manufacturer pitch' ),
			array( 'company profile|price ?list attached|find (attached )?our (price ?list|catalogue|catalog|company profile|brochure)|catalogue attached', 12, 'Sends company profile / price list' ),
			array( 'looking for (distributors|dealers in your|partners|resellers|investors|clients)', 12, 'Looking for distributors / clients' ),
			array( 'business proposal|partnership opportunit|collaboration opportunit|small commission|affiliate (program|partnership)', 12, 'Business proposal / partnership pitch' ),

			// Pricing written the way sellers write it.
			array( '(starting|starts|plans start|prices start|packages start|price starts) (at|from)|(\$|usd|rs\.?|inr|₹)\s?\d[\d,]*\s*(/|per)\s*(month|mo|hour|hr|year|yr|post|word|article|reel|video)|per (word|post|article|hour)\b|(\d+ )?rupees? per word', 14, 'Quotes their own prices' ),
			array( '(at|with|offer|offering|provide|providing|lowest|sabse) (very |the )?(affordable|cheap|low|lowest|competitive|kam) (price|prices|pricing|rates?|cost|fees?|rate me)|lowest (fees|price|rates?) in the market|charges (bahut )?kam', 10, 'Advertises cheap / affordable pricing' ),
			array( 'free (audit|consultation|analysis|report|trial|demo|proposal|sample|website review|site survey|quote|\d+ credits)|for free\b|try (it|us|our \w+) free|\d+ (months?|weeks?|days) free|free for \d+ (days|months)|no obligation|risk[- ]free|100% (safe|guaranteed|permanent)|guaranteed\b|money[- ]back|sample work available', 12, 'Offers something "free" / guaranteed to hook you' ),
			array( '(fee|fees|price|cost|charge|charges) (of|is|are) (just |only )?(\$|usd|rs\.?|inr|₹)\s?\d|(at|for) (just|only) (\$|rs\.?|inr|₹)?\s?\d|at factory (rate|price)|factory rates?|(small |low )?monthly fee|small fee|premium is|listing is free', 12, 'Quotes their own prices' ),
			array( 'special offer|limited[- ]time|exclusive offer|% off\b|discount (offer|code)|save \d+%', 12, 'Contains a sales offer' ),
			array( 'portfolio|behance|dribbble|fiverr|upwork|case stud(y|ies)', 12, 'Shares a portfolio / case studies' ),
			array( 'years of experience', 5, 'Talks about their experience' ),

			// Mass-email patterns.
			array( 'hope (this|my) (message|email|note) finds you|i hope you are doing well|hope you(\'re| are) (doing )?well', 8, 'Mass email greeting' ),
			array( 'dear (sir|madam|sir/madam|website owner|business owner|site owner|owner|team|business)|hello (team|business owner)|hi there', 6, 'Generic greeting (no real name)' ),
			array( 'just following up|following up on my (previous|last)|quick question', 8, 'Cold-email phrase' ),
			array( 'unsubscribe|opt[- ]?out|reply (with )?["\']?(stop|no|remove)|not interested\?', 15, 'Has unsubscribe / opt-out line (bulk email)' ),

			// Advertising-style commands at the start of a sentence: "Get…", "Try…", "Stop wasting…".
			array( '(^|[.!?]\s+|\n)\s*(get|stop|try|protect|migrate|switch|boost|grow|increase|upgrade|grab|claim|unlock|discover|transform|automate|rank|double|join|send (?!me|us)|save (?!my|me))\b[^.!?\n]{0,80}\b(your|our|free|today|now)\b', 12, 'Advertising-style command ("Get…", "Try it free", "Stop…")' ),
		);
	}

	/**
	 * Junk / scam / bot patterns.
	 */
	private static function spam_rules() {
		return array(
			array( '\b(crypto|bitcoin|btc|ethereum|usdt|nft|forex|binary option)', 25, 'Crypto / forex content' ),
			array( '\b(casino|betting|gambl|jackpot|slots?\b|poker)', 25, 'Casino / betting content' ),
			array( '\b(viagra|cialis|pharmacy|pills|weight loss pills|keto)\b', 25, 'Pharma / pills content' ),
			array( '\b(porn|xxx|nude|sexy|adult dating|hot girls|escort)', 40, 'Adult content' ),
			array( '(earn|make) (money|\$\d+|\d+ ?usd)|work from home|passive income|get rich', 22, 'Money-making scheme' ),
			array( 'lottery|you (have )?won|congratulations,? you|claim your (prize|reward)|inheritance|beneficiary', 40, 'Lottery / inheritance scam' ),
			array( 'verify your (account|password)|account (suspended|locked)|update your (billing|payment|card|bank)|delivery (failed|attempt failed)|payment details here|reschedule (your )?delivery|confirm your (identity|details)', 40, 'Phishing language' ),
			array( 'be your friend|be friends with you|my private (email|number)|lonely|dear friend|looking for (love|a relationship)|i saw your profile', 30, 'Romance / friendship scam' ),
			array( 'click (here|this link|below)|check (this|it) out|check out this', 12, '"Click here" link bait' ),
			array( 'invoice attached|payment (pending|overdue)|wire transfer', 15, 'Fake invoice / payment pressure' ),
			array( '\bhacked\b|ransom|bitcoin wallet|your webcam|access to your (device|computer|accounts)|recorded you|share the video', 30, 'Threat / blackmail message' ),
			array( 'worth \$?\d+(\.\d+)? ?(m\b|million|billion)|benefit (both of us|us both)|transfer (of )?(the )?funds|next of kin|diplomat|consignment box|unclaimed (funds|deposit)', 35, 'Advance-fee (419) scam' ),
		);
	}

	/**
	 * Signs of a real customer. Weight is SUBTRACTED from the risk score.
	 */
	private static function genuine_rules() {
		return array(
			array( '\b(i|we)(\'m|\'re| am| are)? (need|want|would like|wish|require|looking|planning|interested)( to (buy|order|book|purchase|know|enquire|inquire|visit|enrol|enroll|get|place|hire|rent|join|apply|become|see|take|start)| for| a| an| some| \d+| in (your|the|this|buying|booking|joining))\b', 12, 'Writes as a buyer (says what they need)' ),
			array( '\bneed (a|an|some|the)?\s?\w+( \w+)? for (my|our)\b|\bfor (my|our) (shop|office|home|house|flat|school|company|hotel|restaurant|clinic|wedding|event|son|daughter|kids?|family|store|factory|party|birthday)\b', 12, 'Writes as a buyer (says what they need)' ),
			array( 'how much|what (is|are) the (price|cost|fee|fees|charges|rate|rates)|price (of|for|kya)|cost (of|for)|rates? for|charges for|fees? for|\byour (price|prices|rates?|fees?|charges|packages|package (price|cost|details|charges)|menu|brochure|catalogue|catalog|price ?list)\b|\b(price|fees|rate)\?|wholesale price|best price', 12, 'Asks about your price' ),
			array( 'quotation|quote (for|on) (my|our|a|the)|estimate for|send (me )?(a )?quote', 10, 'Requests a quotation' ),
			array( '(please|pls|plz|kindly) (call|contact|reach|whatsapp|message) (me|back)|call me|call back|contact me|get back to me|please call', 12, 'Asks to be contacted' ),
			array( '\b(my|our) (order|booking|appointment|account|project|house|home|flat|office|shop|store|wedding|event|child|son|daughter|electricity|product|mixer|parcel)', 8, 'Talks about their own order / situation' ),
			array( 'appointment|book (a|an|the) (table|room|slot|session|visit|appointment)|reservation|site visit|visit your (showroom|shop|office|store)', 10, 'Booking / visit request' ),
			array( 'admission|enrol|enroll|course fee|classes|summer camp|course available', 8, 'Admission / course enquiry' ),
			array( 'availab(le|ility)|in stock|deliver(y)? (to|in|at)|do you (have|ship|deliver|provide|install|accept orders)|can you (deliver|make|do|send|install|share|provide)|opening hours|open on', 8, 'Asks about availability / delivery' ),
			array( 'complaint|refund|return (my|the|this|it)\b|not working|stopped working|has not arrived|not arrived|issue with|problem with|warranty|replace|service (request|centre|center)|kharab', 10, 'Support request' ),
			array( '\b(job|vacancy|vacancies|opening|resume|internship|fresher)\b|apply for', 10, 'Job / career enquiry' ),
			array( 'become a (dealer|distributor|franchise)|dealership|franchise', 10, 'Wants to sell your products (dealer)' ),
			array( '\b(mujhe|muje|humein|hume|chahiye|chaiye|kitna|kitne|kitni|kaise|kab tak|batao|bataye|bataiye|karna hai|lena hai|chahta|chahti|zarurat|mera|meri|hoga|hogi)\b', 12, 'Written in Hinglish (local customer)' ),
		);
	}

	/**
	 * Who is talking to whom? Sellers write "we offer… our services… I can fix
	 * your website… book a call"; buyers write about their own need.
	 *
	 * @return array{seller: int, target: int, cta: int, ask: int, opener: bool, critique: bool}
	 */
	public static function voice_analysis( $text ) {
		$t = strtolower( $text );

		$seller_patterns = array(
			'\bwe(\'re| are) (an? |the )?(leading|professional|top|reputed|certified|trusted|best|experienced|award[- ]winning|full[- ]service|digital|creative|marketing|seo|web|software|it|app|design|development|staffing|recruitment|team of|company that|agency|group of|manufactur\w*|supplier\w*|export\w*|import\w*)',
			'\bwe(\'re| are) (an? |the )?(\w+ ){0,3}(studio|agency|firm|printers|manufacturers|suppliers|exporters|developers|designers|consultants|consultancy|providers?|marketers|contractors|vendors|distributors|wholesalers|solutions? provider|service provider)\b',
			'\b(that|who) helps? (\w+ ){0,3}(businesses|companies|brands|clients|shops|stores|startups|professionals)\b|\bpleased to introduce\b|\bintroduce ourselves\b|\bopportunity to serve\b',
			'\bi (run|own|manage|host) (a|an|the) (\w+ )?(podcast|agency|studio|channel|blog|newsletter|magazine|marketplace|directory|platform)\b',
			'\b(we|i) (offer|provide|sell|supply|deliver) (\w+ ){0,4}(services?|solutions?|packages?|plans?|software|tools?)\b',
			'\bwe (offer|provide|specialize|specialise|deliver|build|design|develop|create|optimi[sz]e|manage|help|work with|partner with|supply|sell|export|organi[sz]e|arrange|conduct|install|manufacture|print|ship|list|have a (list|database|team|network))\b',
			'\bwe (can|will|could) (help|provide|promote|increase|boost|get|build|design|develop|fix|improve|bring|supply|deliver|offer|create|make|grow|rank|handle)\b',
			'\b(our|my) (\w+ ){0,3}(services?|agency|experts?|solutions?|software|platform|tools?|packages?|plans|hosting|clients|portfolio|pricing|rates|charges|network|proposal|offerings?|offers|price ?list|company profile|case studies|products range|number for|directory|listing|marketplace|widget)\b|\bsave our number\b',
			'\b(our|my) (\w+ )?(company|agency|firm|team|business|startup|software|app|platform|tool|experts?) (offers?|provides?|specializ\w*|specialis\w*|helps?|has helped|can|will|is a|is an|works with|builds?|designs?|develops?)\b',
			'\bi(\'m| am) (an? )?(\w+ ){0,2}(developer|designer|marketer|consultant|specialist|freelancer|writer|copywriter|editor|influencer|creator|strategist|photographer|videographer|seo expert|seo specialist)\b',
			'\bi (offer|provide|specialize|specialise|help (businesses|companies|brands|clients|startups)|make \w+( \w+)? for (businesses|companies|brands|clients)|write \w+( \w+)? (articles|blogs?|content|posts))\b',
			'\bi (can|could|will) (help|build|design|develop|fix|improve|increase|boost|redesign|create|write|make|get|bring|rank|optimi[sz]e)( (you|your|it|a|an|the|this))?\b',
			'\b(offer|introduce|present|propose|pitch) (you )?(my|our)\b|\boffer you (a|an)\b|\bto offer you\b',
			'\b(i|we) represent\b|\bfrom (\w+ ){0,2}(solutions|technologies|tech|digital|media|agency|infotech|labs|marketing|consulting|services|pvt|ltd|llc|inc|studios?|global|softwares?|systems)\b',
			'\breaching out\b|\bwriting to (introduce|offer|propose|share)\b|\bwanted to (introduce|share|offer)\b|\bintroduce (our|my|myself)\b',
			'\b(hum|ham|humari|hamari|hamara|humara|hamare|humare)\b[^.?!]{0,40}\b(agency|company|team|service|services|firm)\b',
			'\b(main|mai|mein)\b[^.?!]{0,25}\b(expert|developer|designer|freelancer|agency|consultant)\b',
			'\b(hum|ham)\b[^.?!]{0,40}\b(dete|banate|banati|karte|karti|provide|offer)\b|\b(banati|banate) hai\b|\bla (sakte|dunga|denge)\b|\bgrow kar (sakte|denge|dunga)\b',
		);
		$target_patterns = array(
			'\byour (website|site|web ?site|business|company|brand|online presence|online business|sales|revenue|profits?|customers|leads|inquiries|enquiries|traffic|ranking|rankings|seo|social media|instagram|facebook|youtube|channel|followers|google|marketing|industry|competitors|hiring|employees|staff|domain|account|ads|hosting|server)\b',
			'\b(aapki|aapke|aapka|apki|apke|apka)\b \w*\s?(website|site|business|company|brand|dukaan|sales|google)',
		);
		$cta_patterns = array(
			'\blet me know if\b|\blet me know\.?\s*$|\blet me know your thoughts\b',
			'\breply (yes|back|with|for|to this|now|today)\b|\bkindly reply\b|\bact now\b|\border now\b',
			'\b(book|schedule|set up|hop on) (a|an)( quick| short)? (call|demo|meeting|chat)\b|\b(quick|short) (call|chat)\b|\b\d+[- ]?min(ute)? (call|chat|demo)\b',
			'\b(can|shall|should|may) i (send|share|show|book|call|get)\b',
			'\bwould you (like|be open|be interested)\b|\bare you (open|interested)\b|\binterested\?|\binterested in (a|an) (sample|demo|quote|call|free)\b',
			'\b(can|shall) we (connect|talk|discuss|schedule|hop)\b|\blet\'?s (connect|talk|discuss|schedule|hop)\b',
			'\blooking forward to (working|business|hearing|collaborat)|\bwould love to (work|help|collaborate|connect)\b',
			'\bcontact us\b|\bvisit our\b|\bcheck (out )?our\b|\bwhatsapp us\b|\bcall (us|now)\b|\bget in touch with (me|us)\b|\blet us know\b|\bconvenient time\b',
			'\bhappy to (share|help|discuss|send|show|jump|walk)\b|\bif you(\'re| are) (open|interested)\b|\bwould you consider\b|\b(feature|interview) you\b|\bwant me to (send|share|show)\b',
			'\breply \w+ to (get started|know more|start|claim|book|learn)\b|\bto get started\b|\bfor (any |your )?future requirements?\b|\bany requirements?\b|\bgive us (a|an) (chance|opportunity)\b|\bsample work\b',
			'\bkya aap interested\b|\bbaat kar (lijiye|lo|le|sakte)\b|\bbaat kariye\b|\bcall kar(ein|o|iye)\b|\breply kar(ein|o|iye)\b|\bdemo ke liye\b',
		);
		$ask_patterns = array(
			'\b(do|are|is|would) you(r (business|company|website|team))? (need|want|looking|struggling|planning|interested in (getting|growing|increasing|more))\b',
			'\bwant (your|more) \w+',
			'\blooking to (grow|increase|boost|improve|scale|get more)\b',
			'\bwho (handles|is in charge of|manages|looks after|takes care of)\b',
			'\bstruggling (with|to)\b|\b(double|triple|10x|2x|3x) your\b',
			'\bkya (aap|aapko|apko|aapke|apke)\b[^.?!]{0,50}\b(chahiye|chahte|chahenge)\b',
			'(^|[.!?]\s+)(planning|looking|tired of|struggling|need more|want more|did you know)\b[^?.!]{0,70}\?',
		);
		$opener   = '\b(i|we) (was |were |am |have been |\'ve been |just )?(checking|visited|visiting|came across|come across|noticed|found|saw|reviewed|reviewing|analy[sz]ed|analy[sz]ing|looked at|looking at|went through|going through|browsing|stumbled upon|scanned|audited|tested) (your|that your)\b';
		$critique = '\byour (website|site|page|seo|ranking|store)\b[^.?!]{0,40}\b(is not|isn\'t|not ranking|needs|lacks|missing|slow|loads slowly|outdated|old|errors?|issues?|broken|could be better|is losing|losing|vulnerable|hacked|unsafe|not secure|has \d+)\b|\b(issues?|errors?|problems?|mistakes) (on|with|in) your (website|site)\b|\bcosting you\b|\bfound it (vulnerable|slow|broken)\b|\b(aapki|apki) website[^.?!]{0,30}\b(nahi|slow|kharab)\b';

		$count = static function ( array $patterns ) use ( $t ) {
			$n = 0;
			foreach ( $patterns as $p ) {
				if ( preg_match( '~' . $p . '~iu', $t ) ) {
					$n++;
				}
			}
			return $n;
		};

		return array(
			'seller'   => $count( $seller_patterns ),
			'target'   => $count( $target_patterns ),
			'cta'      => $count( $cta_patterns ),
			'ask'      => $count( $ask_patterns ),
			'opener'   => (bool) preg_match( '~' . $opener . '~iu', $t ),
			'critique' => (bool) preg_match( '~' . $critique . '~iu', $t ),
		);
	}

	private static function disposable_domains() {
		return array(
			'mailinator.com', 'guerrillamail.com', 'guerrillamailblock.com', 'grr.la', 'sharklasers.com', '10minutemail.com',
			'tempmail.com', 'temp-mail.org', 'temp-mail.io', 'tempmailo.com', 'tempail.com', 'tempr.email', 'tmpmail.org',
			'yopmail.com', 'throwawaymail.com', 'getnada.com', 'trashmail.com', 'dispostable.com', 'maildrop.cc',
			'fakeinbox.com', 'mintemail.com', 'mohmal.com', 'emailondeck.com', 'burnermail.io', 'spamgourmet.com',
			'mailnesia.com', 'moakt.com', 'discard.email', '33mail.com', 'mytemp.email', 'emailfake.com', 'spam4.me',
			'mailcatch.com', 'inboxkitten.com', '1secmail.com', '1secmail.net', 'minuteinbox.com', 'mail.tm',
			'linshiyouxiang.net', 'getairmail.com', 'fakemail.net', 'mailpoof.com', 'dropmail.me',
		);
	}

	/**
	 * Classifies one inquiry.
	 *
	 * @param array $input    name, email, phone, subject, message, extra_text.
	 * @param array $settings Plugin settings (see LeadSift_Settings::defaults()).
	 * @param array $context  duplicate_count, ip_recent_count, learned (category|null).
	 * @return array{category: string, score: int, reasons: array}
	 */
	public static function classify( array $input, array $settings = array(), array $context = array() ) {
		$name    = trim( (string) ( $input['name'] ?? '' ) );
		$email   = strtolower( trim( (string) ( $input['email'] ?? '' ) ) );
		$phone   = trim( (string) ( $input['phone'] ?? '' ) );
		$subject = trim( (string) ( $input['subject'] ?? '' ) );
		$message = trim( (string) ( $input['message'] ?? '' ) );
		$extra   = trim( (string) ( $input['extra_text'] ?? '' ) );
		$domain  = false !== strpos( $email, '@' ) ? substr( strrchr( $email, '@' ), 1 ) : '';

		$sensitivity = $settings['sensitivity'] ?? 'balanced';
		$threshold   = self::THRESHOLDS[ $sensitivity ] ?? self::THRESHOLDS['balanced'];

		// 1. Hard rules: your own allow / block lists and learned corrections.
		$trusted = self::split_list( $settings['trusted_senders'] ?? '' );
		$blocked = self::split_list( $settings['blocked_senders'] ?? '' );

		if ( '' !== $email && ( in_array( $email, $trusted, true ) || ( $domain && in_array( $domain, $trusted, true ) ) ) ) {
			return self::result( 'genuine', 0, array( self::reason( 'Sender is on your trusted list', -100, 'rule' ) ), true );
		}
		if ( '' !== $email && ( in_array( $email, $blocked, true ) || ( $domain && in_array( $domain, $blocked, true ) ) ) ) {
			return self::result( 'promotional', 100, array( self::reason( 'Sender is on your blocked list', 100, 'rule' ) ), true );
		}

		// 2. Your own rules. A "move to …" rule decides on the spot; a "points" rule adds to the score below.
		$fields = array(
			'name'         => $name,
			'email'        => $email,
			'email_domain' => $domain,
			'phone'        => $phone,
			'subject'      => $subject,
			'message'      => $message,
			'form'         => (string) ( $input['form_name'] ?? '' ),
			'source'       => (string) ( $input['source'] ?? '' ),
			'form_type'    => (string) ( $input['form_type'] ?? '' ),
		);
		$fields['any'] = implode( "\n", array( $name, $email, $phone, $subject, $message, $extra, $fields['form'] ) );

		$rule_points = array( 'promotional' => array(), 'spam' => array(), 'genuine' => array() );
		foreach ( (array) ( $settings['custom_rules'] ?? array() ) as $rule ) {
			if ( empty( $rule['enabled'] ) || ! empty( $rule['invalid'] ) || ! self::rule_matches( $rule, $fields ) ) {
				continue;
			}
			$label = 'Your rule: ' . ( '' !== ( $rule['name'] ?? '' ) ? $rule['name'] : self::describe_rule( $rule ) );
			if ( 0 === strpos( $rule['action'], 'set_' ) ) {
				$cat = substr( $rule['action'], 4 );
				return self::result( $cat, 'genuine' === $cat ? 0 : 100, array( self::reason( $label, 'genuine' === $cat ? -100 : 100, 'rule' ) ), true );
			}
			$type   = substr( $rule['action'], 6 ); // score_promotional → promotional.
			$points = max( 0, min( 100, (int) ( $rule['points'] ?? 20 ) ) );
			$rule_points[ $type ][ 'rule:' . ( $rule['id'] ?? $label ) ] = self::reason( $label, $points, $type );
		}

		// 3. Corrections you made earlier for this sender.
		if ( ! empty( $context['learned'] ) && in_array( $context['learned'], self::CATEGORIES, true ) ) {
			$cat = $context['learned'];
			return self::result( $cat, 'genuine' === $cat ? 0 : 100, array( self::reason( 'You marked this sender as ' . ucfirst( $cat ) . ' before', 'genuine' === $cat ? -100 : 100, 'rule' ) ), true );
		}

		$text    = trim( $subject . "\n" . $message . "\n" . $extra );
		$promo   = $rule_points['promotional'];
		$spam    = $rule_points['spam'];
		$genuine = $rule_points['genuine'];

		$form_type    = (string) ( $input['form_type'] ?? '' );
		$msg_optional = in_array( $form_type, array( 'newsletter', 'job', 'booking' ), true );
		$country      = strtoupper( (string) ( $settings['phone_country'] ?? '' ) );

		// What the visitor actually wrote. Dropdown choices and other fields are not
		// used for pitch / customer detection – a form label like "What can we help with?"
		// or an agency form's "SEO" option must never count as a sales pitch.
		$said = trim( $subject . "\n" . $message );

		// 4. Built-in keyword rules (each label counts once).
		self::apply_rules( self::promo_rules(), $said, $promo, 'promotional' );
		self::apply_rules( self::spam_rules(), $text, $spam, 'spam' );
		self::apply_rules( self::genuine_rules(), $said, $genuine, 'genuine' );
		$scam = false; // Phishing, blackmail, 419, romance… – nothing a customer would write.
		foreach ( $spam as $hit ) {
			$scam = $scam || $hit['weight'] >= 30;
		}
		$promo_keyword_hits = count( $promo ) - count( $rule_points['promotional'] );

		foreach ( self::split_list( $settings['promo_keywords'] ?? '' ) as $kw ) {
			if ( self::contains_phrase( $text, $kw ) ) {
				$promo[ 'Your keyword: ' . $kw ] = self::reason( 'Matches your promotional keyword "' . $kw . '"', 15, 'promotional' );
			}
		}
		foreach ( self::split_list( $settings['genuine_keywords'] ?? '' ) as $kw ) {
			if ( self::contains_phrase( $text, $kw ) ) {
				$genuine[ 'Your keyword: ' . $kw ] = self::reason( 'Matches your genuine keyword "' . $kw . '"', 12, 'genuine' );
			}
		}

		// 5. Who is talking to whom? (seller pitching you vs. customer asking you).
		$voice = self::voice_analysis( $said );
		if ( $voice['seller'] > 0 ) {
			$promo['v_seller'] = self::reason( 'Writes as a seller ("we offer…", "our services", "I can build…")', min( 25, 15 + 5 * ( $voice['seller'] - 1 ) ), 'promotional' );
		}
		if ( $voice['target'] > 0 ) {
			$promo['v_target'] = self::reason( 'Talks about YOUR website / business / sales', 8, 'promotional' );
		}
		if ( $voice['cta'] > 0 ) {
			$promo['v_cta'] = self::reason( 'Sales call-to-action ("let me know if…", "book a call", "can I send…")', 10, 'promotional' );
		}
		if ( $voice['ask'] > 0 ) {
			$promo['v_ask'] = self::reason( 'Asks whether YOU need something (sales question)', 15, 'promotional' );
		}
		if ( $voice['opener'] ) {
			$promo['v_opener'] = self::reason( 'Cold-outreach opener ("I came across / checked your…")', 6, 'promotional' );
		}
		if ( $voice['critique'] ) {
			$promo['v_critique'] = self::reason( 'Points out problems with your website (classic cold pitch)', 12, 'promotional' );
		}
		$pitch = ( $voice['seller'] > 0 && ( $voice['target'] || $voice['cta'] || $voice['ask'] || $voice['opener'] || $voice['critique'] || $promo_keyword_hits >= 1 ) )
			|| ( $voice['ask'] > 0 && ( $voice['target'] || $voice['cta'] || $promo_keyword_hits >= 1 ) )
			|| ( $voice['critique'] && ( $voice['cta'] || $voice['seller'] || $voice['opener'] ) )
			|| ( $promo_keyword_hits >= 2 && ( $voice['cta'] || $voice['target'] ) )
			|| $voice['seller'] >= 2
			|| $promo_keyword_hits >= 3;

		// A buyer who describes their own business ("we run a hotel and need 40 bedsheets")
		// is not a pitch as long as there is no sales hook aimed at you.
		$buyer_hits = count( $genuine ) - count( $rule_points['genuine'] );
		if ( $pitch && $buyer_hits >= 2 && ! $voice['target'] && ! $voice['cta'] && ! $voice['ask'] && ! $voice['critique'] && $promo_keyword_hits < 2 ) {
			$pitch = false;
		}
		if ( $pitch ) {
			$promo['v_pitch'] = self::reason( 'Reads like a sales pitch: offers their product / service to you', 20, 'promotional' );
		}

		// 6. Links.
		$links = preg_match_all( '~(https?://|www\.)\S+~i', $text );
		if ( preg_match( '~\[url=|<a\s+href~i', $text ) ) {
			$spam['bbcode'] = self::reason( 'Contains HTML / BBCode links (bot pattern)', 30, 'spam' );
		}
		if ( $links >= 3 ) {
			$spam['links'] = self::reason( "Contains {$links} links", 30, 'spam' );
		} elseif ( 2 === $links ) {
			$promo['links'] = self::reason( 'Contains 2 links', 15, 'promotional' );
		} elseif ( 1 === $links ) {
			$promo['links'] = self::reason( 'Contains a link', 7, 'promotional' );
		}

		// 7. Quality checks – catches bots and people who just click / type anything.
		$quality = self::quality_checks( $name, $email, $domain, $phone, $subject, $message, $msg_optional, $country );
		foreach ( $quality['issues'] as $key => $issue ) {
			$spam[ $key ] = $issue;
		}
		if ( $quality['phone_ok'] ) {
			$genuine['phone'] = self::reason( 'Gave a valid phone number', 8, 'genuine' );
		}

		// 8. Foreign scripts (Cyrillic / Chinese / etc.) – common in bot spam.
		if ( ! empty( $settings['flag_foreign_scripts'] ) ) {
			$letters = preg_match_all( '/\p{L}/u', $text . $name );
			$foreign = preg_match_all( '/[\x{0400}-\x{04FF}\x{4E00}-\x{9FFF}\x{0E00}-\x{0E7F}\x{0600}-\x{06FF}\x{AC00}-\x{D7AF}]/u', $text . $name );
			if ( $foreign > 0 ) {
				$most = $letters > 0 && $foreign / $letters > 0.5;
				$spam['script'] = self::reason( $most ? 'Written in Cyrillic / Chinese / other foreign script' : 'Contains Cyrillic / Chinese script', $most ? 45 : 20, 'spam' );
			}
		}

		// 9. Behaviour signals from the database and the form itself.
		$dupes = (int) ( $context['duplicate_count'] ?? 0 );
		if ( $dupes > 0 ) {
			$spam['dupes'] = self::reason( 'Same message was already sent from ' . $dupes . ' other email' . ( $dupes > 1 ? 's' : '' ) . ' (copy-paste campaign)', min( 40, 20 + 6 * $dupes ), 'spam' );
		}
		$ip_count = (int) ( $context['ip_recent_count'] ?? 0 );
		if ( $ip_count >= 3 ) {
			$spam['flood'] = self::reason( $ip_count . ' submissions from the same IP in the last hour', 20, 'spam' );
		}
		$form_hard = false;
		foreach ( (array) ( $context['form_issues'] ?? array() ) as $i => $issue ) {
			if ( isset( $issue['label'], $issue['weight'] ) ) {
				$spam[ 'form' . $i ] = self::reason( $issue['label'], (int) $issue['weight'], 'spam' );
				$form_hard           = $form_hard || (int) $issue['weight'] >= 25;
			}
		}

		// 10. Genuine helpers.
		if ( preg_match( '/[\x{0900}-\x{097F}]/u', $text ) ) {
			$genuine['hindi'] = self::reason( 'Written in Hindi (local customer)', 10, 'genuine' );
		}
		if ( 0 === $links && ! $pitch && $quality['words'] >= 3 && mb_strlen( $message ) <= 700 && false !== strpos( $message, '?' ) ) {
			$genuine['question'] = self::reason( 'Short, direct question without links', 4, 'genuine' );
		}

		// 11. Score.
		$promo_pts = self::sum( $promo );
		$spam_pts  = self::sum( $spam );

		// Genuine-looking words can't cancel a clear pitch, a fake/bot submission or a bypassed form.
		$cap = 35;
		if ( $pitch || $quality['junk'] || $form_hard || $scam ) {
			$cap = 6;
		}
		$own_genuine = self::sum( $rule_points['genuine'] );
		// Your "points toward Genuine" rules are capped too when the message is clearly a
		// pitch / scam / junk (a pitch aimed at a business always uses that business's own words).
		// "Always Genuine" rules are not affected – they decide before this point.
		$own_counted = $cap < 35 ? min( 10, $own_genuine ) : $own_genuine;
		$genuine_pts = min( $cap, self::sum( $genuine ) - $own_genuine ) + $own_counted;

		$score = (int) max( 0, min( 100, $promo_pts + $spam_pts - $genuine_pts ) );

		if ( $score >= $threshold ) {
			$category = $spam_pts > $promo_pts ? 'spam' : 'promotional';
		} else {
			$category = 'genuine';
		}

		$reasons = array_merge( array_values( $spam ), array_values( $promo ) );
		foreach ( $genuine as $g ) {
			$g['weight'] = -$g['weight'];
			$reasons[]   = $g;
		}
		if ( $cap < 35 && self::sum( $genuine ) - $own_genuine > $cap ) {
			$reasons[] = self::reason( 'Customer-like words ignored: the message is clearly a pitch or junk', 0, 'info' );
		}
		usort(
			$reasons,
			static function ( $a, $b ) {
				return abs( $b['weight'] ) <=> abs( $a['weight'] );
			}
		);
		if ( empty( $reasons ) ) {
			$reasons[] = self::reason( 'No promotional or spam signals found', 0, 'genuine' );
		}

		return self::result( $category, $score, $reasons );
	}

	/**
	 * Strict checks on each field: fake phones, keyboard mashing, "test", empty or
	 * one-word messages, test emails, junk names.
	 *
	 * @return array{issues: array, phone_ok: bool, junk: bool, words: int}
	 */
	public static function quality_checks( $name, $email, $domain, $phone, $subject, $message, $msg_optional, $country = '' ) {
		$issues = array();
		$junk   = false;

		// --- Message ---
		$norm  = trim( preg_replace( '/\s+/u', ' ', preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', self::lower( $message ) ) ) );
		$words = '' === $norm ? array() : explode( ' ', $norm );
		$wcount = 0;
		foreach ( $words as $w ) {
			if ( preg_match( '/\p{L}/u', $w ) ) {
				$wcount++;
			}
		}

		$placeholder = '/^(h+i+|h+e+y+|hel+o+|hlo|ok+|okay|test(ing)?( \d+)?|test message|this is a test|testing testing|just testing|nice|good|great|thanks?( you)?|ty|abc|xyz|asdf|qwerty|demo|sample|check(ing)?|yes|no|hm+|yo|sup|hello world|hello there|hi there|ok thanks|nice site|nice website|good site|a|aa+|x+)$/u';

		if ( '' === trim( $message ) ) {
			if ( ! $msg_optional && '' === trim( $subject ) ) {
				$issues['msg_empty'] = self::reason( 'No message written', 30, 'spam' );
			}
		} elseif ( '' === $norm ) {
			$issues['msg_symbols'] = self::reason( 'Message is only symbols / dots', 45, 'spam' );
			$junk                  = true;
		} elseif ( 0 === $wcount ) {
			$issues['msg_digits'] = self::reason( 'Message is only numbers', 45, 'spam' );
			$junk                 = true;
		} elseif ( preg_match( $placeholder, $norm ) || preg_match( $placeholder, implode( ' ', array_unique( $words ) ) ) ) {
			$issues['msg_placeholder'] = self::reason( 'Message is only "' . mb_substr( $norm, 0, 30 ) . '" – not a real inquiry', 50, 'spam' );
			$junk                      = true;
		} elseif ( self::is_mash( $norm ) ) {
			$issues['msg_mash'] = self::reason( 'Message is keyboard mashing / random letters', 50, 'spam' );
			$junk               = true;
		} elseif ( $wcount <= 2 && self::all_vowelless( $words ) ) {
			$issues['msg_mash'] = self::reason( 'Message is random letters ("' . mb_substr( $norm, 0, 20 ) . '")', 50, 'spam' );
			$junk               = true;
		} elseif ( count( $words ) >= 3 && count( array_unique( $words ) ) / count( $words ) <= 0.34 ) {
			$issues['msg_repeat'] = self::reason( 'Same word repeated again and again', 45, 'spam' );
			$junk                 = true;
		} elseif ( self::looks_gibberish( $message, false ) ) {
			$issues['msg_gibberish'] = self::reason( 'Message looks like random characters', 30, 'spam' );
			$junk                    = true;
		} elseif ( $wcount < 3 && ! $msg_optional ) {
			$short = self::reason( 'Message is too short to be a real inquiry (' . $wcount . ' word' . ( 1 === $wcount ? '' : 's' ) . ')', 20, 'spam' );
		}

		$letters = preg_replace( '/[^a-z]/i', '', $message );
		if ( strlen( $letters ) > 25 && strlen( preg_replace( '/[^A-Z]/', '', $letters ) ) / strlen( $letters ) > 0.6 ) {
			$issues['caps'] = self::reason( 'Mostly CAPITAL LETTERS', 10, 'spam' );
		}
		if ( preg_match( '/(.)\1{5,}/u', $message ) ) {
			$issues['msg_chars'] = self::reason( 'Same character typed many times in a row', 10, 'spam' );
		}

		// --- Phone ---
		$phone_ok = false;
		if ( '' !== trim( $phone ) ) {
			$problem = self::phone_problem( $phone, $country );
			if ( $problem ) {
				$issues['phone'] = self::reason( $problem[0], $problem[1], 'spam' );
				$junk            = $junk || $problem[1] >= 25;
			} else {
				$phone_ok = true;
			}
		}
		if ( isset( $short ) && ! $phone_ok ) {
			$issues['msg_short'] = $short;
		}

		// --- Email ---
		if ( '' !== $email ) {
			$local = strstr( $email, '@', true );
			if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
				$issues['email'] = self::reason( 'Email address is not valid', 20, 'spam' );
			} elseif ( in_array( $domain, self::disposable_domains(), true ) ) {
				$issues['email'] = self::reason( 'Uses a temporary / disposable email', 25, 'spam' );
				$junk            = true;
			} elseif ( preg_match( '/^(test|example|domain|abc|xyz|asdf|qwerty|a|aa|aaa|email|fake|sample)\.(com|net|org|in|test)$|\.(test|invalid|example)$/', $domain )
				|| preg_match( '/^(test\d*|testing|asdf\w*|qwerty|abc|abcd|xyz|demo|sample|fake|spam|user|a|aa+|x+|no-?reply)$/', (string) $local )
				|| self::is_mash( (string) $local ) ) {
				$issues['email'] = self::reason( 'Looks like a test / fake email address', 25, 'spam' );
				$junk            = true;
			}
		}

		// --- Name ---
		if ( '' !== $name ) {
			$n = self::lower( trim( $name ) );
			if ( preg_match( '~(https?://|www\.|\.com\b|\.net\b)~i', $name ) ) {
				$issues['name'] = self::reason( 'Name field contains a website', 30, 'spam' );
			} elseif ( preg_match( '/^(test\w*|asdf\w*|qwerty|abc|xyz|demo|fake|user|admin|none|null|na|n\/a|no name|name|x+|a+)$/u', $n ) ) {
				$issues['name'] = self::reason( 'Name is a placeholder ("' . mb_substr( $name, 0, 20 ) . '")', 25, 'spam' );
				$junk           = true;
			} elseif ( mb_strlen( $n ) < 2 ) {
				$issues['name'] = self::reason( 'Name is a single letter', 20, 'spam' );
			} elseif ( self::is_mash( $n ) || self::looks_gibberish( $name, true ) || ( mb_strlen( $n ) >= 4 && ! preg_match( '/[aeiouy\x{0900}-\x{097F}]/u', $n ) && preg_match( '/^[a-z ]+$/', $n ) ) ) {
				$issues['name'] = self::reason( 'Name looks random / bot-generated', 25, 'spam' );
				$junk           = true;
			} elseif ( preg_match_all( '/\d/', $n ) >= 2 ) {
				$issues['name'] = self::reason( 'Name contains numbers', 12, 'spam' );
			}
		}

		return array(
			'issues'   => $issues,
			'phone_ok' => $phone_ok,
			'junk'     => $junk,
			'words'    => $wcount,
		);
	}

	/**
	 * @return array{0: string, 1: int}|null Problem description and weight, or null if the number looks real.
	 */
	public static function phone_problem( $phone, $country = '' ) {
		$digits = preg_replace( '/\D/', '', (string) $phone );
		$len    = strlen( $digits );
		if ( 0 === $len ) {
			return array( 'Phone field has no number in it', 25 );
		}
		if ( $len < 7 ) {
			return array( 'Phone number is too short (' . $len . ' digits)', 25 );
		}
		if ( $len > 15 ) {
			return array( 'Phone number is too long', 20 );
		}
		if ( preg_match( '/^(\d)\1+$/', $digits ) ) {
			return array( 'Phone number is one digit repeated (' . $digits . ')', 30 );
		}
		$tail = substr( $digits, -min( 10, $len ) );
		if ( false !== strpos( '01234567890123456789', $tail ) || false !== strpos( '98765432109876543210', $tail ) ) {
			return array( 'Phone number is a 1-2-3-4 style sequence (' . $digits . ')', 30 );
		}
		if ( preg_match( '/(\d)\1{6,}/', $digits ) ) {
			return array( 'Phone number has the same digit 7+ times in a row (' . $digits . ')', 25 );
		}
		if ( preg_match( '/^(\d{1,3})\1{2,}\d{0,2}$/', $tail ) ) {
			return array( 'Phone number is a repeated pattern (' . $digits . ')', 25 );
		}
		// India: numbers have exactly 10 digits (after +91 / 0). Foreign numbers written
		// with "+" or "00" and another country code are left alone.
		if ( 'IN' === $country && ! preg_match( '/^\s*(\+|00)\s*(?!91)\d/', (string) $phone ) ) {
			$local = $digits;
			if ( 12 === $len && 0 === strpos( $digits, '91' ) ) {
				$local = substr( $digits, 2 );
			} elseif ( 13 === $len && 0 === strpos( $digits, '091' ) ) {
				$local = substr( $digits, 3 );
			} elseif ( 11 === $len && '0' === $digits[0] ) {
				$local = substr( $digits, 1 );
				if ( ! preg_match( '/^[6-9]/', $local ) ) {
					return null; // Landline with STD code, e.g. 0141 2345678.
				}
			}
			if ( 10 !== strlen( $local ) ) {
				return array( 'Not a valid Indian phone number (' . strlen( $local ) . ' digits – Indian numbers have 10)', 25 );
			}
			if ( '0' !== $digits[0] && ! preg_match( '/^[6-9]/', $local ) ) {
				return array( 'Not a valid Indian mobile number (must start with 6, 7, 8 or 9)', 12 );
			}
		}
		return null;
	}

	/** True when every Latin word has no vowel ("sdfsd", "fd", "kjh") – common abbreviations excepted. */
	public static function all_vowelless( array $words ) {
		$ok    = array( 'pls', 'plz', 'thx', 'thnx', 'msg', 'sms', 'cctv', 'gst', 'bhk', 'hr', 'dm', 'ph', 'mr', 'mrs', 'dr', 'st', 'rd', 'kg', 'km', 'ltr', 'pcs', 'qty', 'nd', 'th', 'pvt', 'ltd', 'pdf', 'hrs', 'wtsp', 'whtsp', 'cnfrm', 'sry', 'bht', 'kr', 'krn', 'krna', 'mjhe', 'mjh' );
		$found = 0;
		foreach ( $words as $w ) {
			if ( ! preg_match( '/^[a-z]+$/', $w ) ) {
				if ( preg_match( '/\p{L}/u', $w ) ) {
					return false; // Hindi or mixed words: not judged here.
				}
				continue;
			}
			if ( preg_match( '/[aeiouy]/', $w ) || in_array( $w, $ok, true ) || strlen( $w ) < 2 ) {
				return false;
			}
			$found++;
		}
		return $found > 0;
	}

	/** Keyboard mashing like "asdfgh", "qwerty", "jkljkl", "dfgdfg". */
	public static function is_mash( $text ) {
		$t = preg_replace( '/[^a-z]/', '', strtolower( (string) $text ) );
		if ( strlen( $t ) < 4 ) {
			return false;
		}
		$runs = array( 'qwert', 'asdfg', 'sdfgh', 'dfghj', 'fghjk', 'ghjkl', 'zxcvb', 'xcvbn', 'cvbnm', 'yuiop', 'tyuio', 'asdf', 'qwer', 'zxcv', 'hjkl', 'lkjh', 'fdsa', 'poiu', 'mnbv' );
		foreach ( preg_split( '/[^a-z]+/', strtolower( (string) $text ) ) as $word ) {
			// Keyboard rows inside one word: "asdfgh", "qwerty", "zxcvbn".
			foreach ( $runs as $run ) {
				if ( false !== strpos( $word, $run ) ) {
					return true;
				}
			}
			// Short chunk repeated 3+ times: "jdjdjd", "aaaa", "abcabcabc".
			if ( strlen( $word ) >= 4 && preg_match( '/^([a-z]{1,3})\1{2,}[a-z]?$/', $word ) && ! in_array( $word, array( 'hahaha', 'hehehe', 'hihihi', 'lololol' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ */

	private static function apply_rules( array $rules, $text, array &$bucket, $type ) {
		foreach ( $rules as $rule ) {
			list( $pattern, $weight, $label ) = $rule;
			if ( isset( $bucket[ $label ] ) ) {
				continue;
			}
			if ( preg_match( '~' . $pattern . '~iu', $text ) ) {
				$bucket[ $label ] = self::reason( $label, $weight, $type );
			}
		}
	}

	private static function contains_phrase( $text, $phrase ) {
		return (bool) preg_match( '~(?<![\p{L}\p{N}])' . preg_quote( $phrase, '~' ) . '(?![\p{L}\p{N}])~iu', $text );
	}

	/**
	 * @param string $text    Text to test.
	 * @param bool   $is_name Name fields are short, so one random word is enough.
	 */
	private static function looks_gibberish( $text, $is_name ) {
		if ( preg_match( '/[^\x00-\x7F]/', $text ) ) {
			return false; // Non-Latin text (e.g. Hindi) is handled by other rules.
		}
		$words = preg_split( '/\s+/', trim( $text ) );
		$words = array_values( array_filter( $words, 'strlen' ) );
		if ( empty( $words ) ) {
			return false;
		}
		if ( ! $is_name && 1 === count( $words ) && strlen( $words[0] ) > 25 && false === strpos( $words[0], '://' ) ) {
			return true;
		}
		$bad = 0;
		foreach ( $words as $word ) {
			if ( self::is_random_word( $word ) ) {
				$bad++;
			}
		}
		if ( $is_name ) {
			return $bad > 0;
		}
		return count( $words ) >= 3 ? ( $bad / count( $words ) ) > 0.3 : $bad === count( $words );
	}

	private static function is_random_word( $word ) {
		$clean = preg_replace( '/[^a-z]/i', '', $word );
		if ( strlen( $clean ) < 6 || preg_match( '~^(https?|www)~i', $word ) ) {
			return false;
		}
		$lower = strtolower( $clean );
		if ( ! preg_match( '/[aeiouy]/', $lower ) ) {
			return true;
		}
		if ( preg_match( '/[bcdfghjklmnpqrstvwxz]{6,}/', $lower ) ) {
			return true;
		}
		$switches = 0;
		$len      = strlen( $clean );
		for ( $i = 1; $i < $len; $i++ ) {
			if ( ctype_upper( $clean[ $i ] ) !== ctype_upper( $clean[ $i - 1 ] ) ) {
				$switches++;
			}
		}
		return $switches >= 4;
	}

	private static function split_list( $text ) {
		$parts = preg_split( '/[\r\n,]+/', strtolower( (string) $text ) );
		return array_values( array_filter( array_map( 'trim', $parts ), 'strlen' ) );
	}

	private static function sum( array $bucket ) {
		$total = 0;
		foreach ( $bucket as $r ) {
			$total += $r['weight'];
		}
		return $total;
	}

	private static function reason( $label, $weight, $type ) {
		return array(
			'label'  => $label,
			'weight' => (int) $weight,
			'type'   => $type,
		);
	}

	/**
	 * @param bool $locked True when one of YOUR rules/lists decided – the AI check will not override it.
	 */
	private static function result( $category, $score, array $reasons, $locked = false ) {
		return array(
			'category' => $category,
			'score'    => (int) $score,
			'reasons'  => $reasons,
			'locked'   => (bool) $locked,
		);
	}

	/**
	 * Does one custom rule match?
	 *
	 * For contains / equals / starts with / ends with you can list several values
	 * separated by commas – any one of them matching is enough.
	 */
	public static function rule_matches( array $rule, array $fields ) {
		$haystack = self::lower( (string) ( $fields[ $rule['field'] ?? 'any' ] ?? '' ) );
		$value    = (string) ( $rule['value'] ?? '' );
		$operator = $rule['operator'] ?? 'contains';

		if ( 'is_empty' === $operator ) {
			return '' === trim( $haystack );
		}
		if ( 'regex' === $operator ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors
			return 1 === @preg_match( '~' . str_replace( '~', '\~', $value ) . '~iu', (string) ( $fields[ $rule['field'] ?? 'any' ] ?? '' ) );
		}

		$needles = array_values( array_filter( array_map( 'trim', explode( ',', self::lower( $value ) ) ), 'strlen' ) );
		if ( empty( $needles ) ) {
			return false;
		}
		$haystack = trim( $haystack );
		foreach ( $needles as $needle ) {
			switch ( $operator ) {
				case 'contains':
				case 'not_contains':
					$hit = false !== strpos( $haystack, $needle );
					break;
				case 'equals':
					$hit = $haystack === $needle;
					break;
				case 'starts_with':
					$hit = 0 === strpos( $haystack, $needle );
					break;
				case 'ends_with':
					$hit = strlen( $haystack ) >= strlen( $needle ) && substr( $haystack, -strlen( $needle ) ) === $needle;
					break;
				default:
					$hit = false;
			}
			if ( $hit ) {
				return 'not_contains' !== $operator;
			}
		}
		return 'not_contains' === $operator;
	}

	public static function describe_rule( array $rule ) {
		$fields = array(
			'any'          => 'Any field',
			'name'         => 'Name',
			'email'        => 'Email',
			'email_domain' => 'Email domain',
			'phone'        => 'Phone',
			'subject'      => 'Subject',
			'message'      => 'Message',
			'form'         => 'Form name',
			'form_type'    => 'Form type',
			'source'       => 'Form plugin',
		);
		$ops = array(
			'contains'     => 'contains',
			'not_contains' => 'does not contain',
			'equals'       => 'is',
			'starts_with'  => 'starts with',
			'ends_with'    => 'ends with',
			'regex'        => 'matches pattern',
			'is_empty'     => 'is empty',
		);
		$text = ( $fields[ $rule['field'] ] ?? $rule['field'] ) . ' ' . ( $ops[ $rule['operator'] ] ?? $rule['operator'] );
		return 'is_empty' === $rule['operator'] ? $text : $text . ' "' . $rule['value'] . '"';
	}

	private static function lower( $text ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}
}
