<?php

/**
 * Sanitizes a input parameter of the type int
 *
 * If the input is not a number, execution is halted
 *
 * @param string $input input integer
 * @return Sanitized input integer
 */
function validate_int($input, $link) {
    $num = mysqli_real_escape_string($link, strip_tags(escapeshellcmd($input)));
    if(is_numeric($num)||($num == "")) {
        return (int) $num;
    } else {
        die("Input parameter not a number");
    }
}

/**
 * Sanitizes a input parameter of the type string
 *
 * @param string $input input string
 * @return Sanitized input string
 */
function validate_str($input, $link) {
    return mysqli_real_escape_string($link, $input);
}

// substance concentration thresholds in air (usually mg/m3)
$thresholds['so2'] = [0, 125, 350, 500, 800];		    # http://ec.europa.eu/environment/air/quality/standards.htm
$thresholds['o3'] = [0, 50, 100, 150, 200, 300];       # saved in smogdance local
$thresholds['pm10'] = [0, 55, 155, 255, 355];          # ??
$thresholds['pm25'] = [0, 15, 40, 65, 150];            # ??
$thresholds['co'] = [0, 10000, 20000, 30000, 40000];   # http://ec.europa.eu/environment/air/quality/standards.htm
$thresholds['no2'] = [0, 100, 150, 200, 300];          # http://ec.europa.eu/environment/air/quality/standards.htm


include "db_class.php";
$mydb = new db();
$mydb->connect();
$nodata = False;
$city_chart = False;


/**
 * Prepares sensor data for a given substance for the last 30 days
 *
 * @param integer $id sensor id
 * @return string $chart_data data for chart.js
 */
if (isset($_REQUEST["id"]) && ($_REQUEST["id"] != "")) {
    $time_start = microtime(true);
    $sensor_id = validate_int($_REQUEST["id"], $mydb->db);

    // Get sensor info
    $res = $mydb->getSensorInfo($sensor_id);
    $sensor_data = mysqli_fetch_array($res);
    $city = $sensor_data[0];
    $sensor_name = $sensor_data[1];
    $substance = "pm10";

    // Get sensor data and the max value for the last 30 days
    $points = [];
    $max_point = 0;
    $res = $mydb->getLastMonthData($sensor_id);
    if ($res->num_rows > 1) {
        while ($line = mysqli_fetch_array($res)) {
            $points[] = $line;
            if ($max_point < $line[1]) {
                $max_point = $line[1];
            }
        }

        // Compute chart_max
        $chart_max = (floor($max_point / 10) + 2)  * 10;

        // create chart labels for js
        $labels = "";
        foreach ($points as $point) {
            $labels .= "\t\t\t\t'" . $point[0] . "',\n";
        }
        $labels = substr($labels, 0, -2); // remove last colon
        $chart_data = "{ labels: [\n {$labels} ],";

        $chart_data .= "\n\t\tdatasets: [ { \n\t\t\tlabel: '".strtoupper($substance)."',";

        // create chart data for js
        $data = "";
        foreach ($points as $point) {
            if ($point[1] != "") {
                $data .= $point[1] . ",";
            } else {
                $data .= "'',";
            }
        }
        $data = substr($data, 0, -2); // remove last colon
        $chart_data .= "\n\t\t\tdata: [ {$data} ],\n";
        $chart_data .= "\t\t\tspanGaps: true,\n";
        $chart_data .= "\t\t\tborderWidth: 1,\n";
        $chart_data .= "\t\t\tborderColor: grd,\n";
        $chart_data .= "\t\t\tpointStyle: 'cross',\n";
        $chart_data .= "\t\t\tpointBackgroundColor: 'rgb(0,0,0)',\n";
        $chart_data .= "\t\t\tpointBorderColor: 'rgb(0,0,0)',\n";
        $chart_data .= "\t\t\tbackgroundColor: grd,\n";
        $chart_data .= "\t\t\ttension: 0.6\n";
        $chart_data .= "\t\t}\n\t]\n}";

        // send appropriate threshold to js
        $chart_thresholds = $thresholds[$substance];
    } else {
        $nodata = True;
    }
}


/**
 * Interleaves sensor data for a given substance
 * for all sensors form a given cityfor the last 30 days
 *
 * This is problematic for several reasons - sensors from the same city
 * might run at slightly different times, so we take measurement timestamps
 * of the first sensor and try to see if the other sensors have measurements taken
 * at a simillar time (+- 5m). If yes we pretend they were measured at the same time
 * and add them to the output, otherwise we discard the data
 *
 * There is still a problem with different lengths of the arrays .. TODO later
 *
 * @param string $city city name
 * @return string $chart_data data for chart.js
 */
if (isset($_REQUEST["city"]) && $_REQUEST["city"] != "") {
    $time_start = microtime(true);
    $city_chart = True;
    $city = validate_str($_REQUEST["city"], $mydb->db);

    // Get data for all sensors from city
    $first = True;
    $first_id = 0;
    $max_point = 0;
    $sensor_ids = array();
    $sensor_indexes = array();
    $sensor_timestamps = array();
    $sensor_data = array();
    $sensor_data_temp = array();
    $sensor_names = array();
    $substance = "pm10";
    $res = $mydb->getCitySensors($city);
    //$time_mid = microtime(true);
    //echo "\nReceived city sensors: " . strval($time_mid - $time_start);
    if ($res->num_rows > 1) {

        // FIll arrays first
        //$time_mid = microtime(true);
        //echo "\nStarting array filling: " . strval($time_mid - $time_start);

        // Get sensor ids
        while ($sensor_desc = mysqli_fetch_array($res)) {
            $sensor_ids[] = $sensor_desc[0];
            $sensor_indexes[$sensor_desc[0]] = 0;
            $sensor_data[$sensor_desc[0]] = array();
            if ($first) {
                $first_id = $sensor_desc[0];
                $first = False;
            }
            $sensor_names[$sensor_desc[0]] = $sensor_desc[1];
        }
        //$time_mid = microtime(true);
        //echo "\nStarting array filling: " . strval($time_mid - $time_start);
        // Get data for all sensor ids
        $data = $mydb->getLastMonthDataForSensors($sensor_ids);
        while ($line = mysqli_fetch_array($data)) {
            $sensor_data_temp[$line[0]][] = array($line[1], $line[2]);
            if ($line[2] > $max_point) {
                $max_point = $line[2];
            }
        }

        //$time_mid = microtime(true);
        //echo "\nEnded array filling: " . strval($time_mid - $time_start);

        // Process unprecise timestamp data for all sensors from city
        //$time_mid = microtime(true);
        //echo "\nStarting array processing: " . strval($time_mid - $time_start);
        $sensor_count = sizeof($sensor_ids);
        foreach ($sensor_data_temp[$first_id] as $line) {
            $sensor_timestamps[] = $line[0];
            if ($line[1] == 0) {
                $sensor_data[$first_id][] = '';
            } else {
                $sensor_data[$first_id][] = $line[1];
            }
            $first_time = strtotime($line[0]);
            foreach ($sensor_ids as $sensor_id) {
                if ($sensor_id != $first_id) {
                    $this_time = strtotime($sensor_data_temp[$sensor_id][$sensor_indexes[$sensor_id]][0]);
                    // If timestamp close to the first_time timestamp
                    $time_diff = round(($first_time - $this_time)/60,2);
                    if (abs($time_diff) < 7) {
                        $new_index = $sensor_indexes[$sensor_id] + 1;
                        $sensor_indexes[$sensor_id] = $sensor_indexes[$sensor_id] + 1;
                        $datapoint = (int)$sensor_data_temp[$sensor_id][$sensor_indexes[$sensor_id]][1];
                        if ($datapoint == 0) {
                            $sensor_data[$sensor_id][] = '';
                        } else {
                            $sensor_data[$sensor_id][] = $datapoint;
                        }
                    } else {
                        if ($time_diff > 0) {
                            $new_index = $sensor_indexes[$sensor_id] + 1;
                            $this_time = strtotime($sensor_data_temp[$sensor_id][$new_index][0]);
                            $time_diff = round(($first_time - $this_time)/60,2);
                            if (abs($time_diff) < 7) {
                                $datapoint = (int)$sensor_data_temp[$sensor_id][$new_index][1];
                                if ($datapoint == 0) {
                                        $sensor_data[$sensor_id][] = '';
                                } else {
                                    $sensor_data[$sensor_id][] = $datapoint;
                                }
                                $new_index = $sensor_indexes[$sensor_id] + 1;
                                $sensor_indexes[$sensor_id] = $new_index;
                            } else {
                                $sensor_indexes[$sensor_id] = $new_index;
                                $sensor_data[$sensor_id][] = '';
                            }
                        } else {
                            $sensor_data[$sensor_id][] = '';
                        }
                    }
                }
            }
        }
        //$time_mid = microtime(true);
        //echo "\nEnded array processing: " . strval($time_mid - $time_start);

        // create chart labels for js
        //$time_mid = microtime(true);
        //echo "\nStarting chart data: " . strval($time_mid - $time_start);
        $labels = "";
        foreach ($sensor_timestamps as $timestamp) {
            $labels .= "\t\t\t\t'" . $timestamp . "',\n";
        }
        $labels = substr($labels, 0, -1); // remove last colon
        $chart_data = "{ labels: [\n {$labels} ],";

        // fill out datasets
        $chart_data .= "\n\t\tdatasets: [\n";
        $i = 0;
        foreach ($sensor_ids as $sensor_id) {
            $chart_data .= "{ \n\t\t\tlabel: '{$sensor_names[$sensor_id]}',";
            $data = implode(",", $sensor_data[$sensor_id]);
            $data = substr($data, 0, -2); // remove last colon
            $chart_data .= "\n\t\t\tdata: [ {$data} ],\n";
            $chart_data .= "\t\t\tspanGaps: true,\n";
            $chart_data .= "\t\t\tborderWidth: 1,\n";
            $chart_data .= "\t\t\tborderColor: '#' + pal[{$i}],\n";
            $chart_data .= "\t\t\tpointStyle: 'cross',\n";
            $chart_data .= "\t\t\tpointBackgroundColor: 'rgb(0,0,0,0.2)',\n";
            $chart_data .= "\t\t\tpointBorderColor: 'rgb(0,0,0,0.2)',\n";
            $chart_data .= "\t\t\tbackgroundColor: 'rgba(255,255,255,0)',\n";
            $chart_data .= "\t\t\ttension: 0.6\n";
            $chart_data .= "},";
            $i++;
        }
        $chart_data = substr($chart_data, 0, -1)  . "\n]\n}";

        // Compute chart_max
        $chart_max = (floor($max_point / 10) + 2)  * 10;

        // send appropriate threshold to js
        $chart_thresholds = $thresholds[$substance];
        //$time_mid = microtime(true);
        //echo "\nEnded chart data: " . strval($time_mid - $time_start);
    } else {
        $nodata = True;
    }
}

?>
<html>
<head>
<title>
    <?php
        if ($nodata) {
            echo "smog.dance / no data\n";
        } else {
            if ($city_chart) {
                echo "smog.dance / {$city} sensors - {$substance}\n";
                $city = ucfirst($city);
                $substance = strtoupper($substance);
                $chart_title = "{$city} - {$substance} (last 30 days)";
            } else {
                echo "smog.dance / {$sensor_name}, {$city} - {$substance}\n";
                $city = ucfirst($city);
                $substance = strtoupper($substance);
                $chart_title = "{$sensor_name}, {$city} - {$substance}";
            }
        }
    ?>
</title>

<!-- MOMENT.JS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.23.0/moment.min.js"></script>

<!-- CHART.JS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/2.7.3/Chart.min.js"></script>

<!-- PALETTE.JS -->
<script src="palette.js"></script>

</head>
<body>
    <?php
        if ($nodata) {
            echo "No data for given sensor";
        } else {
            echo '<div class="chart-container" style="position: relative; height:90%; width:90%">' . "\n";
            echo "\t\t" . '<canvas id="full"></canvas>' . "\n";
            echo "\t" . '</div>' . "\n";
            echo "\t" . '<br/>' . "\n";
        }
    ?>
</body>
<script>
    var full = document.getElementById("full").getContext('2d');
    var graph_height = window.innerHeight * 0.9;
    var chart_max = <?php echo $chart_max; ?>;
    var thresholds = <?php echo "[".implode(",", $chart_thresholds)."]"; ?>;
    var grd = full.createLinearGradient(0, graph_height,  0,  0);
    grd.addColorStop(0, '#9eec80');
    if ((thresholds[1] / chart_max) < 1) {
        grd.addColorStop((thresholds[1] / chart_max), '#ffff00');
    }
    if ((thresholds[2] / chart_max) < 1) {
        grd.addColorStop((thresholds[2] / chart_max), '#ffa500');
    }
    if ((thresholds[3] / chart_max) < 1) {
        grd.addColorStop((thresholds[3] / chart_max), '#ff0000');
    }
    if ((thresholds[4] / chart_max) < 1) {
        grd.addColorStop((thresholds[4] / chart_max), '#e50883');
    }

    // generate palette
    var pal = palette('mpn65', <?php echo sizeof($sensor_ids); ?>);

    var myChart = new Chart(full, {
        type: 'line',
        data: <?php echo $chart_data; ?>,
        options: {
            title: {
                display: true,
                text: '<?php echo $chart_title; ?>'
            },
            scales: {
                xAxes: [{
                    type: 'time',
                    time: {
                        unit: 'day'
                    }
                }],
                yAxes: [{
                    ticks: {
                        max: chart_max
                    }
                }]
            }
        }
    });
</script>
<?php
$time_mid = microtime(true);
echo "<p style=\"font-size: 11px; position: absolute; bottom: 1%; left: 1%;\">Generated in: " . strval($time_mid - $time_start) . " sec. Back to <a href=\"https://stats.smog.dance\" style=\"color: black;\">stats.smog.dance</a></p>";
?>
</html>
