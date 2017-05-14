<?php
/*
WInD-mobil project.

Copyright (c) 2013 Jascha Silbermann.
All rights reserved.

*/

// read suntime (sunrise, sunset) from file
function get_sun_times() {
	$times_string = file_get_contents('/home/wind/import/sonnenzeit');
	// format is " Sonne am 4.10. :\t05.13\t16.38\tUTC \n"
	$ts   = explode(':', $times_string);
	//$date = explode('.', $ts[0]);
	$ts   = explode("\t", $ts[1]);
	$sun_times    = array(
		'sunrise' => str_replace('.', ':', trim($ts[1])) . ':00 ' . ' UTC',
		'sunset'  => str_replace('.', ':', trim($ts[2])) . ':00 ' . ' UTC'
	);
	return $sun_times;
}

function get_warning() {
	$warning_string = file_get_contents('/var/www/localhost/htdocs/wind_work/mvd/ampel_inc.htm');
	$color = explode('alt="', $warning_string);
	$color = explode('" src="', $color[1]);
	$color = $color[0];
	$message = explode('<p>', $warning_string);
	$message = explode('</p>', $message[1]);
	$message = str_replace('<br/>', '', $message);
	// convert HTML entities (&ouml; etc.) to UTF-8
	$message = trim(html_entity_decode($message[0], ENT_COMPAT, 'UTF-8'));
	$warning = array(
		'color' 	=> $color,
		'message' 	=> $message
	);
	return $warning;
}

?>