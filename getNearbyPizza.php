<?php
require_once "vendor/autoload.php";

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Yaml\Parser;

$yaml = new Parser();
$config = $yaml->parse(file_get_contents('config.yml'));

$db = connectToDatabase($config["db_host"], $config["db_user"], $config["db_pass"]);

$answer = array("error" => false, "errormessage" => "", "pizzalist" => "");
$postData= $_POST;
if(!array_key_exists('curlat', $postData) || !array_key_exists('curlon', $postData)) {
    $answer["error"] = true;
    $answer["errormessage"] = "Request failed: curlat and curlon need to be set!";
    echo json_encode($answer);
    exit();
}
$nearbyPizzaResult = retrieveNearbyPizza($db, $postData["curlat"], $postData["curlon"]);
$nearbyPizzaList = populatePizzaList($nearbyPizzaResult);
$answer["pizzalist"] = $nearbyPizzaList;
echo json_encode($answer);
exit();

function retrieveNearbyPizza($db, $lat, $lon) {
    $q = "SELECT name, grade, latitude, longitude, ( 6371 * acos( cos( radians('$lat') ) * cos( radians( latitude ) )
                              * cos( radians( longitude ) - radians('$lon') ) + sin( radians('$lat') )
                              * sin(radians(latitude)) ) ) AS distance
          FROM location
          HAVING distance < 10
          ORDER BY distance
          LIMIT 0 , 10;";

    return $db->fetchAll($q);
}

function populatePizzaList($queryResult) {
    $pizzaList = array();
    $i = 0;
    foreach($queryResult as $item) {
        $lat = $item["latitude"];
        $lon = $item["longitude"];
        $pizzaList[$i]["mapsLink"] = "https://maps.google.com/maps?q=$lat,$lon&ll=$lat,$lon&z=17";
        $pizzaList[$i]["name"] = $item["name"];
        $pizzaList[$i]["grade"] = $item["grade"];
        $i++;
    }

    return $pizzaList;
}

function connectToDatabase($host, $user, $pass) {
    $config = new Configuration();
    $connectionParams = array(
        'dbname' => $user,
        'user' => $user,
        'password' => $pass,
        'host' => $host,
        'driver' => 'pdo_mysql',
    );
    return DriverManager::getConnection($connectionParams, $config);
}
