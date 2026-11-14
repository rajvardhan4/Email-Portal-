<?php
/**
 * LeadSift classifier test runner (no WordPress needed).
 *
 *   php tests/run.php            – summary + every wrong verdict
 *   php tests/run.php -v         – also print the reasons for every case
 *
 * Cases live in tests/cases.json. "expect" is a category or a list of
 * acceptable categories.
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/class-leadsift-classifier.php';

$verbose  = in_array( '-v', $argv, true );
$cases    = json_decode( file_get_contents( __DIR__ . '/cases.json' ), true );
$settings = array(
	'sensitivity'          => 'balanced',
	'flag_foreign_scripts' => true,
	'strict_quality'       => true,
	'phone_country'        => 'IN',
	'promo_keywords'       => '',
	'genuine_keywords'     => '',
	'trusted_senders'      => '',
	'blocked_senders'      => '',
	'custom_rules'         => array(),
);

$stats  = array();
$failed = array();
foreach ( $cases as $case ) {
	$expect = (array) $case['expect'];
	$input  = array(
		'name'       => $case['name'] ?? '',
		'email'      => $case['email'] ?? '',
		'phone'      => $case['phone'] ?? '',
		'subject'    => $case['subject'] ?? '',
		'message'    => $case['message'] ?? '',
		'extra_text' => $case['extra'] ?? '',
		'form_name'  => $case['form_name'] ?? 'Contact us',
		'form_type'  => $case['form_type'] ?? 'contact',
		'source'     => 'cf7',
	);
	$context = array( 'form_issues' => $case['form_issues'] ?? array() );
	$result  = LeadSift_Classifier::classify( $input, $settings, $context );
	$ok      = in_array( $result['category'], $expect, true );
	$group   = $expect[0];

	$stats[ $group ]['total'] = ( $stats[ $group ]['total'] ?? 0 ) + 1;
	$stats[ $group ]['ok']    = ( $stats[ $group ]['ok'] ?? 0 ) + ( $ok ? 1 : 0 );

	if ( ! $ok || $verbose ) {
		$line = sprintf( "%s %-4s expected %-12s got %-12s score %3d  | %s", $ok ? 'ok ' : 'BAD', $case['id'], implode( '/', $expect ), $result['category'], $result['score'], mb_substr( $input['message'], 0, 70 ) );
		$why  = array();
		foreach ( $result['reasons'] as $r ) {
			$why[] = ( $r['weight'] > 0 ? '+' : '' ) . $r['weight'] . ' ' . $r['label'];
		}
		$out = $line . "\n        " . implode( "\n        ", $why ) . "\n";
		if ( $ok ) {
			echo $out;
		} else {
			$failed[] = $out;
		}
	}
}

echo "\n" . implode( '', $failed );
echo "\n=== Accuracy ===\n";
$all = 0;
$good = 0;
foreach ( $stats as $group => $s ) {
	printf( "%-12s %2d / %2d  (%d%%)\n", $group, $s['ok'], $s['total'], round( 100 * $s['ok'] / $s['total'] ) );
	$all  += $s['total'];
	$good += $s['ok'];
}
printf( "%-12s %2d / %2d  (%d%%)\n", 'TOTAL', $good, $all, round( 100 * $good / $all ) );
exit( $good === $all ? 0 : 1 );
