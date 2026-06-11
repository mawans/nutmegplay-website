<?php
require_once __DIR__ . '/../app/bootstrap.php';

$apiKey = getenv('NUTMEG_RUNPOD_API_KEY');
if (!$apiKey) {
    echo "No API key found in env.\n";
    exit(1);
}

$query = '
query IntrospectionQuery {
  __schema {
    mutationType {
      fields {
        name
      }
    }
  }
}';

$ch = curl_init('https://api.runpod.io/graphql?api_key=' . urlencode($apiKey));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode(['query' => $query]),
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
]);

$response = curl_exec($ch);
$decoded = json_decode($response, true);

if (empty($decoded['data']['__schema']['mutationType']['fields'])) {
    echo "Failed to fetch mutations. Response:\n";
    print_r($decoded);
    exit(1);
}

$names = array_map(fn($f) => $f['name'], $decoded['data']['__schema']['mutationType']['fields']);
sort($names);

echo "Available mutations:\n";
foreach ($names as $name) {
    if (stripos($name, 'pod') !== false || stripos($name, 'migrate') !== false || stripos($name, 'relocate') !== false) {
        echo "- $name\n";
    }
}
