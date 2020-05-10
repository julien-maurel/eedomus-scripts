<?php
$login = 'xxx@xxx.xx';
$pass = 'xxx';
$instantId = 123;
$dayId = 456;
$totalId = 789;

// Login
$info = array();
$data = http_build_query(array(
    'email' => $login,
    'password' => $pass,
));
$resultString = httpQuery(
    'https://mydeltasolar.deltaww.com/includes/process_login.php',
    'POST',
    $data,
    null,
    array(
        'Content-Length: '.strlen($data),
        'Content-Type: application/x-www-form-urlencoded',
    ),
    false,
    false,
    $info
);
if (!$resultString) {
    die('ERROR auth response is empty');
}
$result = sdk_json_decode($resultString);
if ($result['errmsg']) {
    die('ERROR auth response is not valid : '.$result['errmsg']);
}
$session = preg_replace('#.+sec_session_id.+Set-Cookie: sec_session_id=([a-zA-Z0-9]+).+#s', '$1', $info['header']);

// Get total/day data
$resultString = httpQuery(
    'https://mydeltasolar.deltaww.com/includes/process_gtop.php',
    'GET',
    null,
    null,
    array(
        'Cookie: sec_session_id='.$session
    )
);
$result = sdk_json_decode($resultString);
if (!$result || !isset($result['te'][0]) || !isset($result['le'][0])) {
    die('ERROR data1 json is not valid : '.$resultString);
}
$dayValue = round($result['te'][0] / 1000, 2);
$totalValue = round(($result['le'][0] + $result['te'][0]) / 1000, 2);

// Get instant data
$data = http_build_query(array('unit'=>'day'));
$resultString = httpQuery(
    'https://mydeltasolar.deltaww.com/includes/process_gtop_plot.php',
    'POST',
    $data,
    null,
    array(
        'Cookie: sec_session_id='.$session,
        'Content-Length: '.strlen($data),
        'Content-Type: application/x-www-form-urlencoded',
    )
);
$result = sdk_json_decode($resultString);
if (!$result || !isset($result['top'][0])) {
    die('ERROR data2 json is not valid : '.$resultString);
}
$instantValue = $result['top'][count($result['top']) - 1];

// Store new values
setValue($instantId, $instantValue);
setValue($dayId, $dayValue);
setValue($totalId, $totalValue);

// Cache (not useful for the moment)
$cache = $dayValue.' / '.$totalValue;

// Response
echo '<?xml version="1.0" encoding="ISO-8859-1"?><root><cache>'.$cache.'</cache><instant>'.$instantValue.'</instant><day>'.$dayValue.'</day><total>'.$totalValue.'</total></root>';
