<?php

function getLocation() {
    $ip = $_SERVER['REMOTE_ADDR'];
    $url = "http://ip-api.com/json/{$ip}";
    $response = query($url);
    $location = json_decode($response, true);

    if (isset($location['city']) && $location['city'] === 'Nancy') {
        $lat = $location['lat'];
        $long = $location['lon'];
        return [$long, $lat];
    } else {
        return getPosition('IUT nancy charlemagne');
    }
}

function getWeather($lat, $long) {
    $url = "https://www.infoclimat.fr/public-api/gfs/xml?_ll={$lat},{$long}&_auth=ARsDFFIsBCZRfFtsD3lSe1Q8ADUPeVRzBHgFZgtuAH1UMQNgUTNcPlU5VClSfVZkUn8AYVxmVW0Eb1I2WylSLgFgA25SNwRuUT1bPw83UnlUeAB9DzFUcwR4BWMLYwBhVCkDb1EzXCBVOFQoUmNWZlJnAH9cfFVsBGRSPVs1UjEBZwNkUjIEYVE6WyYPIFJjVGUAZg9mVD4EbwVhCzMAMFQzA2JRMlw5VThUKFJiVmtSZQBpXGtVbwRlUjVbKVIuARsDFFIsBCZRfFtsD3lSe1QyAD4PZA%3D%3D&_c=19f3aa7d766b6ba91191c8be71dd1ab2";
    $response = query($url);
    if (empty($response)) {
        throw new Exception("La requête météo a échoué ou a renvoyé une réponse vide.");
    }
    return $response;
}

function getTraffic() {
    $url = "https://carto.g-ny.org/data/cifs/cifs_waze_v2.json";
    $response = query($url);
    $trafficData = json_decode($response, true);

    if (!isset($trafficData['incidents'])) {
        throw new Exception("Impossible de récupérer les données de trafic.");
    }
    return $trafficData;
}

function transformXmlWithXsl($xmlc, $xslc) {
    if (empty($xmlc)) {
        throw new Exception("Le XML à transformer est vide.");
    }

    $xml = new DOMDocument;
    $xml->loadXML($xmlc);
    $xsl = new DOMDocument;
    $xsl->load($xslc);
    $xslt = new XSLTProcessor;
    $xslt->importStyleSheet($xsl);

    return $xslt->transformToXML($xml);
}

function getPosition($loc) {
    $query = str_replace(' ', '+', $loc);
    $url = "https://api-adresse.data.gouv.fr/search/?q={$query}";
    $response = query($url);
    $data = json_decode($response, true);

    if (!isset($data["features"][0]['geometry']['coordinates'])) {
        throw new Exception("Impossible de récupérer les coordonnées pour l'emplacement : {$loc}");
    }

    return $data["features"][0]['geometry']['coordinates'];
}

function getPollution($city) {
    $url = "https://services3.arcgis.com/Is0UwT37raQYl9Jj/arcgis/rest/services/ind_grandest/FeatureServer/0/query?where=lib_zone%3D%27Nancy%27&objectIds=&time=&geometry=&geometryType=esriGeometryEnvelope&inSR=&spatialRel=esriSpatialRelIntersects&resultType=none&distance=0.0&units=esriSRUnit_Meter&returnGeodetic=false&outFields=*&returnGeometry=true&featureEncoding=esriDefault&multipatchOption=xyFootprint&maxAllowableOffset=&geometryPrecision=&outSR=&datumTransformation=&applyVCSProjection=false&returnIdsOnly=false&returnUniqueIdsOnly=false&returnCountOnly=false&returnExtentOnly=false&returnQueryGeometry=false&returnDistinctValues=false&cacheHint=false&orderByFields=&groupByFieldsForStatistics=&outStatistics=&having=&resultOffset=&resultRecordCount=&returnZ=false&returnM=false&returnExceededLimitFeatures=true&quantizationParameters=&sqlFormat=none&f=pjson&token=";

    $response = query($url);
    $data = json_decode($response, true);

    if (!isset($data["features"])) {
        throw new Exception("Les données de pollution sont indisponibles ou la réponse API est invalide.");
    }

    $latestFeature = null;
    $today = (new DateTime())->format('Y-m-d');

    foreach ($data["features"] as $feature) {
        if (isset($feature["attributes"]["date_ech"], $feature["attributes"]["lib_zone"])) {
            $timestamp = $feature["attributes"]["date_ech"] / 1000;
            $date = (new DateTime("@$timestamp"))->format('Y-m-d');

            if ($feature["attributes"]["lib_zone"] == $city && $date == $today) {
                $latestFeature = $feature;
                break;
            }
        } else {
            error_log("Attributs manquants dans : " . print_r($feature, true));
        }
    }

    if ($latestFeature === null) {
        return [
            'coul_qual' => '#808080',
            'lib_qual' => 'Données indisponibles'
        ];
    }

    return $latestFeature['attributes'];
}


function query($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_PROXY, 'www-cache:3128');
    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        throw new Exception('Erreur CURL : ' . curl_error($ch) . ", sur l\'url : {$url}" );
    }

    curl_close($ch);

    if (empty($response)) {
        throw new Exception("La requête vers {$url} n'a renvoyé aucune donnée.");
    }

    return $response;
}

try {
    $traffic = getTraffic();
    $trafficJs = json_encode($traffic['incidents']);
    $ip = getLocation();
    $long = $ip[0];
    $lat = $ip[1];
    $weather = getWeather($lat, $long);
    $html = transformXmlWithXsl($weather, './meteo.xsl');
    $pollution = getPollution('Nancy');
    $iutncCoord = getPosition('IUT nancy charlemagne');
    $iutncLong = $iutncCoord[0];
    $iutncLat = $iutncCoord[1];
} catch (Exception $e) {
    die("Erreur : " . $e->getMessage());
}

echo <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Interopabilité</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
    <link rel="stylesheet" href="./styles.css">
</head>
<body>
    <div class="info-weather-container">
        $html
        <h2 id="pollution">Air : {$pollution['lib_qual']}</h2>
    </div>
    <div id="map-container">
        <div id="map"></div>
    </div>
    <div class="popup-container hide">
        <div class="popup">
            <div class="github"><i class="fa-brands fa-github"></i> <p>Atmosphère : <a href="https://github.com/Okiles/atmosphere" target="_blank">https://github.com/Okiles/atmosphere</a></p></div>
            <div class="info"><p>Ip location : </p><a href="http://ip-api.com/json/{$_SERVER['REMOTE_ADDR']}">http://ip-api.com/json/{$_SERVER['REMOTE_ADDR']}</a></div>
            <div class="info"><p>Pollution : </p><a href="https://services3.arcgis.com/Is0UwT37raQYl9Jj/arcgis/rest/services/ind_grandest/FeatureServer/0/query?where=lib_zone%3D%27Nancy%27&objectIds=&time=&geometry=&geometryType=esriGeometryEnvelope&inSR=&spatialRel=esriSpatialRelIntersects&resultType=none&distance=0.0&units=esriSRUnit_Meter&returnGeodetic=false&outFields=*&returnGeometry=true&featureEncoding=esriDefault&multipatchOption=xyFootprint&maxAllowableOffset=&geometryPrecision=&outSR=&datumTransformation=&applyVCSProjection=false&returnIdsOnly=false&returnUniqueIdsOnly=false&returnCountOnly=false&returnExtentOnly=false&returnQueryGeometry=false&returnDistinctValues=false&cacheHint=false&orderByFields=&groupByFieldsForStatistics=&outStatistics=&having=&resultOffset=&resultRecordCount=&returnZ=false&returnM=false&returnExceededLimitFeatures=true&quantizationParameters=&sqlFormat=none&f=pjson&token=">https://services3.arcgis.com/Is0UwT37raQYl9Jj/arcgis/rest/services/ind_grandest/FeatureServer/0/</a></div>   
            <div class="info"><p>Weather : </p><a href="https://www.infoclimat.fr/public-api/gfs/xml?_ll={$lat},{$long}&_auth=ARsDFFIsBCZRfFtsD3lSe1Q8ADUPeVRzBHgFZgtuAH1UMQNgUTNcPlU5VClSfVZkUn8AYVxmVW0Eb1I2WylSLgFgA25SNwRuUT1bPw83UnlUeAB9DzFUcwR4BWMLYwBhVCkDb1EzXCBVOFQoUmNWZlJnAH9cfFVsBGRSPVs1UjEBZwNkUjIEYVE6WyYPIFJjVGUAZg9mVD4EbwVhCzMAMFQzA2JRMlw5VThUKFJiVmtSZQBpXGtVbwRlUjVbKVIuARsDFFIsBCZRfFtsD3lSe1QyAD4PZA%3D%3D&_c=19f3aa7d766b6ba91191c8be71dd1ab2">https://www.infoclimat.fr/public-api/gfs/</a></div>
            <div class="btn-close" id="close-popup">Fermer</div> 
        </div>
    </div>
    <div id="btn-show-popup"><i class="fa-solid fa-circle-info"></i> Api et Github</div>
    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
    <script>        
        var map = L.map('map-container').setView([$lat, $long], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);

        var trafficDt = $trafficJs;
        trafficDt.forEach(function(incident) {
            var coord = incident.location.polyline.split(' ');
            var lat = parseFloat(coord[0]);
            var long = parseFloat(coord[1]);
            L.marker([lat, long]).addTo(map)
                .bindPopup('<b>' + incident.short_description + '</b><br>' + incident.location.location_description + '<br> Date de début : ' + incident.starttime + '<br>Date de fin : ' + incident.endtime);
        });

        L.marker([$iutncLat, $iutncLong]).addTo(map)
                .bindPopup('<b>IUT Nancy-Charlemagne</b>');
        
                let btn = document.getElementById("close-popup");
        btn.addEventListener("click", () => {
            document.querySelectorAll('.popup-container').forEach(element => {
                element.classList.add('hide');
            });
        });
        let btnShow = document.getElementById("btn-show-popup");
        btnShow.addEventListener("click", () => {
            document.querySelectorAll('.popup-container').forEach(element => {
                element.classList.remove('hide');
            });
        });
    </script>
</body>
</html>
HTML;
