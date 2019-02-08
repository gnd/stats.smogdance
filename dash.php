<?php

include "sttngs.php";
include "db_class.php";
$mydb = new db();
$mydb->connect();
$nodata = False;
$city_chart = False;

// taken from: https://stackoverflow.com/questions/7927475/php-format-latitude-and-longitude-with-degrees-minuets-and-seconds
function DMStoDEC($deg,$min,$sec) {
    return $deg+((($min*60)+($sec))/3600);
}

// helps extracting gps data
function extract_gps($str) {
    $beg = 0;
    $end = strpos($str,'°');
    $deg = trim(substr($str, $beg, $end-$beg));
    $beg = strpos($str,'°') + 2;
    $end = strpos($str,'´');
    $min = trim(substr($str, $beg, $end-$beg));
    $beg = strpos($str,'´') + 2;
    $end = strlen($str);
    $sec = trim(substr($str, $beg, $end-$beg));

    return [$deg, $min, $sec];
}

$sensor_positions = array();
$res = $mydb->getCitySensorsPosition('praha');
if ($res->num_rows > 0) {
    while ($sensor_desc = mysqli_fetch_array($res)) {
        $sensor_id = $sensor_desc[0];
        $sensor_name = $sensor_desc[1];
        $sensor_country = $sensor_desc[2];
        $sensor_gps = $sensor_desc[3];

        // process GPS - first divide the string into two parts - lat and long
        $gps = explode('"', $sensor_gps);
        $lat = 0;
        $lon = 0;
        if (sizeof($gps) > 1) {
            $arr = extract_gps($gps[0]);
            $lat = DMStoDEC($arr[0], $arr[1], $arr[2]);
            $arr = extract_gps($gps[1]);
            $lon = DMStoDEC($arr[0], $arr[1], $arr[2]);

            $sensor_positions[] = "[{$lat},{$lon}]";
        }
    }
}
?>

<html>
<head>
    <META http-equiv="Content-Type" content="text/html; charset=UTF-8">

    <!-- LEAFLET CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.4.0/dist/leaflet.css" integrity="sha512-puBpdR0798OZvTTbP4A8Ix/l+A4dHDD0DGqYW6RQ+9jxkRFclaxxQb/SJAWZfWAkuyeQUytO7+7N4QKrDh+drA==" crossorigin=""/>

    <!-- LEAFLET JS -->
    <script src="https://unpkg.com/leaflet@1.4.0/dist/leaflet.js" integrity="sha512-QVftwZFqvtRNi0ZyCtsznlKSWOStnDORoefr1enyq5mVL4tmKB3S/EnC3rRJcxCPavG10IcrVGSmPh6Qw5lwrg==" crossorigin=""></script>
</head>
<body>
     <div id="mapid" style="height: 90%;"></div>
</body>
<script>
var mymap = L.map('mapid').setView([50.08804, 14.42076], 13);

L.tileLayer('https://api.tiles.mapbox.com/v4/{id}/{z}/{x}/{y}.png?access_token=pk.eyJ1IjoiZ25kIiwiYSI6ImNqcnRkdWR2OTBybmQ0M21xNmYycTJsdTUifQ.faK2wRaTVqj_BAYq-UI5VA', {
    attribution: 'Map data &copy; <a href="https://www.openstreetmap.org/">OpenStreetMap</a> contributors, <a href="https://creativecommons.org/licenses/by-sa/2.0/">CC-BY-SA</a>, Imagery © <a href="https://www.mapbox.com/">Mapbox</a>',
    maxZoom: 15,
    id: 'mapbox.streets',
    accessToken: 'your.mapbox.access.token'
}).addTo(mymap);

// sensor positions
var sensor_positions = [<?php echo implode(",", $sensor_positions); ?>];

// draw sensor positions
for (var i=0; i < sensor_positions.length; i++) {
    var circle = L.circle(sensor_positions[i], {color: 'red', fillColor: '#f03', fillOpacity: 0.5, radius: 100}).addTo(mymap);
}

</script>
</html>
