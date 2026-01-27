<?php
$url = 'http://127.0.0.1:8000/route';
$data = json_encode(['query' => 'test', 'max_routes' => 1]);
echo "Testing $url...\n";
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$resp = curl_exec($ch);
$err = curl_error($ch);
$info = curl_getinfo($ch);
curl_close($ch);

echo "Status Code: " . $info['http_code'] . "\n";
echo "Response: $resp\n";
if ($err)
    echo "Curl Error: $err\n";
