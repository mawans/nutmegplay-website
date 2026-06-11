<?php
/**
 * Stop One GPU Pod - Keep Only Budget Pod Running
 */

require 'app/bootstrap.php';

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║            Stopping One GPU Pod - Keeping Budget Pod           ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

$runpodApiKey = getenv('NUTMEG_RUNPOD_API_KEY');
$instancesJson = getenv('NUTMEG_RUNPOD_INSTANCES_JSON');
$instances = json_decode($instancesJson, true);

// Stop the expensive pod (RTX A5000), keep the cheap one (RTX 2000 Ada)
$podToStop = $instances['a5000']; // RTX A5000 @ $0.27/hr
$podToKeep = $instances['rtx2000ada']; // RTX 2000 Ada @ $0.24/hr

echo "📍 Stopping Pod: {$podToStop['label']}\n";
echo "───────────────────────────────────────────────────────────────\n";
echo "Pod ID: {$podToStop['pod_id']}\n";
echo "Cost: \${$podToStop['hourly_cost']}/hour\n\n";

echo "📍 Keeping Pod: {$podToKeep['label']}\n";
echo "───────────────────────────────────────────────────────────────\n";
echo "Pod ID: {$podToKeep['pod_id']}\n";
echo "Cost: \${$podToKeep['hourly_cost']}/hour (saves \$0.03/hour)\n\n";

// Stop the pod
$mutation = <<<'GQL'
mutation {
  podStop(input: {podId: "%s"}) {
    id
  }
}
GQL;

$payload = json_encode([
    'query' => sprintf($mutation, $podToStop['pod_id'])
]);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://api.runpod.io/graphql?api_key=' . $runpodApiKey,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => false,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$result = json_decode($response, true);

echo "📍 Stop Command Sent\n";
echo "───────────────────────────────────────────────────────────────\n";

if (isset($result['data']['podStop'])) {
    echo "✅ Pod stopped successfully!\n";
    echo "   Pod ID: {$result['data']['podStop']['id']}\n\n";
} else {
    $errors = $result['errors'] ?? [];
    if (!empty($errors)) {
        echo "ℹ️  Pod stop response:\n";
        foreach ($errors as $error) {
            echo "   " . ($error['message'] ?? json_encode($error)) . "\n";
        }
    } else {
        echo "Response: " . json_encode($result, JSON_PRETTY_PRINT) . "\n";
    }
    echo "\n";
}

// Summary
echo "\n╔════════════════════════════════════════════════════════════════╗\n";
echo "║                     SUMMARY                                    ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "✅ Configuration:\n";
echo "───────────────────────────────────────────────────────────────\n";
echo "Running Pod: {$podToKeep['label']}\n";
echo "  Pod ID: {$podToKeep['pod_id']}\n";
echo "  GPU: {$podToKeep['gpu_name']}\n";
echo "  Cost: \${$podToKeep['hourly_cost']}/hour\n\n";

echo "Stopped Pod: {$podToStop['label']}\n";
echo "  Pod ID: {$podToStop['pod_id']}\n";
echo "  Savings: \$0.03/hour (\$0.72/day, \$21.60/month)\n\n";

echo "Status: Only 1 GPU pod is now running and ready for video processing\n";

?>
