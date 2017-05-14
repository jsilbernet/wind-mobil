<?php
/*
WInD-mobil project.

Copyright (c) 2013 Jascha Silbermann.
All rights reserved.
*/

/* ---- */

// set JSON header
header('Content-type: application/json');

// get database connection and data structures
require_once('./inc/db.php');
// file-based data
require_once('./inc/files.php');

function run() {
  //error_reporting(E_ALL);
  // set the desired timezone
  date_default_timezone_set('Europe/Berlin');
  // connect to the database
  sql_connect();
  echo json_encode(make_appdata());
  /*$forecast = forecast_at_station('potsdam', 1);
  echo json_encode(make_forecast($forecast['values']));*/
}
run();

/* ---- */

/* Generate data structures  */

function make_appdata() {
  $appdata = array(
    'app'  => make_app(),
    'data' => make_data(),
  );
  return $appdata;
}

function make_app() {
  return array(
    'status'      => 'Test',
    'application' => 'WiND-mobil',
    'version'     => '1.1',
    'request'     => array(
      'uri'  => 'http://' . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'],
      'time' => date('Y-m-d H:i:s e', $_SERVER['REQUEST_TIME'])
    ),
    'timezone'    => date_default_timezone_get()
  );
}

function make_data() {
  return array(
    // use features to add new data channels
    'features' => array('radar', 'suntime', 'warning', 'weather'), // register feature name
    'units' => array(
      'temp' => '°C',
      'wind' => 'm/s'
    ),
    'feature' => array(  // add feature definitions
      'radar'   => make_radar_feature(),
      'suntime' => make_suntime_feature(),
      'warning' => make_warning_feature(),
      'weather' => make_weather_feature()
    )
  );
}

/* -------- */
/* Features */
/* -------- */

function make_radar_feature() {
  return array(
    'de'          => array(
      'url' =>  'http://wind.met.fu-berlin.de/wind/mobile/loops_zip/radar.de_3h.zip'
    ),
    'berlin-100'  => array(
      'url' =>  'http://wind.met.fu-berlin.de/wind/mobile/loops_zip/radar.100_3h.zip'
    ),
    'berlin-200'  => array(
      'url' =>  'http://wind.met.fu-berlin.de/wind/mobile/loops_zip/radar.200_3h.zip'
    )
  );
}

/* ---- */

function make_suntime_feature() {
  $times = get_sun_times();
  $times['sunrise'] = utc_to_local($times['sunrise']);
  $times['sunset']  = utc_to_local($times['sunset']);
  return $times;
}

/* ---- */

function make_warning_feature() {
  $warning = get_warning();
  return $warning;
}


/* ---- */

function make_weather_feature() {
  // all stations
  $stations = get_stations();
  // weather locations
  $locations = array();
  foreach ($stations as $station) {
    // database time values are UTC!
    date_default_timezone_set('UTC');
    $current  = weather_at_station($station);
    // get hourly forecast
    $forecast = forecast_at_station($station, 1);
    // reset to the diplay time format
    date_default_timezone_set('Europe/Berlin');
    $locations[] = array(
      'station'   => $current['name'],
      'current'   => array(
        'time'    => utc_to_local($current['time']),
        'temp'    => array(
          'value'  => $current['temperature']
        ),
        'wind'  => array(
          'speed'  => array(
            'mean' => knots_to_ms($current['wind_mean']),
            'squall' => knots_to_ms($current['wind_squall'])
          ),
          'direction' => $current['wind_dir']
        ),
        'icon'  => $current['icon']
      ),
      'days'  => make_forecast($forecast)
    );
  }
  return $locations;
}

/* ---- */

function make_forecast($forecast) {
  $daily_forecast = array();
  $days = hours_to_days($forecast['values']);
  // fill first day with hours from the second day to make 24 hours total
  $addtl_times  = array_slice($days[1], 0, (24 - count($days[0])));
  $days[0]      = array_merge($days[0], $addtl_times);
  // build forecast for each day
  $day_counter = 0;
  foreach ( $days as $day ) {    
    // get summary values for this day
    $date = date_from_dt(utc_to_local($day[0]['time']));
    date_default_timezone_set('UTC');
    $day_summary = day_summary_for_station($forecast['symbol'], $date);
    date_default_timezone_set('Europe/Berlin');
    // combine forecast and summary values into the day summary
    $daily_forecast[$day_counter] = make_summary($day_summary, $day, $date);
    // break day into hours
    foreach($day as $hour) {
      // add forecast values for the hour
      $daily_forecast[$day_counter]['forecast'][] = array(
        'time'    => utc_to_local($hour['time']),
        'temp'    => array(
            'value'   => $hour['temperature']
          ),
          'wind'  => array(
            'speed'  => array(
              'mean' => knots_to_ms($hour['wind_mean']),
              'squall' => knots_to_ms($hour['wind_squall'])
            ),
            'direction' => $hour['wind_dir']
          ),
          'icon'  => $hour['icon']
      );
    }
    // advance to the next day
    $day_counter += 1;
  }
  return $daily_forecast;
}

/* ---- */

/* Auxiliary functions */

// convert wind speed in knots to m/s
function knots_to_ms($wind_speed) {
  return $wind_speed / 1.94;
}

// convert a UTC date string (from the database) to local time (Europe/Berlin)
// assuming date_default_timezone_set('Europe/Berlin') is set
function utc_to_local($utc_date_string) { 
  return date('Y-m-d H:i:s P' , strtotime($utc_date_string));
}

// get the yyyy-mm-dd part of a date
// (the ten first characters)
function date_from_dt($date_time) {
  return substr($date_time, 0, 10);
}

// cluster together forecast values from the same day
function hours_to_days($forecast_values) {
  $forecast_days = array();
  // set the initial date to compare forecast values against
  $last_day = date_from_dt(utc_to_local($forecast_values[0]['time']));
  // set up the forecast days
  $today  = 0;
  $forecast_days[$today] = array();
  // break forecast values into days
  foreach ($forecast_values as $value) {
    // still on the same day
    if ( date_from_dt(utc_to_local($value['time'])) == $last_day ) {
      // add the current values to the current day
      $forecast_days[$today][] = $value;
    }
    // now at at the next day
    else {
      // set new day's date
      $last_day    = date_from_dt(utc_to_local($value['time']));
      // start a new day
      $today  += 1;
      $forecast_days[$today] = array();
      // add the current values to the new day
      $forecast_days[$today][] = $value;
    }
  }
  // constrain forecast days to five days into the future
  // this is important, as day summaries take values from future days!
  return array_slice($forecast_days, 0, 5);
}

function make_summary($day_summary, $day, $date) {
  // normally we use the measurement at 18:00 for the summary
  $current_time_string = '18';
  // see if this is the current day
  if ($date == date('Y-m-d', $_SERVER['REQUEST_TIME'])) {
    // on the current day we use more fine-grained control
    $current_time = date('H', $_SERVER['REQUEST_TIME']);
    if ($current_time <= 6) {
      $current_time_string = '12';
    }
    else if ($current_time <= 12) {
      $current_time_string = '18';
    }
    else {
      $current_time_string = '00';
    }
  }
  // calculate average wind values
  $wind = calculate_average_wind($day);
  return array(
      'summary' => array(
        'date' => $date,
        'temp' => array('min' => $day_summary['temp_min'],
                        'max' => $day_summary['temp_max']),
        'wind' => array('mean_speed'     => $wind['speed'],
                        'mean_direction' => $wind['direction'],
                        'max_squall'     => $wind['squall']),
        'sun_time'      => $day_summary['sun_time'],
        'rain_amount'   => $day_summary['rain_amount'],
        'clouds'        => $day_summary['clouds_' . $current_time_string],
        'weather'       => $day_summary['weather_' . $current_time_string],
        'icon'          => $day_summary['icon_' . $current_time_string]
      ),
      'forecast' => array()
    );
}

// all parameters have to be arrays of equal length!
function calculate_average_wind($day) {
  $xks = 0;
  $yks = 0;
  $max_squall = 0;
  $hours = count($day);
  // perform trigonometry on the hourly values
  foreach ($day as $hour) {
    $dd_rad = 2 * pi() * $hour['wind_dir'] / 360;
    $xks += knots_to_ms($hour['wind_mean']) * sin($dd_rad);
    $yks += knots_to_ms($hour['wind_mean']) * cos($dd_rad);
    $max_squall = max($max_squall, knots_to_ms($hour['wind_squall']);
  }
  // normalize values
  $xks = $xks / $hours;
  $yks = $yks / $hours;
  // calculate results
  $ddv = atan($xks / $yks);
  $fr  = sqrt(pow($xks, 2) + pow($yks, 2));
  $dr  = $ddv * 360 / 2 * pi();
  // check for and correct overflows
  if ($yks < 0) {
    $dr += 180;
  }
  if ($xks < 0 && $yks >= 0) {
    $dr += 360;
  }
  return array(
    'direction' => $dr,
    'speed'     => $fr,
    'squall'    => $max_squall
  );
}

/* ---- */

/* End of content */

?>

