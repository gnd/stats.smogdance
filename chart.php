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

    $max_point = array();
    $sensor_ids[] = $sensor_id;
    $sensor_data = array();
    $sensor_substances = array();
    $sensor_timestamps = array();

    // Get sensor info
    $res = $mydb->getSensorInfo($sensor_id);
    $sensor_data = mysqli_fetch_array($res);
    $city = $sensor_data[0];
    $sensor_name = $sensor_data[1];
    $sensor_substances = explode(" ",$sensor_data[2]);
    sort($sensor_substances);
    $city_substances = $sensor_substances;
    // build a list of all substances for a given city
    foreach ($sensor_substances as $substance) {
        $max_point[$substance] = 0;
    }

    // Get sensor data and the max value for the last 30 days
    $res = $mydb->getLastMonthData($sensor_id, $sensor_substances);
    if ($res->num_rows > 1) {
        while ($line = mysqli_fetch_array($res, MYSQLI_ASSOC)) {
            $sensor_timestamps[] = $line['timestamp'];
            // create an assoc array with all substance data for a given timestamp
            foreach ($sensor_substances as $substance) {
                $sensor_data[$substance][] = $line[$substance];
                // search for maximum for each substance
                if ($line[$substance] > $max_point[$substance]) {
                    $max_point[$substance] = $line[$substance];
                }
            }
        }

        // Compute chart_max for each substance
        $chart_max_data = "var chart_max = {};\n";
        foreach ($sensor_substances as $substance) {
            $chart_max[$substance] = (floor($max_point[$substance] / 10) + 2)  * 10;
            $chart_max_data .= "\tchart_max['{$substance}'] = {$chart_max[$substance]};\n";
        }

        // create chart labels for js
        $labels = "";
        foreach ($sensor_timestamps as $timestamp) {
            $labels .= "\t\t\t\t'" . $timestamp . "',\n";
        }
        $labels = substr($labels, 0, -2); // remove last colon
        $chart_data = "{ labels: [\n {$labels} ],";
        $chart_data .= "\n\t\tdatasets: [ { \n\t\t\tlabel: '".strtoupper($sensor_substances[0])."',";

        // fill the data_array
        $data_array = "var data_array = {};\n";
        $data_array .= "\n\t// create data_array[{$sensor_id}]\n";
        $data_array .= "\tdata_array[{$sensor_id}] = {};\n";
        foreach ($sensor_substances as $substance) {
            $data = implode(",", $sensor_data[$substance]);
            $data = substr($data, 0, -2); // remove last colon
            $data_array .= "\n\t// data for sensor id {$sensor_id} ({$sensor_name}) - substance {$substance}\n";
            $data_array .= "\tdata_array[{$sensor_id}]['{$substance}'] = [{$data}];\n";
        }

        // create chart data for js
        $data = substr($data, 0, -2); // remove last colon
        $chart_data .= "\n\t\t\tdata: data_array[{$sensor_id}]['{$sensor_substances[0]}'],\n";
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
    $max_point = array();
    $sensor_ids = array();
    $sensor_indexes = array();
    $sensor_timestamps = array();
    $sensor_substances = array();
    $sensor_data = array();
    $sensor_data_temp = array();
    $sensor_names = array();
    $city_substances = array();
    $res = $mydb->getCitySensors($city);

    if ($res->num_rows > 1) {
        // Get sensor ids, names and substances
        while ($sensor_desc = mysqli_fetch_array($res)) {
            $sensor_id = $sensor_desc[0];
            $sensor_ids[] = $sensor_id;
            $sensor_indexes[$sensor_id] = 0;
            $sensor_data[$sensor_id] = array();
            if ($first) {
                $first_id = $sensor_id;
                $first = False;
            }
            $sensor_names[$sensor_id] = $sensor_desc[1];
            $sensor_substances_tmp = explode(" ",$sensor_desc[2]);
            sort($sensor_substances_tmp);
            $sensor_substances[$sensor_id] = $sensor_substances_tmp;
            // build a list of all substances for a given city
            foreach ($sensor_substances_tmp as $substance) {
                if (!in_array($substance,$city_substances)) {
                    $city_substances[] = $substance;
                    // initialize max_point for each substance
                    $max_point[$substance] = 0;
                }
            }
        }
        sort($city_substances);

        // Get data for all sensor ids
        $i = 0;
        $data = $mydb->getLastMonthDataForSensors($sensor_ids, $city_substances);
        while ($line = mysqli_fetch_array($data, MYSQLI_ASSOC)) {
            $substance_data = array();
            // create an assoc array with all substance data for a given timestamp
            foreach ($sensor_substances[$line['sensor_id']] as $substance) {
                    $substance_data[$substance] = $line[$substance];
                    // search for maximum for each substance
                    if ($line[$substance] > $max_point[$substance]) {
                        $max_point[$substance] = $line[$substance];
                    }
            }
            $sensor_data_temp[$line['sensor_id']][] = array($line['timestamp'], $substance_data);
        }

        // Process unprecise timestamp data for all sensors from city
        $sensor_count = sizeof($sensor_ids);
        foreach ($sensor_data_temp[$first_id] as $line) {
            $sensor_timestamps[] = $line[0];
            foreach ($sensor_substances[$sensor_id] as $substance) {
                if ($line[1][$substance] == 0) {
                    $sensor_data[$first_id][$substance][] = '';
                } else {
                    $sensor_data[$first_id][$substance][] = $line[1][$substance];
                }
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
                        foreach ($sensor_substances[$sensor_id] as $substance) {
                            $datapoint = (int)$sensor_data_temp[$sensor_id][$sensor_indexes[$sensor_id]][1][$substance];
                            if ($datapoint == 0) {
                                $sensor_data[$sensor_id][$substance][] = '';
                            } else {
                                $sensor_data[$sensor_id][$substance][] = $datapoint;
                            }
                        }
                    // If the data from the other sensor is not timestampe within +-7 minutes
                    } else {
                        // But we see that other sensor's timstamped data is in the past against the first sensors data
                        if ($time_diff > 0) {
                            // We try to check the next data entry for the other sensor
                            $new_index = $sensor_indexes[$sensor_id] + 1;
                            $this_time = strtotime($sensor_data_temp[$sensor_id][$new_index][0]);
                            $time_diff = round(($first_time - $this_time)/60,2);
                            // If we find its within +-7 minutes we store it
                            if (abs($time_diff) < 7) {
                                foreach ($sensor_substances[$sensor_id] as $substance) {
                                    $datapoint = (int)$sensor_data_temp[$sensor_id][$new_index][1][$substance];
                                    if ($datapoint == 0) {
                                            $sensor_data[$sensor_id][$substance][] = '';
                                    } else {
                                        $sensor_data[$sensor_id][$substance][] = $datapoint;
                                    }
                                }
                                $new_index = $sensor_indexes[$sensor_id] + 1;
                                $sensor_indexes[$sensor_id] = $new_index;
                            // Otherwise we raise the index for the other sensor and store nothing
                            } else {
                                $sensor_indexes[$sensor_id] = $new_index;
                                foreach ($sensor_substances[$sensor_id] as $substance) {
                                    $sensor_data[$sensor_id][$substance][] = '';
                                }
                            }
                        // If it is in the future, we keep the index as it is and store nothing for the current timestamp
                        } else {
                            foreach ($sensor_substances[$sensor_id] as $substance) {
                                $sensor_data[$sensor_id][$substance][] = '';
                            }
                        }
                    }
                }
            }
        }
        //$time_mid = microtime(true);
        //echo "\nEnded array processing: " . strval($time_mid - $time_start);

        // create chart labels for js
        $labels = "";
        foreach ($sensor_timestamps as $timestamp) {
            $labels .= "\t\t\t\t'" . $timestamp . "',\n";
        }
        $labels = substr($labels, 0, -1); // remove last colon
        $chart_data = "{ labels: [\n {$labels} ],";

        // fill out datasets
        $data_array = "var data_array = {};\n";
        $chart_data .= "\n\t\tdatasets: [\n";
        $i = 0;
        foreach ($sensor_ids as $sensor_id) {

            // fill the data_array
            $data_array .= "\n\t// create data_array[{$sensor_id}]\n";
            $data_array .= "\tdata_array[{$sensor_id}] = {};\n";
            foreach ($sensor_substances[$sensor_id] as $substance) {
                $data = implode(",", $sensor_data[$sensor_id][$substance]);
                $data = substr($data, 0, -2); // remove last colon
                $data_array .= "\n\t// data for sensor id {$sensor_id} ({$sensor_names[$sensor_id]}) - substance {$substance}\n";
                $data_array .= "\tdata_array[{$sensor_id}]['{$substance}'] = [{$data}];\n";
            }

            // prepare the chartjs data
            $chart_data .= "{ \n\t\t\tlabel: '{$sensor_names[$sensor_id]} (id: {$sensor_id})',";
            $chart_data .= "\n\t\t\tdata: data_array[{$sensor_id}]['{$city_substances[0]}'],\n";
            $chart_data .= "\t\t\tspanGaps: true,\n";
            $chart_data .= "\t\t\tborderWidth: 1,\n";
            $chart_data .= "\t\t\tborderColor: '#' + pal[{$i}],\n";
            $chart_data .= "\t\t\tpointStyle: 'cross',\n";
            $chart_data .= "\t\t\tpointBackgroundColor: 'rgb(0,0,0,0.2)',\n";
            $chart_data .= "\t\t\tpointBorderColor: 'rgb(0,0,0,0.2)',\n";
            $chart_data .= "\t\t\tbackgroundColor: hexToRGBA('#' + pal[{$i}], 0),\n";
            $chart_data .= "\t\t\ttension: 0.6\n";
            $chart_data .= "},";
            $i++;
        }
        $chart_data = substr($chart_data, 0, -1)  . "\n]\n}";

        // Compute chart_max for each substance
        $chart_max_data = "var chart_max = {};\n";
        foreach ($sensor_substances[$sensor_id] as $substance) {
            $chart_max[$substance] = (floor($max_point[$substance] / 10) + 2)  * 10;
            $chart_max_data .= "\tchart_max['{$substance}'] = {$chart_max[$substance]};\n";
        }

        // send appropriate threshold to js - TODO - this has to be done in JS
        $chart_thresholds = $thresholds[$city_substances[0]];
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
                echo "smog.dance / {$city} sensors - {$city_substances[0]}\n";
                $city = ucfirst($city);
                $substance = strtoupper($city_substances[0]);
                $chart_title = "{$city} - {$substance} (last 30 days)";
            } else {
                echo "smog.dance / {$sensor_name}, {$city} - {$city_substances[0]}\n";
                $city = ucfirst($city);
                $substance = strtoupper($city_substances[0]);
                $chart_title = "{$sensor_name}, {$city} - {$city_substances[0]}";
            }
        }
    ?>
</title>

<!-- MOMENT.JS -->
<script src="moment.js"></script>

<!-- CHART.JS -->
<script src="Chart.min.js"></script>

<!-- PALETTE.JS -->
<script src="palette.js"></script>

</head>
<body>
    <?php
        if ($nodata) {
            echo "No data for given sensor";
        } else {
            echo '<div class="chart-container" style="position: relative; width:87%; height:87%;">' . "\n";
            echo "\t\t" . '<canvas id="full"></canvas>' . "\n";
            echo "\t" . '</div>' . "\n";
            echo "\t" . '<br/><br/><br/>' . "\n";
        }
        if ($city_chart) {
            echo "<div style=\"padding-left: 1%;\">Substances available for {$city} (click): \n";
        } else {
            echo "<div style=\"padding-left: 1%;\">Substances available for {$sensor_name}, {$city} (click): \n";
        }
        $i = 0;
        foreach ($city_substances as $substance) {
            if ($i == 0) {
                echo "<button id='{$substance}' style=\"width: 50px; height: 20px; background-color: gold; border: 0px;\" onclick=\"change_substance('{$substance}');\">{$substance}</button>\n";
                $i++;
            } else {
                echo "<button id='{$substance}' style=\"width: 50px; height: 20px; background-color: yellow; border: 0px;\" onclick=\"change_substance('{$substance}');\">{$substance}</button>\n";
            }
            echo "&nbsp;\n";
        }
        echo "</div>\n";
    ?>
</body>
<script>
    // city name
    var city_name = '<?php echo $city; ?>';

    // sensor ids
    var sensor_ids = [<?php echo implode(",", $sensor_ids); ?>];

    // city_substances
    var city_substances = [<?php echo "'" . implode("','", $city_substances) . "'"; ?>];

    // create data_array
    <?php echo $data_array; ?>

    // HEX 2 RGB - taken from
    function hexToR(h) {return parseInt((cutHex(h)).substring(0,2),16)}
    function hexToG(h) {return parseInt((cutHex(h)).substring(2,4),16)}
    function hexToB(h) {return parseInt((cutHex(h)).substring(4,6),16)}
    function cutHex(h) {return (h.charAt(0)=="#") ? h.substring(1,7):h}
    function hexToRGBA(h, alpha) {
        return "rgba(" + hexToR(h) + "," + hexToG(h) + "," + hexToB(h) + "," + alpha + ")";
    }

    function change_substance(substance) {
        for (var i=0; i< window.myChart.data.datasets.length; i++) {
            var sensor_id = sensor_ids[i];
            if (data_array[sensor_id][substance]) {
                window.myChart.data.datasets[i].data = data_array[sensor_id][substance];
            } else {
                window.myChart.data.datasets[i].data = [];
            }
        }
        // change the chart title
        window.myChart.options.title.text = city_name + " " + substance.toUpperCase() + " (last 30 days)";
        // change active button color
        for (var i=0; i< city_substances.length; i++) {
            if (city_substances[i] == substance) {
                document.getElementById(city_substances[i]).style.backgroundColor = 'gold';
            } else {
                document.getElementById(city_substances[i]).style.backgroundColor = 'yellow';
            }
        }
        // change max chart values
        window.myChart.options.scales.yAxes[0].ticks.max = chart_max[substance];

        // update chart
        window.myChart.update();
    }

    var full = document.getElementById("full").getContext('2d');
    var graph_height = window.innerHeight * 0.9;

    // these are the maximum values for all substances
    <?php echo $chart_max_data; ?>

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

    // create a legendCallback - see: https://github.com/chartjs/Chart.js/issues/2565
    function legendcallback(chart) {
        var legendHtml = [];
        legendHtml.push('<table>');
        legendHtml.push('<tr>');
        for (var i=0; i<chart.data.datasets.length; i++) {
            legendHtml.push('<td><div class="chart-legend" style="background-color:' + chart.data.datasets[i].borderColor + '"></div></td>');
            if (chart.data.datasets[i].label) {
                legendHtml.push(
                    '<td class="chart-legend-label-text" onclick="updateDataset(event, ' + '\'' + chart.legend.legendItems[i].datasetIndex + '\'' + ')">'
                     + chart.data.datasets[i].label + '</td>');
            }
        }
        legendHtml.push('</tr>');
        legendHtml.push('</table>');
        return legendHtml.join("");
    }

    window.myChart = new Chart(full, {
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
                        min: 0,
                        max: <?php echo "chart_max['{$city_substances[0]}']"; ?>
                    }
                }]
            },
            legendCallback: legendcallback,
            legend: {
                display: true
            }
        }
    });
</script>
<?php
$time_mid = microtime(true);
echo "<p style=\"font-size: 11px; position: absolute; bottom: 1%; left: 1%;\">Generated in: " . strval($time_mid - $time_start) . " sec. Back to <a href=\"https://stats.smog.dance\" style=\"color: black;\">stats.smog.dance</a></p>";
?>
</html>
