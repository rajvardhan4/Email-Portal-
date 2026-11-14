<?php
/**
 * Lists every form on the site (from all supported form plugins) together with
 * how many inquiries each one received.
 *
 * Forms are found two ways:
 *  1. Asking each installed form plugin for its forms (so a form shows up even
 *     before its first inquiry).
 *  2. Looking at saved inquiries (catches Elementor forms, which live inside
 *     page content, and custom REST forms).
 *
 * @package LeadSift
 */

defined( 'ABSPATH' ) || exit;

class LeadSift_Forms {

	/**
	 * @return array[] Each: key, source, source_label, form_id, name, detected, total, genuine, promotional, spam, unread, last_at.
	 */
	public static function all() {
		$forms = array();

		foreach ( self::detect() as $form ) {
			$forms[ $form['source'] . ':' . $form['form_id'] ] = $form;
		}

		foreach ( LeadSift_DB::form_counts() as $key => $counts ) {
			if ( isset( $forms[ $key ] ) ) {
				$name          = $forms[ $key ]['name'];
				$forms[ $key ] = array_merge( $forms[ $key ], $counts );
				$forms[ $key ]['name'] = $name ? $name : $counts['name'];
			} else {
				$forms[ $key ] = array_merge( $counts, array( 'detected' => false ) );
			}
		}

		$labels = LeadSift_Settings::sources();
		$out    = array();
		foreach ( $forms as $key => $form ) {
			$read  = LeadSift_Form_Reader::read( $form['source'], $form['form_id'], (string) $form['name'] );
			$form  = array_merge(
				$form,
				array(
					'type'        => $read['type'],
					'field_count' => count( $read['fields'] ),
					'warnings'    => count( $read['warnings'] ),
					'mapped'      => $read['mapped'],
					'fields_from' => $read['from'],
				)
			);
			$out[] = array_merge(
				array(
					'total'       => 0,
					'genuine'     => 0,
					'promotional' => 0,
					'spam'        => 0,
					'unread'      => 0,
					'last_at'     => '',
				),
				$form,
				array(
					'key'          => $key,
					'source_label' => $labels[ $form['source'] ] ?? $form['source'],
					'name'         => '' !== (string) $form['name'] ? $form['name'] : ( 'Form #' . $form['form_id'] ),
				)
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return array( $a['source_label'], $b['total'] * -1, $a['name'] ) <=> array( $b['source_label'], $a['total'] * -1, $b['name'] );
			}
		);
		return $out;
	}

	/**
	 * Asks each active form plugin for its forms. Every block is guarded so one
	 * plugin's API change can never break the dashboard.
	 */
	private static function detect() {
		$found = array();
		$add   = static function ( $source, $id, $name ) use ( &$found ) {
			$found[] = array(
				'source'   => $source,
				'form_id'  => (string) $id,
				'name'     => wp_strip_all_tags( (string) $name ),
				'detected' => true,
			);
		};

		// Contact Form 7 & WPForms store forms as posts.
		foreach ( array( 'cf7' => 'wpcf7_contact_form', 'wpforms' => 'wpforms' ) as $source => $type ) {
			try {
				if ( post_type_exists( $type ) ) {
					$posts = get_posts(
						array(
							'post_type'      => $type,
							'post_status'    => 'publish',
							'posts_per_page' => 500,
							'orderby'        => 'title',
							'order'          => 'ASC',
						)
					);
					foreach ( $posts as $post ) {
						$add( $source, $post->ID, $post->post_title );
					}
				}
			} catch ( \Throwable $e ) { // phpcs:ignore
				// Ignore – the form simply won't be pre-listed.
			}
		}

		// Gravity Forms.
		try {
			if ( class_exists( 'GFAPI' ) && method_exists( 'GFAPI', 'get_forms' ) ) {
				foreach ( (array) GFAPI::get_forms() as $form ) {
					$add( 'gravityforms', $form['id'] ?? '', $form['title'] ?? '' );
				}
			}
		} catch ( \Throwable $e ) { // phpcs:ignore
		}

		// Fluent Forms (own table).
		try {
			if ( defined( 'FLUENTFORM' ) || function_exists( 'wpFluentForm' ) ) {
				global $wpdb;
				$table = $wpdb->prefix . 'fluentform_forms';
				if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
					// phpcs:ignore WordPress.DB.PreparedSQL
					foreach ( (array) $wpdb->get_results( "SELECT id, title FROM {$table} ORDER BY title ASC LIMIT 500", ARRAY_A ) as $form ) {
						$add( 'fluentforms', $form['id'], $form['title'] );
					}
				}
			}
		} catch ( \Throwable $e ) { // phpcs:ignore
		}

		// Ninja Forms.
		try {
			if ( function_exists( 'Ninja_Forms' ) ) {
				foreach ( (array) Ninja_Forms()->form()->get_forms() as $form ) {
					if ( is_object( $form ) && method_exists( $form, 'get_id' ) ) {
						$add( 'ninjaforms', $form->get_id(), $form->get_setting( 'title' ) );
					}
				}
			}
		} catch ( \Throwable $e ) { // phpcs:ignore
		}

		// Formidable Forms.
		try {
			if ( class_exists( 'FrmForm' ) && method_exists( 'FrmForm', 'getAll' ) ) {
				foreach ( (array) FrmForm::getAll( array( 'is_template' => 0, 'status' => 'published' ) ) as $form ) {
					if ( is_object( $form ) ) {
						$add( 'formidable', $form->id, $form->name );
					}
				}
			}
		} catch ( \Throwable $e ) { // phpcs:ignore
		}

		/**
		 * Let developers add more forms: return array of [ source, form_id, name ].
		 */
		foreach ( (array) apply_filters( 'leadsift_extra_forms', array() ) as $form ) {
			if ( isset( $form['source'], $form['form_id'] ) ) {
				$add( sanitize_key( $form['source'] ), $form['form_id'], $form['name'] ?? '' );
			}
		}

		return $found;
	}
}
