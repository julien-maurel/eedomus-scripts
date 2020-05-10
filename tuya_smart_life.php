<?
/**
 * Thanks to
 *   https://github.com/sabinus52/TuyaCloudApi
 *   https://github.com/unparagoned/cloudtuya
 * 
 * How to
 * - Required : Create one http controler per device to control your tuya devices
 *    [VAR1] : id of device (go to smart life app, edit device go to device infos, id is value on "virtual id"
 *    Values :
 *      OFF : value 0, url http://localhost/script/?exec=tuya_smart_life.php&actionDeviceId=[VAR1]&actionDeviceValue=0
 *      ON : value 100, url http://localhost/script/?exec=tuya_smart_life.php&actionDeviceId=[VAR1]&actionDeviceValue=1
 * - Optional : Create one http sensor to update status of your controlers if you use smart life app to control your tuya devices too
 *    Url : http://localhost/script/?exec=tuya_smart_life.php&refreshDevices=true
 *    XPath : /root/status
 *    Frequency : as you like
 * - Update personal config below
 *    $authData.countryCode : country calling code from your country (eg. 33 for france)
 *    $authData.bizType : tuya or smart_life
 *    $devicesAssoc (only if you have a http senso) : tuya device id as key, eedomus controler id as value
 */
 
 
/*******************/
/* Personal config */
/*******************/
// Data about authentication
$authData = array(
    'userName'    => 'xxx@xxx.xx',
    'password'    => 'xxxx',
    'countryCode' => '33',
    'bizType'     => 'smart_life',
    'from'        => 'tuya',
);
// Mapping tuya id to eedomus id
$devicesAssoc = array(
    '111222333' => '123',
    '444555666' => '456',
);


/*****************/
/* System config */
/*****************/
$baseUrl = 'https://px1.tuyaeu.com';
$authUrl = '/homeassistant/auth.do';
$refreshUrl = '/homeassistant/access.do';
$skillUrl = '/homeassistant/skill';


/*************/
/* Functions */
/*************/
// Simple json_encode implementation
function sdk_json_encode($data)
{
    $json = '{';
    foreach ($data as $k => $v) {
        if (is_string($v) || is_numeric($v)) {
            $json .= '"'.$k.'":"'.$v.'",';
        } else {
            $json .= '"'.$k.'":'.sdk_json_encode($v).',';
        }
    }
    $json = substr($json, 0, -1).'}';

    return $json;
}

// Authentication to tuya
function sdk_auth($url, $reqData) {
    $response = httpQuery($url, 'POST', http_build_query($reqData));
    
    // Check response
    if (!$response){
        die('ERROR auth response is empty');
    }
    $data = sdk_json_decode($response);
    if (!$data) {
        die('ERROR auth response is not valid json : '.$response);
    }
    if (!isset($data['access_token'])) {
        die('ERROR auth response is not valid : '.$response);
    }
    
    // Add expiration date
    $data['expiration_time'] = time() + $data['expires_in'] - 60;
    
    // Save and return
    saveVariable('token', $data);
    return $data;
}

// Refresh token
function sdk_refresh($url, $token) {
    $reqData = array(
        'grant_type'    => 'refresh_token',
        'refresh_token' => $token['refresh_token'],
    );
    $response = httpQuery($url.'?'.http_build_query($reqData), 'GET');
    
    // Check response
    if (!$response){
        die('ERROR refresh response is empty');
    }
    $data = sdk_json_decode($response);
    if (!$data) {
        die('ERROR refresh response is not valid json : '.$response);
    }
    if (!isset($data['access_token'])) {
        die('ERROR refresh response is not valid : '.$response);
    }
    
    // Add expiration date
    $data['expiration_time'] = time() + $data['expires_in'] - 60;
    
    // Save and return
    saveVariable('token', $data);
    return $data;
}

// Get devices list
function sdk_devices($url, $token) {
    $reqData = array(
        'header' => array(
            'name' => 'Discovery',
            'namespace' => 'discovery',
            'payloadVersion' => 1,
        ),
        'payload' => array(
            'accessToken' => $token['access_token'],
        )
    );
    $response = httpQuery($url, 'POST', sdk_json_encode($reqData), null, array('Content-Type: application/json'));

    // Check response
    if (!$response){
        die('ERROR devices response is empty');
    }
    $data = sdk_json_decode($response);
    if (!$data) {
        die('ERROR devices response is not valid json : '.$response);
    }
    if (!isset($data['payload']['devices'])) {
        die('ERROR devices response is not valid : '.$response);
    }
    
    // Format data
    $devices = array();
    foreach ($data['payload']['devices'] as $device) {
        $devices[$device['id']] = $device;
    }
    
    // Save and return
    saveVariable('devices', $devices);
    return $devices;
}

// Execute an action
function sdk_action($url, $token, $deviceId, $deviceValue) {
    $reqData = array(
        'header' => array(
            'name' => 'turnOnOff',
            'namespace' => 'control',
            'payloadVersion' => 1,
        ),
        'payload' => array(
            'accessToken' => $token['access_token'],
            'devId' => $deviceId,
            'value' => $deviceValue,            
        )
    );
    $response = httpQuery($url, 'POST', sdk_json_encode($reqData), null, array('Content-Type: application/json'));

    // Check response
    if (!$response){
        die('ERROR action response is empty');
    }
    $data = sdk_json_decode($response);
    if (!$data) {
        die('ERROR action response is not valid json : '.$response);
    }
    if (!isset($data['header']['code']) || $data['header']['code'] !== 'SUCCESS') {
        die('ERROR action response is not valid : '.$response);
    }
}


/*************/
/* Execution */
/*************/
// Auth
$token = loadVariable('token');
if (!$token) {
    $token = sdk_auth($baseUrl.$authUrl, $authData);
}
if (time() >= $token['expiration_time']) {
    $token = sdk_refresh($baseUrl.$refreshUrl, $token);
}

// List devices
$refreshDevices = getArg('refreshDevices', false, null);
$devices = loadVariable('devices');
if (!$devices || $refreshDevices) {
    $devices = sdk_devices($baseUrl.$skillUrl, $token);
}

// Actions
$actionDeviceId = getArg('actionDeviceId', false, null);
$actionDeviceValue = getArg('actionDeviceValue', false, null);
// Refresh controlers status
if ($refreshDevices) {
    foreach ($devicesAssoc as $tuya => $eedomus) {
        $value = getValue($eedomus);
        $newValue = $devices[$tuya]['data']['state'] ? 100 : 0;
        if ($value['value'] != $newValue) {
            setValue($eedomus, $newValue, false, true);
        }
    }
}
// Control a device
if ($actionDeviceId !== null && $actionDeviceValue !== null) {
    sdk_action($baseUrl.$skillUrl, $token, $actionDeviceId, $actionDeviceValue);
}

echo '<?xml version="1.0" encoding="ISO-8859-1"?>
    <root><status>OK</status></root>';
?>
