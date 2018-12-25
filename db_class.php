<?php

class db {
    var $link;
    var $prfx;

    function connect() {
        include "sttngs.php";
        $this->db = new mysqli($db_host, $db_user, $db_pass, $db_name);
        $this->prfx = $db_prfx;
    }

    function close() {
        $this->db->close();
    }

    function getLastMonthData($sensor_id) {
        $result = $this->db->query("SELECT timestamp, pm10 from sensor_data WHERE timestamp > DATE_SUB(now(), INTERVAL 30 DAY) AND timestamp <= now() and sensor_id = " . $sensor_id . ";");
        //$result = $this->db->query("select timestamp, pm10 from sensor_data where sensor_id = 286 and timestamp > '2018-08-16 16:32:52' and timestamp < '2018-09-15 16:32:57';");
        //var_dump($kak);
        return $result;
    }

    function getSensorInfo($sensor_id) {
        $result = $this->db->query("SELECT city, name from sensors WHERE id = " . $sensor_id . ";");
        return $result;
    }

}

?>
