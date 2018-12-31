<?php

class db {
    var $link;
    var $prfx;
    var $db_table;
    var $data_table;

    function connect() {
        include "sttngs.php";
        $this->db = new mysqli($db_host, $db_user, $db_pass, $db_name);
        $this->prfx = $db_prfx;
        $this->db_table = $db_table;
        $this->data_table = $data_table;
        $this->data_table_month = $data_table_month;
    }

    function close() {
        $this->db->close();
    }

    function getCitySensors($city) {
        $result = $this->db->query($kak = "SELECT id, name FROM {$this->db_table} WHERE city = '" . $city . "';");
        return $result;
    }

    function getLastMonthData($sensor_id) {
        $result = $this->db->query("SELECT timestamp, pm10 from {$this->data_table_month} WHERE timestamp > DATE_SUB(now(), INTERVAL 30 DAY) AND timestamp <= now() and sensor_id = " . $sensor_id . ";");
        return $result;
    }

    function getLastMonthDataForSensors($sensors) {
        $sensors_selector = "";
        foreach ($sensors as $sensor) {
            $sensors_selector .= "sensor_id = {$sensor} OR ";
        }
        $sensors_selector = substr($sensors_selector, 0, -4);
        $result = $this->db->query("SELECT sensor_id, timestamp, pm10 from {$this->data_table_month} WHERE timestamp > DATE_SUB(now(), INTERVAL 30 DAY) AND timestamp <= now() and ({$sensors_selector});");
        return $result;
    }

    function getSensorInfo($sensor_id) {
        $result = $this->db->query("SELECT city, name from {$this->db_table} WHERE id = " . $sensor_id . ";");
        return $result;
    }

}

?>
