<?php
require_once "vendor/autoload.php";

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Yaml\Parser;

$yaml = new Parser();
$config = $yaml->parse(file_get_contents('config.yml'));

$db = connectToDatabase($config["db_host"], $config["db_user"], $config["db_pass"]);

$items = loadItemsFromKlm($config["klm_url"]);
$parsedItems = parseItems($items);
$visits = $parsedItems["visits"];
$locations = $parsedItems["locations"];
removeOutdatedVisits($db, $visits);
removeOutdatedLocations($db, $locations);
updatePizzaDatabase($db, $visits);

//print_r($visits);
//print_r($locations);
//echo count($visits);
exit;

function loadItemsFromKlm($urlToKlm) {
    $kml_content = file_get_contents($urlToKlm);
    $xml = simplexml_load_string($kml_content, "SimpleXMLElement", LIBXML_NOCDATA);
    return $xml->Document->Folder->Placemark;
}

function parseItems($items) {
    $i = 0;
    $locations = array();
    $visits = array();
    foreach($items as $item) {
        //$name = utf8_decode($item->name);
        $name = $item->name;
        //$description = utf8_decode(getDescription($item->description));
        $description = getDescription($item->description);
        $grade = getGrade($item->styleUrl);
        $coordinates = getCoordinates($item->Point->coordinates);
        $date = getDateOfVisit($item->description);
        $quality = getQuality($item->description);
        $price = getPrice($item->description);
        if(!is_numeric($quality) || !is_numeric($price)) {
            echo "ERROR while parsing! Multiple visits? " . ++$i . '. ' . $name . "\n";
            continue;
        }
        $unique_location = hash("sha256", $name.$coordinates["lat"].$coordinates["lon"]);
        $unique_visit = hash("sha256", $date.$unique_location);
        $locations[$unique_location] =
            array( "unique_location" => $unique_location,
                "name" => $name,
                "grade" => $grade,
                "latitude" => $coordinates["lat"],
                "longitude" => $coordinates["lon"]);
        $visits[$unique_visit] =
            array("location" => $locations[$unique_location],
                "date" => $date,
                "description" => $description,
                "quality" => $quality,
                "price" => $price);
    }

    return array("visits" => $visits, "locations" => $locations);
}

function getDescription($data) {
	$c = determineExplodeChar($data);
	if($c === false) {
		return false;
	}
	$parts = explode($c, $data);

	return $parts[2];
}

function getGrade($style_url) {
	$grades = array( "#icon-123" => 4, "#icon-157" => 3, "#icon-61" => 2, "#icon-22" => 1);

	return $grades["$style_url"];
}

function getCoordinates($coord_string) {
	$parts = explode(',', $coord_string);
	$coordinates = array( "lon" => $parts[0], "lat" => $parts[1] );
	
	return $coordinates;
}

function getQuality($data) {
	$c = determineExplodeChar($data);
	if($c === false) {
		return false;
	}
	$parts = explode($c, $data);
	$quality_string = $parts[count($parts)-2];

	return trim($quality_string, "Q: ");
}

function getPrice($data) {
	$c = determineExplodeChar($data);
	if($c === false) {
		return false;
	}
	$parts = explode($c, $data);
	$quality_string = $parts[count($parts)-1];

	return trim($quality_string, "PL: ");
}

function getDateOfVisit($data) {
	$c = determineExplodeChar($data);
	if($c === false) {
		return false;
	}
	$parts = explode($c, $data);
	$date_string = trim($parts[0]);
	
	return date('Y-m-d', strtotime($date_string));
}

function determineExplodeChar($data)
{
	if(strpos($data, '|') !== false) {
		return '|';
	} elseif(strpos($data, '<br>') !== false) {
		return '<br>';
	} else {
		return false;
	}
}

function updatePizzaDatabase($db, $visits) {
	$locationCount = 0;
	$visitCount = 0;
    foreach($visits as $uniqueVisit => $visit) {
		$existingVisit = getVisit($db, $uniqueVisit);
        if($existingVisit === false) {
            $location = $visit['location'];
			$existingLocation = getLocation($db, $location["unique_location"]);
            if($existingLocation !== false){
				$visit["location"] = $existingLocation["id"];
            } else {
				$newLocationId = insertLocation($db, $location);
				$locationCount++;
				$visit["location"] = $newLocationId;
            }
			$visit["unique_visit"] = $uniqueVisit;
			insertVisit($db, $visit);
			$visitCount++;
        }
    }
	echo "new locations: $locationCount\n";
	echo "new visits: $visitCount\n";
}

function insertVisit($db, $visit) {
	$db->insert('visit', $visit);
	return $db->lastInsertId();
}

function insertLocation($db, $location) {
	$db->insert('location', $location);
	return $db->lastInsertId();
}

function getVisit($db, $visit) {
    $q = "SELECT * FROM visit WHERE unique_visit='$visit'";
    return $db->fetchAssoc($q);
}

function getLocation($db, $location) {
    $q = "SELECT * FROM location WHERE unique_location='$location'";
    return $db->fetchAssoc($q);
}

function getVisits($db) {
	return $db->fetchAll("SELECT * FROM visit");
}

function getLocations($db) {
	return $db->fetchAll("SELECT * FROM location");
}

function deleteVisit($db, $id) {
	return $db->delete('visit', array('id' => $id));
}

function deleteLocation($db, $id) {
	return $db->delete('location', array('id' => $id));
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

function removeOutdatedVisits($db, $visits) {
	$existingVisits = getVisits($db);
	if($existingVisits === false) {
		return;
	}
	$i = 0;
	foreach($existingVisits as $existingVisit) {
		if(!array_key_exists($existingVisit["unique_visit"], $visits)) {
			deleteVisit($db, $existingVisit["id"]);
		}
	}
	echo "removed $i outdated visits from DB.\n";
}

function removeOutdatedLocations($db, $locations) {
	$existingLocations = getLocations($db);
	if($existingLocations === false) {
		return;
	}
	$i = 0;
	foreach($existingLocations as $existingLocation) {
		if(!array_key_exists($existingLocation["unique_location"], $locations)) {
			deleteLocation($db, $existingLocation["id"]);
			$i++;
		}
	}
	echo "removed $i outdated locations from DB.\n";
}
