<?php
/**
 * Form reader: looks inside every form on the site BEFORE inquiries arrive.
 *
 * For each form it works out:
 *  - every field (label, input type, required or not);
 *  - what each field means to LeadSift ("role"): name, email, phone, subject,
 *    message, company, other info, or ignore (consent boxes, captchas, uploads…);
 *  - what the form is for ("form type"): contact, quote, booking, job application,
 *    newsletter, support or other.
 *
 * The site owner can correct any of it in the Forms tab. The saved map is then
 * used for every submission of that form, so name / email / message are always
 * read from the right field, and e.g. a newsletter form (email only) is not
 * flagged as spam just because it has no message.
 *
 * @package LeadSift
 */

defined( 'ABSPATH' ) || exit;

class LeadSift_Form_Reader {

	const MAP_OPTION = 'leadsift_form_maps';
	const ROLES      = array( 'name', 'email', 'phone', 'subject', 'message', 'company', 'other', 'ignore' );
	const TYPES      = array( 'contact', 'quote', 'booking', 'job', 'newsletter', 'support', 'other' );

	/** Field types that never hold user text. */
	const SKIP_TYPES = array( 'submit', 'html', 'section', 'page', 'pagebreak', 'divider', 'hr', 'captcha', 'recaptcha', 'hcaptcha', 'turnstile', 'honeypot', 'spam', 'step', 'container', 'custom_html', 'shortcode', 'quiz', 'response' );

	/**
	 * Reads one form.
	 *
	 * @param string $source  Form plugin key (cf7, wpforms, …).
	 * @param string $form_id Form ID inside that plugin.
	 * @param string $name    Form name (used to guess the type).
	 * @return array{ key: string, fields: array, type: string, type_auto: string, mapped: bool, from: string, warnings: string[] }
	 */
	public static function read( $source, $form_id, $name = '' ) {
		$key  = $source . ':' . $form_id;
		$raw  = self::raw_fields( $source, $form_id );
		$from = 'plugin';
		if ( empty( $raw ) ) {
			$raw  = self::fields_from_inquiries( $source, $form_id );
			$from = $raw ? 'inquiries' : 'none';
		}

		$map    = self::get_map( $key );
		$fields = array();
		foreach ( $raw as $f ) {
			$auto     = self::guess_role( $f['label'] . ' ' . $f['key'], $f['type'] );
			$fields[] = array_merge(
				$f,
				array(
					'role_auto'     => $auto,
					'role'          => isset( $map['roles'][ $f['key'] ] ) && in_array( $map['roles'][ $f['key'] ], self::ROLES, true ) ? $map['roles'][ $f['key'] ] : $auto,
					'required_form' => ! empty( $f['required'] ),
					'required'      => isset( $map['required'][ $f['key'] ] ) ? (bool) $map['required'][ $f['key'] ] : ! empty( $f['required'] ),
				)
			);
		}

		$type_auto = self::guess_type( $name, $fields );
		$type      = isset( $map['type'] ) && in_array( $map['type'], self::TYPES, true ) ? $map['type'] : $type_auto;
		$checks    = self::clean_checks( isset( $map['checks'] ) && is_array( $map['checks'] ) ? $map['checks'] : array() );

		return array(
			'key'       => $key,
			'fields'    => $fields,
			'type'      => $type,
			'type_auto' => $type_auto,
			'mapped'    => ! empty( $map ),
			'from'      => $from,
			'checks'    => $checks,
			'rules'     => self::describe_checks( $fields, $checks, $type ),
			'warnings'  => self::warnings( $fields, $type, $from ),
		);
	}

	/**
	 * Plain-language list of the checks LeadSift runs for this form ("auto rules").
	 *
	 * @return string[]
	 */
	public static function describe_checks( array $fields, array $checks, $type ) {
		$out = array();
		foreach ( $fields as $f ) {
			if ( 'ignore' === $f['role'] ) {
				continue;
			}
			$label = '"' . $f['label'] . '"';
			if ( $checks['check_required'] && ! empty( $f['required'] ) ) {
				$out[] = $label . ' must be filled in';
			}
			if ( $checks['check_options'] && ! empty( $f['options'] ) ) {
				$out[] = $label . ' must be one of its ' . count( $f['options'] ) . ' options';
			}
			if ( 'email' === $f['role'] ) {
				$out[] = $label . ' must be a real, non-temporary email (no test@test.com)';
			}
			if ( 'phone' === $f['role'] ) {
				$out[] = $label . ' must be a real phone number (no 1234567890, 9999999999, too short…)';
			}
			if ( 'name' === $f['role'] ) {
				$out[] = $label . ' must look like a real name (no "asdf", "test", random letters)';
			}
			if ( 'message' === $f['role'] ) {
				$min   = $checks['min_words'] > 0 ? $checks['min_words'] : 3;
				$out[] = $label . ' must be a real message: at least ' . $min . ' words, no "hi", "test", "ok", keyboard mashing or repeated words';
			}
		}
		if ( $checks['check_same'] ) {
			$out[] = 'The same text must not be typed into every field';
		}
		if ( in_array( $type, array( 'newsletter', 'job', 'booking' ), true ) ) {
			$out[] = 'An empty message is allowed (this is a ' . $type . ' form)';
		}
		return $out;
	}

	/* ------------------------------ Saved maps ------------------------------ */

	public static function maps() {
		$maps = get_option( self::MAP_OPTION, array() );
		return is_array( $maps ) ? $maps : array();
	}

	public static function get_map( $key ) {
		$maps = self::maps();
		return isset( $maps[ $key ] ) && is_array( $maps[ $key ] ) ? $maps[ $key ] : array();
	}

	public static function save_map( $key, array $roles, $type, array $required = array(), array $checks = array() ) {
		$clean = array();
		foreach ( $roles as $field => $role ) {
			if ( in_array( $role, self::ROLES, true ) ) {
				$clean[ substr( (string) $field, 0, 190 ) ] = $role;
			}
		}
		$req = array();
		foreach ( $required as $field => $on ) {
			$req[ substr( (string) $field, 0, 190 ) ] = (bool) $on;
		}
		$maps         = self::maps();
		$maps[ $key ] = array(
			'roles'    => $clean,
			'type'     => in_array( $type, self::TYPES, true ) ? $type : 'contact',
			'required' => $req,
			'checks'   => self::clean_checks( $checks ),
		);
		update_option( self::MAP_OPTION, $maps, false );
	}

	/**
	 * Form-level checks. Defaults are on; the owner can switch them off per form.
	 */
	public static function clean_checks( array $checks ) {
		return array(
			'check_required' => ! isset( $checks['check_required'] ) || (bool) $checks['check_required'],
			'check_options'  => ! isset( $checks['check_options'] ) || (bool) $checks['check_options'],
			'check_same'     => ! isset( $checks['check_same'] ) || (bool) $checks['check_same'],
			'min_words'      => isset( $checks['min_words'] ) ? max( 0, min( 50, (int) $checks['min_words'] ) ) : 0,
		);
	}

	/**
	 * Checks one submission against what the form itself defines.
	 * Returned issues are added to the spam score by the classifier.
	 *
	 * @param array $def    Output of read() (fields with role/required/options + checks).
	 * @param array $values Submitted "field key => value" pairs (already flattened).
	 * @return array[] Each: label, weight.
	 */
	public static function check_values( array $def, array $values ) {
		$issues = array();
		$checks = $def['checks'] ?? self::clean_checks( array() );
		$filled = array();

		foreach ( (array) ( $def['fields'] ?? array() ) as $f ) {
			if ( 'ignore' === ( $f['role'] ?? '' ) ) {
				continue;
			}
			$exists = array_key_exists( $f['key'], $values );
			$value  = $exists ? trim( (string) $values[ $f['key'] ] ) : '';
			if ( '' !== $value ) {
				$filled[] = mb_strtolower( $value );
			}

			// 1. Required field left empty → the browser form was bypassed (bots post directly).
			if ( $checks['check_required'] && ! empty( $f['required'] ) && $exists && '' === $value ) {
				$issues[] = array(
					'label'  => 'Required field "' . $f['label'] . '" was left empty (the form was bypassed)',
					'weight' => 30,
				);
			}

			// 2. Dropdown / radio / checkbox answer that is not one of the form's options.
			// Skipped when the field has an "Other" choice (people may type their own answer).
			$allowed = array_map( 'mb_strtolower', array_map( 'trim', (array) ( $f['options'] ?? array() ) ) );
			$has_other = (bool) preg_grep( '/^(other|others|anything else|any other)\b/', $allowed );
			if ( $checks['check_options'] && '' !== $value && $allowed && ! $has_other ) {
				$given   = array_filter( array_map( 'trim', preg_split( '/[,\n]+/', mb_strtolower( $value ) ) ), 'strlen' );
				foreach ( $given as $one ) {
					if ( ! in_array( $one, $allowed, true ) && ! in_array( mb_strtolower( $value ), $allowed, true ) ) {
						$issues[] = array(
							'label'  => 'Answer in "' . $f['label'] . '" is not one of the form\'s options ("' . mb_substr( $one, 0, 30 ) . '")',
							'weight' => 30,
						);
						break;
					}
				}
			}

			// 3. Number fields must contain a number.
			if ( '' !== $value && in_array( $f['type'], array( 'number', 'quantity' ), true ) && ! preg_match( '/\d/', $value ) ) {
				$issues[] = array(
					'label'  => 'Number field "' . $f['label'] . '" has no number in it',
					'weight' => 20,
				);
			}

			// 4. Owner-set minimum length for the message.
			if ( 'message' === ( $f['role'] ?? '' ) && $checks['min_words'] > 0 && '' !== $value ) {
				$words = preg_match_all( '/\p{L}+/u', $value );
				if ( $words < $checks['min_words'] ) {
					$issues[] = array(
						'label'  => 'Message has only ' . $words . ' word' . ( 1 === $words ? '' : 's' ) . ' (this form expects at least ' . $checks['min_words'] . ')',
						'weight' => 25,
					);
				}
			}
		}

		// 5. Same text pasted into every field ("asdf" in name, email, message…).
		if ( $checks['check_same'] && count( $filled ) >= 3 && 1 === count( array_unique( $filled ) ) ) {
			$issues[] = array(
				'label'  => 'The same text was typed into every field',
				'weight' => 35,
			);
		}

		return $issues;
	}

	/** Reads the form and checks a submission against it in one go. */
	public static function check_submission( $source, $form_id, array $values ) {
		if ( '' === (string) $form_id && 'rest' !== $source ) {
			return array();
		}
		$def = self::read( $source, $form_id );
		return 'plugin' === $def['from'] ? self::check_values( $def, $values ) : array();
	}

	public static function reset_map( $key ) {
		$maps = self::maps();
		unset( $maps[ $key ] );
		update_option( self::MAP_OPTION, $maps, false );
	}

	/** The form type to use for a submission (saved choice, else a guess from the name). */
	public static function type_for( $source, $form_id, $form_name, array $field_labels = array() ) {
		$map = self::get_map( $source . ':' . $form_id );
		if ( isset( $map['type'] ) && in_array( $map['type'], self::TYPES, true ) ) {
			return $map['type'];
		}
		$fields = array();
		foreach ( $field_labels as $label ) {
			$fields[] = array( 'label' => $label, 'key' => $label, 'type' => '', 'role' => self::guess_role( $label, '' ) );
		}
		return self::guess_type( $form_name, $fields );
	}

	/* ------------------------------ Guessing ------------------------------ */

	public static function guess_role( $label, $type ) {
		$l    = strtolower( (string) $label );
		$type = strtolower( (string) $type );

		if ( in_array( $type, array( 'date', 'time', 'datetime', 'date-time' ), true ) ) {
			return 'other';
		}
		if ( in_array( $type, array( 'file', 'upload', 'file-upload', 'fileupload', 'acceptance', 'consent', 'gdpr', 'hidden', 'password', 'signature', 'quiz' ), true )
			|| preg_match( '/consent|accept|agree|gdpr|privacy|terms|captcha|upload|attach|resume|\bcv\b|hidden|honeypot|newsletter opt/', $l ) ) {
			return 'ignore';
		}
		if ( 'email' === $type || preg_match( '/e-?mail/', $l ) ) {
			return 'email';
		}
		if ( in_array( $type, array( 'tel', 'phone' ), true ) || preg_match( '/phone|mobile|\btel\b|tel-|whatsapp|contact[ _-]?(no|num)|\bcell/', $l ) ) {
			return 'phone';
		}
		if ( preg_match( '/subject|topic|regarding/', $l ) ) {
			return 'subject';
		}
		if ( 'textarea' === $type || preg_match( '/message|comment|enquir|inquir|query|question|detail|descri|requirement|\bnote|\bmsg\b|tell us|how can we help/', $l ) ) {
			return 'message';
		}
		if ( preg_match( '/company|business|organi[sz]ation|firm|brand|website|\burl\b|domain/', $l ) ) {
			return 'company';
		}
		if ( 'name' === $type || preg_match( '/name|first|last|surname|fname|lname|full.?name/', $l ) ) {
			return 'name';
		}
		return 'other';
	}

	public static function guess_type( $name, array $fields ) {
		$text  = strtolower( (string) $name . ' ' . implode( ' ', array_column( $fields, 'label' ) ) );
		$n     = strtolower( (string) $name );
		$roles = array_column( $fields, 'role' );

		if ( preg_match( '/career|job|apply|application|vacanc|hiring|resume|\bcv\b|recruit/', $n ) ) {
			return 'job';
		}
		if ( preg_match( '/newsletter|subscribe|subscription|updates|mailing/', $n ) || ( $fields && count( array_diff( $roles, array( 'email', 'name', 'ignore' ) ) ) === 0 && in_array( 'email', $roles, true ) && ! in_array( 'message', $roles, true ) && count( $fields ) <= 3 ) ) {
			return 'newsletter';
		}
		if ( preg_match( '/book|appointment|reservation|schedule|slot|visit|consultation|demo/', $n ) || preg_match( '/check.?in|check.?out|preferred date|appointment date|no\.? of (guests|people)/', $text ) ) {
			return 'booking';
		}
		if ( preg_match( '/quote|quotation|estimate|pricing|price|order|bulk|enquiry for|request a/', $n ) || preg_match( '/budget|quantity|qty/', $text ) ) {
			return 'quote';
		}
		if ( preg_match( '/support|help ?desk|complaint|ticket|warranty|service request|feedback/', $n ) ) {
			return 'support';
		}
		if ( preg_match( '/contact|enquiry|inquiry|get in touch|reach|message|callback|call back/', $n ) || in_array( 'message', $roles, true ) ) {
			return 'contact';
		}
		return 'other';
	}

	private static function warnings( array $fields, $type, $from ) {
		$w     = array();
		$roles = array_column( $fields, 'role' );
		if ( 'none' === $from ) {
			$w[] = 'Could not read the fields of this form yet. They will appear after its first inquiry.';
			return $w;
		}
		if ( 'inquiries' === $from ) {
			$w[] = 'Fields were learned from received inquiries (this form plugin does not expose its fields).';
		}
		if ( ! in_array( 'email', $roles, true ) && ! in_array( 'phone', $roles, true ) ) {
			$w[] = 'No email or phone field found – you will not be able to reply to these inquiries.';
		}
		if ( ! in_array( 'message', $roles, true ) && ! in_array( $type, array( 'newsletter', 'job', 'booking' ), true ) ) {
			$w[] = 'No message field found – LeadSift will judge these inquiries from the other fields only.';
		}
		if ( count( array_keys( $roles, 'email', true ) ) > 1 ) {
			$w[] = 'More than one field is marked as Email – the first filled one is used.';
		}
		return $w;
	}

	/* ------------------------- Reading each plugin ------------------------- */

	/**
	 * @return array[] Each: key (as captured), label, type, required.
	 */
	private static function raw_fields( $source, $form_id ) {
		$out = array();
		try {
			switch ( $source ) {
				case 'cf7':
					if ( class_exists( 'WPCF7_ContactForm' ) ) {
						$form = WPCF7_ContactForm::get_instance( (int) $form_id );
						if ( $form ) {
							foreach ( $form->scan_form_tags() as $tag ) {
								if ( '' === (string) $tag->name || in_array( $tag->basetype, self::SKIP_TYPES, true ) ) {
									continue;
								}
								$opts = array();
								if ( in_array( $tag->basetype, array( 'select', 'checkbox', 'radio' ), true ) && ! ( method_exists( $tag, 'has_option' ) && $tag->has_option( 'free_text' ) ) ) {
									foreach ( (array) ( ! empty( $tag->raw_values ) ? $tag->raw_values : $tag->values ) as $raw ) {
										$opts = array_merge( $opts, explode( '|', (string) $raw ) );
									}
								}
								$out[] = self::field( $tag->name, self::pretty( $tag->name ), $tag->basetype, method_exists( $tag, 'is_required' ) && $tag->is_required(), $opts );
							}
						}
					}
					break;

				case 'wpforms':
					$post = get_post( (int) $form_id );
					if ( $post && 'wpforms' === $post->post_type ) {
						$data = function_exists( 'wpforms_decode' ) ? wpforms_decode( $post->post_content ) : json_decode( $post->post_content, true );
						foreach ( (array) ( $data['fields'] ?? array() ) as $f ) {
							$type = $f['type'] ?? '';
							if ( in_array( $type, self::SKIP_TYPES, true ) ) {
								continue;
							}
							$label = ! empty( $f['label'] ) ? $f['label'] : 'Field ' . ( $f['id'] ?? '' );
							$out[] = self::field( $label, $label, $type, ! empty( $f['required'] ), self::choice_list( $f['choices'] ?? array(), array( 'label', 'value' ) ) );
						}
					}
					break;

				case 'gravityforms':
					if ( class_exists( 'GFAPI' ) ) {
						$form = GFAPI::get_form( (int) $form_id );
						foreach ( (array) ( $form['fields'] ?? array() ) as $f ) {
							$type = is_object( $f ) ? $f->type : ( $f['type'] ?? '' );
							if ( in_array( $type, self::SKIP_TYPES, true ) ) {
								continue;
							}
							$label = is_object( $f ) ? $f->label : ( $f['label'] ?? '' );
							$id    = is_object( $f ) ? $f->id : ( $f['id'] ?? '' );
							$label = $label ? $label : 'Field ' . $id;
							$choices = is_object( $f ) ? ( $f->choices ?? array() ) : ( $f['choices'] ?? array() );
							$out[]   = self::field( $label, $label, $type, is_object( $f ) ? ! empty( $f->isRequired ) : ! empty( $f['isRequired'] ), in_array( $type, array( 'select', 'radio', 'checkbox', 'multiselect' ), true ) ? self::choice_list( $choices, array( 'text', 'value' ) ) : array() );
						}
					}
					break;

				case 'fluentforms':
					global $wpdb;
					$table = $wpdb->prefix . 'fluentform_forms';
					if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
						// phpcs:ignore WordPress.DB.PreparedSQL
						$json = $wpdb->get_var( $wpdb->prepare( "SELECT form_fields FROM {$table} WHERE id = %d", (int) $form_id ) );
						$data = json_decode( (string) $json, true );
						self::walk_fluent( (array) ( $data['fields'] ?? array() ), '', $out );
					}
					break;

				case 'ninjaforms':
					if ( function_exists( 'Ninja_Forms' ) ) {
						foreach ( (array) Ninja_Forms()->form( (int) $form_id )->get_fields() as $f ) {
							$type = $f->get_setting( 'type' );
							if ( in_array( $type, self::SKIP_TYPES, true ) ) {
								continue;
							}
							$label = $f->get_setting( 'label' );
							$label = $label ? $label : $f->get_setting( 'key' );
							$out[] = self::field( $label, $label, $type, (bool) $f->get_setting( 'required' ), in_array( $type, array( 'listselect', 'listradio', 'listcheckbox', 'listmultiselect' ), true ) ? self::choice_list( (array) $f->get_setting( 'options' ), array( 'label', 'value' ) ) : array() );
						}
					}
					break;

				case 'formidable':
					if ( class_exists( 'FrmField' ) && method_exists( 'FrmField', 'get_all_for_form' ) ) {
						foreach ( (array) FrmField::get_all_for_form( (int) $form_id ) as $f ) {
							if ( in_array( $f->type, array_merge( self::SKIP_TYPES, array( 'break', 'end_divider', 'form' ) ), true ) ) {
								continue;
							}
							$out[] = self::field( $f->name, $f->name, $f->type, ! empty( $f->required ), in_array( $f->type, array( 'select', 'radio', 'checkbox' ), true ) ? self::choice_list( (array) $f->options, array( 'label', 'value' ) ) : array() );
						}
					}
					break;
			}
		} catch ( \Throwable $e ) { // phpcs:ignore
			return array();
		}
		return $out;
	}

	/** Fluent Forms nests fields inside containers and name/address groups. */
	private static function walk_fluent( array $items, $prefix, array &$out ) {
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( ! empty( $item['columns'] ) ) {
				foreach ( (array) $item['columns'] as $col ) {
					self::walk_fluent( (array) ( $col['fields'] ?? array() ), $prefix, $out );
				}
				continue;
			}
			$element = $item['element'] ?? '';
			$name    = $item['attributes']['name'] ?? '';
			if ( in_array( $element, array( 'button', 'custom_html', 'section_break', 'recaptcha', 'hcaptcha', 'turnstile', 'shortcode', 'form_step' ), true ) ) {
				continue;
			}
			if ( ! empty( $item['fields'] ) && is_array( $item['fields'] ) ) {
				// Name / address groups: children are captured as "parent child".
				foreach ( $item['fields'] as $child_key => $child ) {
					if ( ! empty( $child['settings']['visible'] ) || ! isset( $child['settings']['visible'] ) ) {
						$child_name = $child['attributes']['name'] ?? $child_key;
						$label      = $child['settings']['label'] ?? $child_name;
						$out[]      = self::field( trim( $prefix . $name . ' ' . $child_name ), $label, $child['element'] ?? '', ! empty( $child['settings']['validation_rules']['required']['value'] ) );
					}
				}
				continue;
			}
			if ( '' === $name ) {
				continue;
			}
			$label = $item['settings']['label'] ?? ( $item['settings']['admin_field_label'] ?? $name );
			$type  = $item['attributes']['type'] ?? $element;
			if ( 'textarea' === $element ) {
				$type = 'textarea';
			}
			$opts  = in_array( $element, array( 'select', 'input_radio', 'input_checkbox' ), true ) ? self::choice_list( (array) ( $item['settings']['advanced_options'] ?? $item['options'] ?? array() ), array( 'label', 'value' ) ) : array();
			$out[] = self::field( $prefix . $name, $label ? $label : $name, $type, ! empty( $item['settings']['validation_rules']['required']['value'] ), $opts );
		}
	}

	/** Elementor / custom forms: learn the fields from the last inquiries. */
	private static function fields_from_inquiries( $source, $form_id ) {
		global $wpdb;
		$table = LeadSift_DB::table();
		// phpcs:ignore WordPress.DB.PreparedSQL
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT form_data FROM {$table} WHERE source = %s AND form_id = %s ORDER BY id DESC LIMIT 20", $source, (string) $form_id ) );
		$seen = array();
		foreach ( (array) $rows as $json ) {
			foreach ( array_keys( (array) json_decode( (string) $json, true ) ) as $k ) {
				$seen[ $k ] = true;
			}
		}
		$out = array();
		foreach ( array_keys( $seen ) as $k ) {
			$out[] = self::field( $k, $k, '', false );
		}
		return $out;
	}

	private static function field( $key, $label, $type, $required, array $options = array() ) {
		$options = array_values( array_unique( array_filter( array_map( 'trim', array_map( 'strval', $options ) ), 'strlen' ) ) );
		return array(
			'key'      => (string) $key,
			'label'    => wp_strip_all_tags( (string) $label ),
			'type'     => (string) $type,
			'required' => (bool) $required,
			'options'  => array_slice( $options, 0, 200 ),
		);
	}

	/** Turns choice lists of any shape into plain strings (labels and values). */
	private static function choice_list( $choices, array $keys ) {
		$out = array();
		foreach ( (array) $choices as $k => $c ) {
			if ( is_array( $c ) || is_object( $c ) ) {
				foreach ( $keys as $key ) {
					$v = is_array( $c ) ? ( $c[ $key ] ?? '' ) : ( $c->$key ?? '' );
					if ( is_scalar( $v ) && '' !== (string) $v ) {
						$out[] = (string) $v;
					}
				}
			} elseif ( is_scalar( $c ) ) {
				$out[] = (string) $c;
				if ( is_string( $k ) ) {
					$out[] = $k;
				}
			}
		}
		return $out;
	}

	/** "your-name" → "Your name". */
	private static function pretty( $name ) {
		return ucfirst( trim( str_replace( array( '-', '_' ), ' ', (string) $name ) ) );
	}
}
