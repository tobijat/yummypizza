<?php
require_once "vendor/autoload.php";

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Yaml\Parser;

$yaml = new Parser();
$config = $yaml->parse(file_get_contents('config.yml'));

$db = connectToDatabase($config["db_host"], $config["db_user"], $config["db_pass"]);

$answer = array("error" => false, "errormessage" => "", "message" => "", "pizzalist" => "", "pizzacount" => "");
$postData= $_REQUEST;
if(!array_key_exists('curlat', $postData) || !array_key_exists('curlon', $postData)) {
    $answer["error"] = true;
    $answer["errormessage"] = "Request failed: curlat and curlon need to be set!";
    echo json_encode($answer);
    exit();
}
$pizzaList = retrieveYummyPizza($db, $postData["curlat"], $postData["curlon"]);

if(count($pizzaList) == 0) {
    $answer["message"] = "Awww.. Seems there is no yummy pizza around you! But I'll just show you the most delicious pizza I have to offer.";
    $pizzaList = retrieveBestPizza($db, $postData["curlat"], $postData["curlon"]);
}

$answer["pizzalist"] = $pizzaList;
$answer["pizzacount"] = count($pizzaList);
echo json_encode($answer);
exit();

function retrieveNearbyPizza($db, $lat, $lon, $radius = 10, $limit = 10) {
    $q = "SELECT location.name, location.grade, location.latitude, location.longitude,
                 visit.quality, visit.price,
                 (6371 * acos(cos(radians('$lat')) * cos(radians(location.latitude))
                  * cos(radians(location.longitude) - radians('$lon')) + sin(radians('$lat'))
                  * sin(radians(location.latitude)))) AS distance,
                 ((visit.quality + visit.price + location.grade) / 3) AS rating
          FROM location, visit
          WHERE
          visit.location=location.id
          HAVING distance < 10
          ORDER BY distance
          LIMIT 0 , 10;";

    return populatePizzaList($db->fetchAll($q));
}

function retrieveBestPizza($db, $lat, $lon, $radius = 10, $limit = 10) {
    $q = "SELECT location.name, location.grade, location.latitude, location.longitude,
                 visit.quality, visit.price,
                 (6371 * acos(cos(radians('$lat')) * cos(radians(location.latitude))
                  * cos(radians(location.longitude) - radians('$lon')) + sin(radians('$lat'))
                  * sin(radians(location.latitude)))) AS distance,
                 ((visit.quality + visit.price + location.grade) / 3) AS rating
          FROM location, visit
          WHERE
          visit.location=location.id AND
          location.grade = 1
          ORDER BY distance
          LIMIT 0 , 10;";

    return populatePizzaList($db->fetchAll($q));
}

function retrieveYummyPizza($db, $lat, $lon, $radius = 10, $limit = 10) {
    $q = "SELECT location.name, location.grade, location.latitude, location.longitude,
                 visit.quality, visit.price,
                 (6371 * acos(cos(radians('$lat')) * cos(radians(location.latitude))
                  * cos(radians(location.longitude) - radians('$lon')) + sin(radians('$lat') )
                  * sin(radians(location.latitude)))) AS distance,
                 ((visit.quality + visit.price + location.grade) / 3) AS rating
          FROM visit, location
          WHERE visit.location = location.id
          HAVING distance < 5
          ORDER BY ((distance + rating) / 2) ASC
          LIMIT 0 , 10;";

    return populatePizzaList($db->fetchAll($q));
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
        $pizzaList[$i]["distance"] = round($item["distance"], 2);
        $pizzaList[$i]["rating"] = round($item["rating"], 1);
        $pizzaList[$i]["quality"] = $item["quality"];
        $pizzaList[$i]["price"] = $item["price"];
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
