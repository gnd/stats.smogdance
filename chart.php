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


include "db_class.php";
$mydb = new db();
$mydb->connect();

if (isset($_REQUEST["id"]) && $_REQUEST["id"] != "") {
    $sensor_id = validate_int($mydb->link,$_REQUEST["id"]);

    // Get sensor info
    $data = $mydb->getSensorInfo($sensor_id);
    $line = mysqli_fetch_array($data)
    $city_name = $line[0];
    $sensor_name = $line[1];
    $substance = "pm10";

    // Get sensor data and the max value for the last 30 days
    $points = [];
    $max_point = 0;
    $data = $mydb->getLastMonthData($sensor_id);
    while ($line = mysqli_fetch_array($data)) {
        $points[] = $line;
        if ($max_point < $line[1]) {
            $max_point = $line[1];
        }
    }

    // Compute chart_max
    $chart_max = (floor($max_point / 10) + 2)  * 10;
}

$thresholds = [];
switch ($substance){
    case 'so2':
        $thresholds = [0, 125, 350, 500, 800];		    # http://ec.europa.eu/environment/air/quality/standards.htm
        break;
    case 'o3':
        $thresholds = [0, 50, 100, 150, 200, 300];       # saved in smogdance local
        break;
    case 'pm10':
        $thresholds = [0, 55, 155, 255, 355];          # ??
        break;
    case 'pm25':
        $thresholds = [0, 15, 40, 65, 150];            # ??
        break;
    case 'co':
        $thresholds = [0, 10000, 20000, 30000, 40000];   # http://ec.europa.eu/environment/air/quality/standards.htm
        break;
    case 'no2':
        $thresholds = [0, 100, 150, 200, 300];          # http://ec.europa.eu/environment/air/quality/standards.htm
        break;
}

/*
use Webit\DownSampling\DownSampler\LargestTriangleThreeBucketsDownSampler;
$data = array();
for ($i=0; $i < 500; $i++) {
    $data[] = array($i, mt_rand(0, 200));
}
$sampler = new LargestTriangleThreeBucketsDownSampler();
$sampled = $sampler->sampleDown($data, 100);
echo count($sampled); // displays 100

$hours = [];
$hour_values = [];
$days = [];
$day_values = [];
$sensor_id = "286";
// Get hourly data for the last 30 days
$i = 0;
$hour_num = 0;
$hour_sum = 0;
$data = $mydb->getLastMonthData($sensor_id);
while ($line = mysqli_fetch_array($data)) {
    if (($i % 4) == 0) {
        $hours[] = $hour_num;
        $hour_values[] = $hour_sum / 4;
        $hour_sum = 0;
        $hour_num = ($hour_num + 1) % 24;
        $i++;
    } else {
        $hour_sum = $hour_sum + $line[1];
        $i++;
    }
}

// Compute daily averages for the last 30 days from the data
$i = 0;
$day_num = 0;
$day_sum = 0;
foreach ($hour_values as $hour) {
    if (($i % 24) == 0) {
        $days[] = $day_num;
        $day_values[] = $day_sum / 24;
        $day_sum = 0;
        $day_num++;
        $i++;
    } else {
        $day_sum = $day_sum + $hour;
        $i++;
    }
}*/

?>

<html>
<head>
<title>
    <?php
        echo $substance . " - " . $sensor_name . ", " . $city_name;
    ?>
</title>

<!-- MOMENT.JS -->
<script src="moment.js"></script>

<!-- CHART.JS -->
<script src="Chart.min.js"></script>

</head>
<body>
    <div class="chart-container" style="position: relative; height:90%; width:90%">
        <canvas id="full"></canvas>
    </div>
    <br/>
</body>
<script>
var full = document.getElementById("full").getContext('2d');
var graph_height = window.innerHeight * 0.9;
var chart_max = <?php echo $chart_max; ?>;
var thresholds = <?php echo "[".implode(",", $thresholds)."]"; ?>;
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

var myChart = new Chart(full, {
    type: 'line',
    data: {
        labels: [   <?php
                        $data = "";
                        foreach ($points as $point) {
                            $data .= "'" . $point[0] . "',\n";
                        }
                        echo substr($data, 0, -2); // remove last colon
                    ?>

                ],
        datasets: [ {
            label: '<?php
                        echo $substance . " - full";
                    ?>',
            data: [ <?php
                $data = "";
                        foreach ($points as $point) {
                            if ($point[1] != "") {
                                $data .= $point[1] . ",";
                            } else {
                                $data .= "'',";
                            }
                        }
                        echo substr($data, 0, -2); // remove last colon
                    ?> ],
            spanGaps: true,
            borderWidth: 1,
            borderColor: grd,
            pointStyle: 'cross',
            pointBackgroundColor: 'rgb(0,0,0)',
            pointBorderColor: 'rgb(0,0,0)',
            backgroundColor: grd,
            tension: 0.6
            }
        ]
    },
    options: {
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
</html>
