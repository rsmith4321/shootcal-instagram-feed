<?php
/**
 * Tiny dependency-free hashtag regression test.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/includes/class-hashtag-filter.php';

use ShootCalInstagramFeed\Hashtag_Filter;

$cases = array(
	array( '#Wedding', 'wedding' ),
	array( ' weddings ', 'weddings' ),
	array( '#Família', 'família' ),
	array( '#Família!', '' ),
	array( '##Portraits', '' ),
	array( 'foo-bar', '' ),
);

foreach ( $cases as list( $input, $expected ) ) {
	$actual = Hashtag_Filter::normalize( $input );
	if ( $actual !== $expected ) {
		throw new RuntimeException( "normalize({$input}) returned {$actual}; expected {$expected}" );
	}
}

$tags = Hashtag_Filter::extract( 'A #Wedding, a #portrait—and #WEDDING again. Not foo#bar.' );
if ( $tags !== array( 'wedding', 'portrait' ) ) {
	throw new RuntimeException( 'Unexpected extracted hashtags: ' . json_encode( $tags ) );
}

$items = array(
	array( 'caption' => 'One #Wedding', 'hashtags' => array( 'wedding' ) ),
	array( 'caption' => 'Two #Weddings', 'hashtags' => array( 'weddings' ) ),
	array( 'caption' => 'Three #WEDDING.', 'hashtags' => array( 'wedding' ) ),
);

if ( 2 !== count( Hashtag_Filter::filter( $items, '#wedding' ) ) ) {
	throw new RuntimeException( 'Exact case-insensitive hashtag filtering failed.' );
}

if ( 1 !== count( Hashtag_Filter::filter( $items, 'weddings' ) ) ) {
	throw new RuntimeException( 'Hashtag substring isolation failed.' );
}

if ( array() !== Hashtag_Filter::filter( $items, 'wedding-photography' ) ) {
	throw new RuntimeException( 'Invalid hashtags must not fall back to an unfiltered feed.' );
}

if ( array( 'wedding', 'beachwedding' ) !== Hashtag_Filter::normalize_list( '#Wedding, #beachwedding,, wedding' ) ) {
	throw new RuntimeException( 'normalize_list did not normalize and deduplicate a comma list.' );
}
if ( array() !== Hashtag_Filter::normalize_list( '  ' ) ) {
	throw new RuntimeException( 'normalize_list did not treat blank input as an empty filter.' );
}
if ( null !== Hashtag_Filter::normalize_list( 'wedding, not-valid!' ) ) {
	throw new RuntimeException( 'normalize_list accepted an invalid entry.' );
}

$list_items = array(
	array( 'caption' => 'One', 'hashtags' => array( 'wedding', 'beachwedding' ) ),
	array( 'caption' => 'Two', 'hashtags' => array( 'familyportraits' ) ),
	array( 'caption' => 'Three', 'hashtags' => array( 'familyportraits', 'wedding' ) ),
	'not-an-item',
);

if ( 2 !== count( Hashtag_Filter::filter_list( $list_items, array( 'wedding', 'engagement' ) ) ) ) {
	throw new RuntimeException( 'filter_list did not match any-of the include tags.' );
}
if ( 1 !== count( Hashtag_Filter::filter_list( $list_items, array( 'familyportraits' ), array( 'wedding' ) ) ) ) {
	throw new RuntimeException( 'filter_list did not drop excluded tags.' );
}
if ( 3 !== count( Hashtag_Filter::filter_list( $list_items, array() ) ) ) {
	throw new RuntimeException( 'filter_list with no filters did not keep every valid item.' );
}
if ( 1 !== count( Hashtag_Filter::filter_list( $list_items, array(), array( 'familyportraits' ) ) ) ) {
	throw new RuntimeException( 'filter_list exclude-only filtering failed.' );
}

echo "hashtag-filter: ok\n";
