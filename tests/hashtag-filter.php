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

echo "hashtag-filter: ok\n";
