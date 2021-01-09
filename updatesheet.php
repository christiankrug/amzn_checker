<?php

/*
 * amzn_checker
 * Christian Krug, 2021
 */


// Load config
$configPath = "config.json";
$config = loadConfig($configPath);
$debug = $config["debug"];
$baseSheetURL = $config["base_sheet_url"];
$amazonAvailabilityFieldID = $config["amazon_availability_field"];
$gsaCredPath = $config["gsa_credentials_file"];
$credConfig = loadGSAConfig($gsaCredPath);


// Function that pulls data from an API and returns a JSON Object
function pullAPIData($path, $bearerToken) {
    $options = array('http' => array(
        'method'  => 'GET',
        'header' => 'Authorization: Bearer '.$bearerToken
    ));
    $context  = stream_context_create($options);
    $data = file_get_contents($path, false, $context);
    if (getHttpCode($http_response_header) != 200) {
        die("An error occured while contacting the Google Sheets API, Error ".getHttpCode($http_response_header));
    }
    return json_decode($data);
}

// Function to update data to an API
function updateAPIData($path, $bearerToken, $data) {
    global $debug;
    $path .= "?valueInputOption=USER_ENTERED";
    $postdata = json_encode(array("values"=>$data));
    if($debug) flushEcho("Updating a batch of rows to the spreadsheet.\n");
    $opts = array('http' =>
        array(
            'method'  => 'PUT',
            'header'  => array('Content-Type: application/json',
                'Authorization: Bearer '.$bearerToken),
            'content' => $postdata
        )
    );

    $context  = stream_context_create($opts);

    $result = file_get_contents($path, false, $context);

    if ($result === false) {
        die ("An error occured while updating data to the Google Sheets API.");
    }
}

// Function that extracts the HTTP Response Code from the HTTP Response Header
function getHttpCode($http_response_header)
{
    if(is_array($http_response_header))
    {
        $parts=explode(' ',$http_response_header[0]);
        if(count($parts)>1) //HTTP/1.0 <code> <text>
            return intval($parts[1]); //Get code
    }
    return 0;
}

// Function that pulls HTML code from the Amazon website
function pullAmazonData($path) {
    $data = file_get_contents($path);
    if (getHttpCode($http_response_header) != 200) {
        die("An error occured while contacting the Amazon website, Error ".getHttpCode($http_response_header));
    }
    return $data;
}

// Get product availability from Amazon HTML code
function parseAmazonData($htmlCode) {
    $doc = new DOMDocument();
    @$doc->loadHTML($htmlCode);
    removeScriptTags($doc);
    if($doc->getElementById("availability") == null || $doc->getElementById("availability")->getElementsByTagName("span")[0] == null) return "Fehler";
    return trim($doc->getElementById("availability")->getElementsByTagName("span")[0]->textContent);
}

function removeScriptTags(&$dom) {
    while (($r = $dom->getElementsByTagName("script")) && $r->length) {
        $r->item(0)->parentNode->removeChild($r->item(0));
    }
    $dom->saveHTML();
}

// Function that processes a single row of the spreadsheet
function updateSingleRow($rowArray) {
    global $debug, $config;
    if (isset($rowArray[$config["sheet_structure"]["MAIN_LINK_FIELD"]]) && !empty($rowArray[$config["sheet_structure"]["MAIN_LINK_FIELD"]])) {
        $status = parseAmazonData(pullAmazonData($rowArray[$config["sheet_structure"]["MAIN_LINK_FIELD"]]));
        if($debug) flushEcho("Status: ".$status);
        $rowArray[$config["sheet_structure"]["MAIN_STATUS_FIELD"]] = $status;
        // Sleep something between 1-2 secs
        sleep((rand(10, 20) / 10));
    }
    if (isset($rowArray[$config["sheet_structure"]["ALTERNATIVE_LINK_FIELD"]]) && !empty($rowArray[$config["sheet_structure"]["ALTERNATIVE_LINK_FIELD"]])) {
        $status = parseAmazonData(pullAmazonData($rowArray[$config["sheet_structure"]["ALTERNATIVE_LINK_FIELD"]]));
        if ($debug) flushEcho("Status: ".$status);
        $rowArray[$config["sheet_structure"]["ALTERNATIVE_STATUS_FIELD"]] = $status;
        // Sleep something between 1-2 secs
        sleep((rand(10, 20) / 10));
    }
    return $rowArray;
}

// Function to process an entire sheet of Amazon product links
function updateSheet($sheetID) {
    global $baseSheetURL, $debug;
    $bearerToken = getAuthBearerToken();
    $generalInfo = pullAPIData($baseSheetURL.$sheetID, $bearerToken);
    flushEcho(" -- Updating Sheet: ".$generalInfo->properties->title." -- \n");
    $rowCount = $generalInfo->sheets[0]->properties->gridProperties->rowCount;
    if ($debug) flushEcho("Found ".$rowCount." rows we will look through.\n");
    $firstBatch = true;
    for ($i = 100; $i <= $rowCount; $i += 100) {
        if($debug) flushEcho("Going for the batch of rows ".($i-99)."-".$i.".\n");
        $batchData = pullAPIData($baseSheetURL.$sheetID."/values/A".($i-99).":Z".$i."", $bearerToken);
        if (isset($batchData->values)) {
            $batchRowData = $batchData->values;
            if ($firstBatch) array_shift($batchRowData);
            foreach ($batchRowData as $key => &$row) {
                if ($debug) flushEcho("\n Working on row ".($i-99+$key)."\n");
                $row = updateSingleRow($row, $batchRowData);
            }
            if (!$firstBatch) {
                updateAPIData($baseSheetURL.$sheetID."/values/A".($i-99).":Z".$i."", $bearerToken, $batchRowData);
            } else {
                updateAPIData($baseSheetURL.$sheetID."/values/A".($i-98).":Z".$i."", $bearerToken, $batchRowData);
                $firstBatch = false;
            }
        }
    }
    flushEcho(" -- Done With Sheet: ".$generalInfo->properties->title." -- \n");
}

// Function to encode in base64 url format
function base64_url_encode($input) {
    return str_replace('=', '', strtr(base64_encode($input), '+/', '-_'));
}

// Function to echo text and flush afterwards
function flushEcho($msg) {
    echo $msg;
    @ob_flush();
    flush();
}

// Function to get Auth token from Google OAuth2 API
function getAuthBearerToken() {
    global $credConfig;
    $url = "https://oauth2.googleapis.com/token";
    $scope = 'https://www.googleapis.com/auth/spreadsheets';

    $client_id = $credConfig["client_email"];

    $iat = time();
    $jwt_data = array(
        'iss' => $client_id,
        'aud' => $url,
        'scope' => $scope,
        'exp' => $iat + 3600,
        'iat' => $iat,
    );

    $header = array('typ' => 'JWT', 'alg' => 'RS256');
    $signing_input = base64_url_encode(json_encode($header)) . '.' . base64_url_encode(json_encode($jwt_data));
    openssl_sign($signing_input, $signature, $credConfig["private_key"], 'SHA256');
    $jwt = $signing_input . '.' . base64_url_encode($signature);

    $data = array(
        "grant_type" => "urn:ietf:params:oauth:grant-type:jwt-bearer",
        "assertion" => $jwt
    );

    $postdata = http_build_query($data);
    $opts = array('http' =>
        array(
            'method'  => 'POST',
            'header'  => 'Content-Type: application/x-www-form-urlencoded',
            'content' => $postdata
        )
    );

    $context  = stream_context_create($opts);

    $result = file_get_contents($url, false, $context);
    $bearerArray = json_decode($result);
    return $bearerArray->access_token;

}

// Function to load config for this script
function loadConfig($configPath) {
    if(!file_exists($configPath)) {
        die("Error: The specified config file under ".$configPath." could not be found.");
    }
    $configData = file_get_contents($configPath);
    return json_decode($configData, true);
}

// Function to load credentials file for a Google Service account
function loadGSAConfig($path) {
    if(!file_exists($path)) {
        die("Error: The specified credentials file under ".$path." could not be found.");
    }
    $data = file_get_contents($path);
    return json_decode($data, true);
}

// Update the main sheet
updateSheet($config["sheet_id"]);
