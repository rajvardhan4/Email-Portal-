<?php
/**
 * Captures submissions from popular form plugins (and custom forms),
 * classifies them and saves them.
 *
 * @package LeadSift
 */

defined( 'ABSPATH' ) || exit;

class LeadSift_Capture {

	/** True while handling a promotional/spam submission and "suppress emails" is on. */
	private static $suppress_mail = false;

	/** Prevents double capture when two hooks of the same plugin fire. */
	private static $done = array();

	/** Field keys that are never real user data. */
	const SKIP_KEYS = '/^(_|g-recaptcha|h-captcha|cf-turnstile|frm_|wpforms\[|nonce|action$|form_id$|post_id$|referer|honeypot|ak_|akismet)/i';

	public static function init() {
		$on = LeadSift_Settings::get()['sources'];

		if ( ! empty( $on['cf7'] ) ) {
			add_action( 'wpcf7_before_send_mail', array( __CLASS__, 'from_cf7' ), 5, 1 );
		}
		if ( ! empty( $on['wpforms'] ) ) {
			add_action( 'wpforms_process_entry_save', array( __CLASS__, 'from_wpforms' ), 5, 4 );
			add_action( 'wpforms_process_complete', array( __CLASS__, 'from_wpforms_complete' ), 5, 4 );
		}
		if ( ! empty( $on['gravityforms'] ) ) {
			add_action( 'gform_entry_created', array( __CLASS__, 'from_gravity' ), 5, 2 );
		}
		if ( ! empty( $on['elementor'] ) ) {
			add_action( 'elementor_pro/forms/process', array( __CLASS__, 'from_elementor' ), 5, 2 );
		}
		if ( ! empty( $on['fluentforms'] ) ) {
			add_action( 'fluentform/submission_inserted', array( __CLASS__, 'from_fluent' ), 1, 3 );
			add_action( 'fluentform_submission_inserted', array( __CLASS__, 'from_fluent' ), 1, 3 );
		}
		if ( ! empty( $on['ninjaforms'] ) ) {
			add_action( 'ninja_forms_after_submission', array( __CLASS__, 'from_ninja' ), 5, 1 );
		}
		if ( ! empty( $on['formidable'] ) ) {
			add_action( 'frm_after_create_entry', array( __CLASS__, 'from_formidable' ), 5, 2 );
		}

		// Developers: do_action( 'leadsift_capture', $fields, 'my-source', array( 'form_name' => 'Quote form' ) );
		add_action( 'leadsift_capture', array( __CLASS__, 'capture' ), 10, 3 );

		add_filter( 'pre_wp_mail', array( __CLASS__, 'maybe_suppress_mail' ), 10, 2 );
	}

	/* ------------------------- Form plugin adapters ------------------------- */

	public static function from_cf7( $contact_form ) {
		if ( ! class_exists( 'WPCF7_Submission' ) ) {
			return;
		}
		$submission = WPCF7_Submission::get_instance();
		if ( ! $submission ) {
			return;
		}
		self::capture(
			(array) $submission->get_posted_data(),
			'cf7',
			array(
				'form_id'   => method_exists( $contact_form, 'id' ) ? $contact_form->id() : '',
				'form_name' => method_exists( $contact_form, 'title' ) ? $contact_form->title() : 'Contact Form 7',
				'page_url'  => (string) $submission->get_meta( 'url' ),
			)
		);
	}

	public static function from_wpforms( $fields, $entry, $form_id, $form_data = array() ) {
		$data  = array();
		$hints = array();
		foreach ( (array) $fields as $field ) {
			$label          = ! empty( $field['name'] ) ? $field['name'] : 'Field ' . ( $field['id'] ?? '' );
			$value          = $field['value'] ?? '';
			$data[ $label ] = $value;
			self::hint_from_type( $field['type'] ?? '', $value, $hints );
		}
		self::capture(
			$data,
			'wpforms',
			array(
				'form_id'   => $form_id,
				'form_name' => $form_data['settings']['form_title'] ?? 'WPForms',
				'hints'     => $hints,
				'dedupe'    => 'wpforms-' . $form_id,
			)
		);
	}

	public static function from_wpforms_complete( $fields, $entry, $form_data, $entry_id ) {
		self::from_wpforms( $fields, $entry, $form_data['id'] ?? '', $form_data );
	}

	public static function from_gravity( $entry, $form ) {
		$data  = array();
		$hints = array();
		foreach ( (array) ( $form['fields'] ?? array() ) as $field ) {
			$type = is_object( $field ) ? $field->type : ( $field['type'] ?? '' );
			if ( in_array( $type, array( 'html', 'section', 'page', 'captcha', 'honeypot' ), true ) ) {
				continue;
			}
			$label = is_object( $field ) ? $field->label : ( $field['label'] ?? '' );
			$id    = is_object( $field ) ? $field->id : ( $field['id'] ?? '' );
			if ( is_object( $field ) && method_exists( $field, 'get_value_export' ) ) {
				$value = $field->get_value_export( $entry );
			} else {
				$value = $entry[ (string) $id ] ?? '';
			}
			$data[ $label ? $label : 'Field ' . $id ] = $value;
			self::hint_from_type( $type, $value, $hints );
		}
		self::capture(
			$data,
			'gravityforms',
			array(
				'form_id'   => $form['id'] ?? '',
				'form_name' => $form['title'] ?? 'Gravity Forms',
				'page_url'  => $entry['source_url'] ?? '',
				'hints'     => $hints,
			)
		);
	}

	public static function from_elementor( $record, $ajax_handler = null ) {
		if ( ! is_object( $record ) || ! method_exists( $record, 'get' ) ) {
			return;
		}
		$data  = array();
		$hints = array();
		foreach ( (array) $record->get( 'fields' ) as $id => $field ) {
			$label          = ! empty( $field['title'] ) ? $field['title'] : $id;
			$value          = $field['value'] ?? '';
			$data[ $label ] = $value;
			self::hint_from_type( $field['type'] ?? '', $value, $hints );
		}
		$meta = (array) $record->get( 'meta' );
		self::capture(
			$data,
			'elementor',
			array(
				'form_id'   => method_exists( $record, 'get_form_settings' ) ? $record->get_form_settings( 'id' ) : '',
				'form_name' => method_exists( $record, 'get_form_settings' ) ? $record->get_form_settings( 'form_name' ) : 'Elementor Form',
				'page_url'  => $meta['page_url']['value'] ?? '',
				'hints'     => $hints,
			)
		);
	}

	public static function from_fluent( $entry_id, $form_data, $form ) {
		self::capture(
			(array) $form_data,
			'fluentforms',
			array(
				'form_id'   => is_object( $form ) ? $form->id : '',
				'form_name' => is_object( $form ) ? $form->title : 'Fluent Forms',
				'dedupe'    => 'fluent-' . $entry_id,
			)
		);
	}

	public static function from_ninja( $form_data ) {
		$data  = array();
		$hints = array();
		foreach ( (array) ( $form_data['fields'] ?? array() ) as $field ) {
			$type = $field['type'] ?? '';
			if ( in_array( $type, array( 'submit', 'html', 'hr', 'recaptcha', 'spam' ), true ) ) {
				continue;
			}
			$label          = ! empty( $field['label'] ) ? $field['label'] : ( $field['key'] ?? 'Field' );
			$value          = $field['value'] ?? '';
			$data[ $label ] = $value;
			self::hint_from_type( $type, $value, $hints );
		}
		self::capture(
			$data,
			'ninjaforms',
			array(
				'form_id'   => $form_data['form_id'] ?? '',
				'form_name' => $form_data['settings']['title'] ?? 'Ninja Forms',
				'hints'     => $hints,
			)
		);
	}

	public static function from_formidable( $entry_id, $form_id ) {
		if ( ! class_exists( 'FrmEntry' ) || ! class_exists( 'FrmField' ) ) {
			return;
		}
		$entry = FrmEntry::getOne( $entry_id, true );
		if ( ! $entry ) {
			return;
		}
		$data  = array();
		$hints = array();
		foreach ( (array) $entry->metas as $field_id => $value ) {
			$field          = FrmField::getOne( $field_id );
			$label          = $field ? $field->name : 'Field ' . $field_id;
			$data[ $label ] = $value;
			self::hint_from_type( $field ? $field->type : '', $value, $hints );
		}
		$form = class_exists( 'FrmForm' ) ? FrmForm::getOne( $form_id ) : null;
		self::capture(
			$data,
			'formidable',
			array(
				'form_id'   => $form_id,
				'form_name' => $form ? $form->name : 'Formidable Forms',
				'hints'     => $hints,
			)
		);
	}

	/* ------------------------------ Core ------------------------------ */

	/**
	 * Saves and classifies one submission.
	 *
	 * @param array  $fields Raw label => value pairs.
	 * @param string $source Source key (cf7, wpforms, rest, ...).
	 * @param array  $meta   form_id, form_name, page_url, hints, dedupe.
	 * @return array|null Saved row, or null if skipped.
	 */
	public static function capture( $fields, $source = 'custom', $meta = array() ) {
		$fields = self::flatten( (array) $fields );
		if ( empty( $fields ) ) {
			return null;
		}

		$dedupe = $meta['dedupe'] ?? '';
		if ( '' !== $dedupe ) {
			if ( isset( self::$done[ $dedupe ] ) ) {
				return null;
			}
			self::$done[ $dedupe ] = true;
		}

		// Importing an old entry from a form plugin's own storage (see LeadSift_Importer).
		$import = ! empty( $meta['import'] );
		$ref    = substr( (string) ( $meta['external_ref'] ?? '' ), 0, 80 );
		if ( $import && '' !== $ref && LeadSift_DB::has_ref( $ref ) ) {
			return null;
		}

		$settings = LeadSift_Settings::get();
		$form_id  = (string) ( $meta['form_id'] ?? '' );
		$parts    = self::extract_with_map( $fields, sanitize_key( $source ), $form_id, (array) ( $meta['hints'] ?? array() ) );

		$parts['form_name'] = sanitize_text_field( (string) ( $meta['form_name'] ?? '' ) );
		$parts['source']    = sanitize_key( $source );
		$parts['form_type'] = LeadSift_Form_Reader::type_for( $parts['source'], $form_id, $parts['form_name'], array_keys( $fields ) );
		if ( $import ) {
			$ip = $settings['store_ip'] ? (string) ( $meta['ip'] ?? '' ) : '';
		} else {
			$ip = $settings['store_ip'] ? self::client_ip() : '';
		}
		$hash     = self::content_hash( $parts['message'] );
		$learned  = LeadSift_Settings::learned();
		$created  = $import && ! empty( $meta['created_at'] ) ? (string) $meta['created_at'] : current_time( 'mysql' );

		// Already caught live (or imported from another plugin that stores the same entry).
		if ( $import && LeadSift_DB::has_twin( sanitize_email( $parts['email'] ), sanitize_textarea_field( $parts['message'] ), $created ) ) {
			return null;
		}

		$context = array(
			'duplicate_count' => LeadSift_DB::count_duplicates( $hash, $parts['email'] ),
			'ip_recent_count' => LeadSift_DB::count_recent_from_ip( $ip ),
			'learned'         => $parts['email'] && ! empty( $settings['learn_from_actions'] ) ? ( $learned[ strtolower( $parts['email'] ) ] ?? null ) : null,
			// Checks built from the form itself: required fields, real dropdown answers, etc.
			'form_issues'     => LeadSift_Form_Reader::check_submission( $parts['source'], $form_id, $fields ),
		);

		$result = LeadSift_Classifier::classify( $parts, $settings, $context );

		/**
		 * Lets developers adjust the verdict, e.g. to plug in their own AI model.
		 */
		$result = apply_filters( 'leadsift_classification', $result, $parts, $fields, $source );

		$row = array(
			'source'       => sanitize_key( $source ),
			'form_id'      => (string) ( $meta['form_id'] ?? '' ),
			'form_name'    => sanitize_text_field( (string) ( $meta['form_name'] ?? '' ) ),
			'name'         => sanitize_text_field( $parts['name'] ),
			'email'        => sanitize_email( $parts['email'] ),
			'phone'        => sanitize_text_field( $parts['phone'] ),
			'subject'      => sanitize_text_field( $parts['subject'] ),
			'message'      => sanitize_textarea_field( $parts['message'] ),
			'form_data'    => array_map( 'sanitize_textarea_field', $fields ),
			'ip'           => $ip,
			'user_agent'   => $import ? '' : ( isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '' ),
			'page_url'     => esc_url_raw( (string) ( $meta['page_url'] ?? ( ! $import && isset( $_SERVER['HTTP_REFERER'] ) ? wp_unslash( $_SERVER['HTTP_REFERER'] ) : '' ) ) ),
			'content_hash' => $hash,
			'score'        => $result['score'],
			'category'     => $result['category'],
			'reasons'      => $result['reasons'],
			'external_ref' => $ref,
			'is_read'      => $import ? 1 : 0,
			'created_at'   => $created,
		);

		$id = LeadSift_DB::insert( $row );
		if ( ! $id ) {
			return null;
		}
		$row['id'] = $id;

		// Old entries: just sort them. No emails, no AI calls, no notifications.
		if ( $import ) {
			return $row;
		}

		if ( 'genuine' !== $row['category'] && ! empty( $settings['suppress_emails'] ) ) {
			self::$suppress_mail = true;
		}

		// AI second opinion runs in the background so the visitor never waits.
		$ai     = LeadSift_AI::should_check( $result, $settings );
		$notify = ! empty( $settings['notify_genuine'] );
		if ( $ai ) {
			LeadSift_AI::schedule( $id, $notify );
		} elseif ( $notify && 'genuine' === $row['category'] ) {
			self::notify( $row, $settings['notify_email'] );
		}

		do_action( 'leadsift_inquiry_saved', $id, $row );
		return $row;
	}

	/**
	 * Re-runs the classifier on a saved row (used by "Re-scan all").
	 */
	public static function reclassify_row( array $row, array $settings ) {
		$learned = LeadSift_Settings::learned();
		$parts   = LeadSift_AI::parts_from_row( $row );
		$context = array(
			'learned' => $row['email'] && ! empty( $settings['learn_from_actions'] ) ? ( $learned[ strtolower( $row['email'] ) ] ?? null ) : null,
		);
		$result = LeadSift_Classifier::classify( $parts, $settings, $context );

		// Keep an earlier AI verdict instead of throwing it away.
		if ( ! empty( $row['ai_category'] ) ) {
			$result = LeadSift_AI::combine( $result, $row['ai_category'], (int) $row['ai_confidence'], (string) $row['ai_reason'], LeadSift_AI::label( $settings ) );
		}
		return $result;
	}

	/**
	 * Uses the field map from the Forms tab (if this form has one), then falls
	 * back to guessing for any field that is not mapped.
	 */
	public static function extract_with_map( array $fields, $source, $form_id, array $hints = array() ) {
		$map = LeadSift_Form_Reader::get_map( $source . ':' . $form_id );
		if ( empty( $map['roles'] ) ) {
			return self::extract( $fields, $hints );
		}

		$unmapped = array();
		$by_role  = array();
		$extra    = array();
		foreach ( $fields as $label => $value ) {
			$role  = $map['roles'][ $label ] ?? null;
			$value = trim( (string) $value );
			if ( null === $role ) {
				$unmapped[ $label ] = $value;
			} elseif ( in_array( $role, array( 'name', 'email', 'phone', 'subject', 'message' ), true ) ) {
				if ( '' !== $value ) {
					$by_role[ $role ][] = $value;
				}
			} elseif ( 'ignore' !== $role && '' !== $value ) {
				$extra[] = $value; // company / other info: still read by the filter.
			}
		}
		foreach ( $by_role as $role => $values ) {
			if ( 'message' === $role ) {
				$hints['message'] = implode( "\n", $values );
			} elseif ( 'name' === $role ) {
				$hints['name'] = implode( ' ', $values );
			} else {
				$hints[ $role ] = $values[0];
			}
		}

		$parts = self::extract( $unmapped, $hints );
		if ( $extra ) {
			$parts['extra_text'] = trim( $parts['extra_text'] . "\n" . implode( "\n", $extra ) );
		}
		return $parts;
	}

	/**
	 * Finds name / email / phone / subject / message in any form layout.
	 */
	public static function extract( array $fields, array $hints = array() ) {
		$out   = array( 'name' => '', 'email' => '', 'phone' => '', 'subject' => '', 'message' => '' );
		$first = '';
		$last  = '';
		$used  = array();

		foreach ( array( 'name', 'email', 'phone', 'subject', 'message' ) as $key ) {
			if ( ! empty( $hints[ $key ] ) ) {
				$out[ $key ] = trim( (string) $hints[ $key ] );
			}
		}

		foreach ( $fields as $label => $value ) {
			$k = strtolower( (string) $label );
			$v = trim( (string) $value );
			if ( '' === $v ) {
				continue;
			}

			if ( '' === $out['email'] && filter_var( $v, FILTER_VALIDATE_EMAIL ) ) {
				$out['email'] = $v;
				$used[ $label ] = true;
			} elseif ( '' === $out['email'] && preg_match( '/e-?mail/', $k ) ) {
				$out['email'] = $v;
				$used[ $label ] = true;
			} elseif ( '' === $out['phone'] && preg_match( '/phone|mobile|\btel\b|tel-|whatsapp|contact[ _-]?(no|num)|cell/', $k ) ) {
				$out['phone'] = $v;
				$used[ $label ] = true;
			} elseif ( '' === $out['subject'] && preg_match( '/subject|topic|regarding/', $k ) ) {
				$out['subject'] = $v;
				$used[ $label ] = true;
			} elseif ( preg_match( '/message|comment|enquir|inquir|query|question|detail|descri|requirement|note|textarea|body|\bmsg\b|your-msg/', $k ) ) {
				if ( strlen( $v ) > strlen( $out['message'] ) ) {
					$out['message'] = $v;
				}
				$used[ $label ] = true;
			} elseif ( preg_match( '/first|fname|given/', $k ) ) {
				$first = $v;
				$used[ $label ] = true;
			} elseif ( preg_match( '/last|lname|surname|family/', $k ) ) {
				$last = $v;
				$used[ $label ] = true;
			} elseif ( '' === $out['name'] && preg_match( '/name/', $k ) && ! preg_match( '/company|business|organi|user|file|site|domain/', $k ) ) {
				$out['name'] = $v;
				$used[ $label ] = true;
			}
		}

		if ( '' === $out['name'] && ( $first || $last ) ) {
			$out['name'] = trim( $first . ' ' . $last );
		}

		// No obvious message field? Use the longest free-text answer.
		if ( '' === $out['message'] ) {
			$longest = '';
			foreach ( $fields as $label => $value ) {
				if ( empty( $used[ $label ] ) && strlen( (string) $value ) > strlen( $longest ) && strlen( (string) $value ) >= 15 ) {
					$longest = (string) $value;
				}
			}
			if ( '' !== $longest ) {
				$out['message'] = $longest;
				$used[ array_search( $longest, $fields, true ) ] = true;
			}
		}

		// Everything else (company, service, budget…) still gets scanned.
		$extra = array();
		foreach ( $fields as $label => $value ) {
			if ( empty( $used[ $label ] ) && '' !== trim( (string) $value ) ) {
				$extra[] = (string) $value;
			}
		}
		$out['extra_text'] = implode( "\n", $extra );

		return $out;
	}

	/* ----------------------------- Helpers ----------------------------- */

	private static function hint_from_type( $type, $value, array &$hints ) {
		$value = is_array( $value ) ? implode( ' ', array_filter( array_map( 'strval', $value ) ) ) : (string) $value;
		$map   = array( 'email' => 'email', 'name' => 'name', 'phone' => 'phone', 'tel' => 'phone', 'textarea' => 'message' );
		if ( isset( $map[ $type ] ) && '' !== trim( $value ) && empty( $hints[ $map[ $type ] ] ) ) {
			$hints[ $map[ $type ] ] = $value;
		}
	}

	/**
	 * Turns nested form data into flat "Label => text" pairs.
	 */
	private static function flatten( array $fields, $prefix = '' ) {
		$out = array();
		foreach ( $fields as $key => $value ) {
			$key = (string) $key;
			if ( preg_match( self::SKIP_KEYS, $key ) ) {
				continue;
			}
			$label = '' !== $prefix ? $prefix . ' ' . $key : $key;
			if ( is_array( $value ) || is_object( $value ) ) {
				$value   = (array) $value;
				$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
				if ( $is_list ) {
					$out[ $label ] = implode( ', ', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) );
				} else {
					$out = array_merge( $out, self::flatten( $value, $label ) );
				}
			} elseif ( is_scalar( $value ) ) {
				$out[ $label ] = (string) $value;
			}
		}
		return $out;
	}

	private static function content_hash( $message ) {
		$norm = strtolower( preg_replace( '/\s+/', ' ', trim( (string) $message ) ) );
		return strlen( $norm ) >= 20 ? md5( $norm ) : '';
	}

	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	public static function notify( array $row, $to ) {
		if ( ! $to ) {
			return;
		}
		$lines = array(
			'A new genuine inquiry just arrived.',
			'',
			'Name:    ' . $row['name'],
			'Email:   ' . $row['email'],
			'Phone:   ' . $row['phone'],
			'Form:    ' . $row['form_name'],
			'',
			$row['message'],
			'',
			'Open dashboard: ' . admin_url( 'admin.php?page=leadsift' ),
		);
		$headers = array();
		if ( is_email( $row['email'] ) ) {
			$headers[] = 'Reply-To: ' . $row['email'];
		}
		/* translators: %s: sender name or email */
		$subject = sprintf( __( '[LeadSift] Genuine inquiry from %s', 'leadsift' ), $row['name'] ? $row['name'] : $row['email'] );
		wp_mail( $to, $subject, implode( "\n", $lines ), $headers );
	}

	/**
	 * Stops the form plugin's notification email for promotional/spam inquiries
	 * (only when the "suppress emails" setting is on). The inquiry is still saved.
	 */
	public static function maybe_suppress_mail( $return, $atts ) {
		return self::$suppress_mail ? true : $return;
	}
}
