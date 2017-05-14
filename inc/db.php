<?php
/*
WInD-mobil project.

Copyright (c) 2013 Jascha Silbermann.
All rights reserved.

Based on 'mein_wind.php'.
*/

// get database connection and data structures
require_once('./../../../' . 'include/config.php');
require_once('./../../../' . 'include/functions.php');

function sql_connect() {
  // connect to the database
  // not taking any responsibility for the error handling!
  @mysql_connect(MYSQL_HOST, MYSQL_USER, MYSQL_PASS) OR die (mysql_error());
  mysql_select_db(MYSQL_DATABASE) OR die (mysql_error());
}

// get current weather at given station
function weather_at_station($station_symbol) {

// local functions

  // local function to get most recent time at station
  $get_time = function ($station_id) {
    // time query
    $time_query = "( SELECT MAX(Datumzeit) as time FROM mewis WHERE station_id = $station_id AND wert IS NOT NULL) UNION ( SELECT MAX(Datumzeit) as time FROM synwerte WHERE station_id = $station_id AND wert IS NOT NULL)";

    // times
    $result = mysql_query($time_query) OR die (mysql_error());
    $times = array();
    while ( $rows = mysql_fetch_assoc($result) ) {
      // select 'time' data
      $times[] = $rows['time'];
    }
    rsort($times);
    $time = $times[0];
    return $time;
  };
  
  // local function to produce SQL queries
  $make_query = function ($station_id, $parameter_id, $time) {
    $query = "( SELECT wert FROM mewis WHERE station_id = $station_id AND parameter_id = $parameter_id AND Datumzeit = '$time' ) UNION ( SELECT wert FROM synwerte WHERE station_id = $station_id AND parameter_id = $parameter_id AND Datumzeit = '$time' )";
    return $query;
  };
  
  // local function to run SQL queries
  $run_query = function ($name, $query) {
    $value = '';
    $result = mysql_query($query[$name]) OR die (mysql_error());
    while ($row = mysql_fetch_assoc($result))
    {
      $value = $row['wert'];
    } 
    return $value;
  };

  // current station
  $station = array();
  $station['symbol']  = $station_symbol;
  $station['id']      = station_id_for($station_symbol);
  $station['name']    = station_name_for($station_symbol);
  $station['time']    = $get_time($station['id']) . ' ' . date_default_timezone_get();

  // construct SQL queries
  $query = array();  
  $query['weather']       = $make_query( $station['id'], 350, $station['time'] );
  $query['n']             = $make_query( $station['id'], 550, $station['time'] );
  $query['temperature']   = $make_query( $station['id'],   1, $station['time'] );
  $query['wind_mean']     = $make_query( $station['id'], 251, $station['time'] );
  $query['wind_squall']   = $make_query( $station['id'], 253, $station['time'] );
  $query['wind_dir']      = $make_query( $station['id'], 250, $station['time'] );
  
  // execute SQL queries
  $station['weather']       = $run_query('weather',     $query);
  $station['n']             = $run_query('n',           $query);
  $station['temperature']   = $run_query('temperature', $query);
  $station['wind_mean']     = $run_query('wind_mean',   $query);
  $station['wind_squall']   = $run_query('wind_squall', $query);
  $station['wind_dir']      = $run_query('wind_dir',    $query);

  // compute icon
  $hour = date('H', strtotime($station['time']));
  // cut off the '.gif' from the end using substr()
  $station['icon'] = substr( basename(symbol($station['n'], $station['weather'], $hour)), 0, -4 );  
  
  return $station;
}

// get forecast for a station
// $interval denotes hours between measurements
function forecast_at_station($station_symbol, $interval) {

// local functions

  // local function to produce SQL queries
  $make_query = function ($station_id, $parameter_id, $interval) {
    $start_date = date('Y-m-d H:i:s');
    $query = "SELECT Datumzeit, wert FROM moswerte WHERE station_id = $station_id AND parameter_id = $parameter_id AND Datumzeit >= '" . $start_date . "' AND (HOUR(Datumzeit) % $interval = 0)";
    return $query;
  };
  
  // local function to run SQL queries
  $run_query = function ($name, $query) {
    $values = array();
    $result = mysql_query($query[$name]) OR die (mysql_error());
    while ($row = mysql_fetch_assoc($result))
    {
      $time          = $row['Datumzeit'];
      $values[$time] = $row['wert'];
    } 
    return $values;
  };

  // current station
  $station = array();
  $station['symbol']    = $station_symbol;
  $station['id']        = station_id_for($station_symbol);
  $station['name']      = station_name_for($station_symbol);
  // limit interval to [1..24]
  $station['interval']  = min(max($interval, 1), 24);

  // construct SQL queries
  $query = array();  
  $query['weather']       = $make_query( $station['id'], 350, $station['interval'] );
  $query['n']             = $make_query( $station['id'], 551, $station['interval'] );
  $query['temperature']   = $make_query( $station['id'],   1, $station['interval'] );
  $query['wind_mean']     = $make_query( $station['id'], 251, $station['interval'] );
  $query['wind_squall']   = $make_query( $station['id'], 253, $station['interval'] );
  $query['wind_dir']      = $make_query( $station['id'], 250, $station['interval'] );

  
  // execute SQL queries
  $station['weather']       = $run_query('weather',     $query);
  $station['n']             = $run_query('n',           $query);
  $station['temperature']   = $run_query('temperature', $query);
  $station['wind_mean']     = $run_query('wind_mean',   $query);
  $station['wind_squall']   = $run_query('wind_squall', $query);
  $station['wind_dir']      = $run_query('wind_dir',    $query);

  $station['values'] = array();

  // gather values under common date
  foreach ($station['weather'] as $time => $value) {
    $hour = date('H', strtotime($time));
    $station['values'][] = array(
      'time'          => $time . ' ' . date_default_timezone_get(),  
      'temperature'   => $station['temperature'][$time],
      'wind_mean'     => $station['wind_mean'][$time],
      'wind_squall'     => $station['wind_squall'][$time],
      'wind_dir'      => $station['wind_dir'][$time],
      // cut off the '.gif' from the end usinf substr()
      'icon'          => substr( basename(symbol($station['n'][$time], $station['weather'][$time], $hour)), 0, -4 ),
      // including the station symbol here for day_summary_for_station()
      'station'       => $station['symbol']
    );
  }
  return $station;
}


// get summary for a day at a station
// these values are only available at certain times each day
// $day must be in the format 'yyyy-mm-dd'
function day_summary_for_station($station_symbol, $day) {
 // local function to produce SQL queries
  $make_query = function ($station_id, $parameter_id, $date) {
    $query = "SELECT wert FROM moswerte WHERE station_id = $station_id AND parameter_id = $parameter_id AND Datumzeit = '$date'";
    return $query;
  };

    // local function to run SQL queries
  $run_query = function ($name, $query) {
    $value = '';
    $result = mysql_query($query[$name]) OR die (mysql_error());
    while ($row = mysql_fetch_assoc($result))
    {
      $value = $row['wert'];
    } 
    return $value;
  };

 // current station
  $station = array();
  $station['symbol']  = $station_symbol;
  $station['id']      = station_id_for($station_symbol);
  $station['name']    = station_name_for($station_symbol);

  $query['temp_min'] = $make_query( $station['id'], 4, $day . ' 06:00:00' );
  $query['temp_max'] = $make_query( $station['id'], 2, $day . ' 18:00:00' );

  $query['sun_time']    = $make_query( $station['id'], 701, date('Y-m-d 00:00:00', strtotime($day . ' + 1 day')) );
  $query['rain_amount'] = $make_query( $station['id'], 405, date('Y-m-d 00:00:00', strtotime($day . ' + 1 day')) );

  $query['clouds_12'] = $make_query( $station['id'], 552, $day . ' 12:00:00' );
  $query['clouds_18'] = $make_query( $station['id'], 552, $day . ' 18:00:00' );
  $query['clouds_00'] = $make_query( $station['id'], 552, date('Y-m-d 00:00:00', strtotime($day . ' + 1 day')) );

  $query['weather_12'] = $make_query( $station['id'], 353, $day . ' 12:00:00' );
  $query['weather_18'] = $make_query( $station['id'], 353, $day . ' 18:00:00' );
  $query['weather_00'] = $make_query( $station['id'], 353, date('Y-m-d 00:00:00', strtotime($day . ' + 1 day')) );

  $station['temp_min'] = $run_query('temp_min', $query);
  $station['temp_max'] = $run_query('temp_max', $query);

  $station['sun_time']    = $run_query('sun_time', $query);
  $station['rain_amount'] = $run_query('rain_amount', $query);

  $station['clouds_12'] = $run_query('clouds_12', $query);
  $station['clouds_18'] = $run_query('clouds_18', $query);
  $station['clouds_00'] = $run_query('clouds_00', $query);

  $station['weather_12'] = $run_query('weather_12', $query);
  $station['weather_18'] = $run_query('weather_18', $query);
  $station['weather_00'] = $run_query('weather_00', $query);

  $station['icon_12'] = $station['icon'] = substr( basename(symbol($station['clouds_12'], $station['weather_12'], 12)), 0, -4 );  
  $station['icon_18'] = $station['icon'] = substr( basename(symbol($station['clouds_18'], $station['weather_18'], 18)), 0, -4 );  
  $station['icon_00'] = $station['icon'] = substr( basename(symbol($station['clouds_00'], $station['weather_00'], 0)), 0, -4 );  

  return $station;

}

///

function get_stations() {
  return array('potsdam', 'dahlem', 'tegel', 'tempelhof', 'schoenefeld', 'wannsee');
}

function station_id_for($station_symbol) {
  // stations
  $stations = array(
    'potsdam'     => 10379,
    'dahlem'      => 10381,
    'tegel'       => 10382,
    'tempelhof'   => 10384,
    'schoenefeld' => 10385,
    'wannsee'     => 500130,
    'pichelsdorf' => 500140
  );
  // get station id matching the station symbol
  $station_id = $stations[$station_symbol];
  // add check for non-match i.e. empty station here
  // ...
  return $station_id;
}

function station_name_for($station_symbol) {
  // stations
  $stations = array(
    'potsdam'     => 'Potsdam',
    'dahlem'      => 'Dahlem',
    'tegel'       => 'Tegel',
    'tempelhof'   => 'Tempelhof',
    'schoenefeld' => 'Schönefeld',
    'wannsee'     => 'Wannsee',
    'pichelsdorf' => 'Pichelsdorf'
  );
  // get station name matching the station symbol
  $station_name = $stations[$station_symbol];
  // add check for non-match i.e. empty station here
  return $station_name;
}

?>

