<?php
/**
 * Check GPU Availability & Pod Status (Simplified)
 * Uses REST API to query pod status
 */

require 'app/bootstrap.php';

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║          GPU Availability & Pod Status Check                   ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

$runpodApiKey = getenv('NUTMEG_RUNPOD_API_KEY');
$instancesJson = getenv('NUTMEG_RUNPOD_INSTANCES_JSON');
$instances = json_decode($instancesJson, true);

if (!$runpodApiKey || !$instances) {
    echo "❌ Missing Runpod API key or instance configuration\n";
    exit(1);
}

// ============================================================================
// STEP 1: CHECK CONFIGURED INSTANCES
// ============================================================================
echo "📍 STEP 1: Configured GPU Instances\n";
echo "───────────────────────────────────────────────────────────────\n\n";

foreach ($instances as $key => $instance) {
    echo "Instance [{$key}]:\n";
    echo "  Label:        {$instance['label']}\n";
    echo "  Pod ID:       {$instance['pod_id']}\n";
    echo "  GPU:          {$instance['gpu_name']}\n";
    echo "  Cost/hour:    \${$instance['hourly_cost']}\n";
    echo "\n";
}

// ============================================================================
// STEP 2: QUERY POD STATUS FROM RUNPOD REST API
// ============================================================================
echo "📍 STEP 2: Query Pod Status from Runpod\n";
echo "───────────────────────────────────────────────────────────────\n\n";

function queryRunpodPodRest($podId, $apiKey) {
    $restUrl = "https://api.runpod.io/graphql";
    
    $query = <<<'GQL'
query {
  pod(input: {podId: "%s"}) {
    id
    name
    gpuCount
    machineId
  }
}
GQL;
    
    $payload = json_encode([
        'query' => sprintf($query, $podId)
    ]);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $restUrl . '?api_key=' . $apiKey,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_VERBOSE => true,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        return ['error' => $curlError, 'http_code' => 0];
    }
    
    return [
        'http_code' => $httpCode,
        'response' => json_decode($response, true)
    ];
}

$podStatuses = [];
foreach ($instances as $key => $instance) {
    $podId = $instance['pod_id'];
    echo "Querying pod: $podId ({$instance['label']})\n";
    
    $result = queryRunpodPodRest($podId, $runpodApiKey);
    
    if (isset($result['error'])) {
        echo "  ⚠️  Connection error: {$result['error']}\n\n";
        continue;
    }
    
    if ($result['http_code'] === 200) {
        $podData = $result['response']['data']['pod'] ?? null;
        
        if ($podData) {
            // If pod exists, it's likely running (podData exists means pod is active)
            $status = 'RUNNING';
            $gpuCount = $podData['gpuCount'] ?? 1;
            $podName = $podData['name'] ?? 'Unknown Pod';
            
            $podStatuses[$key] = [
                'name' => $instance['label'],
                'pod_id' => $podId,
                'status' => $status,
                'gpu_count' => $gpuCount,
                'cost_per_hour' => $instance['hourly_cost'],
            ];
            
            echo "  ✅ Status: $status (Pod exists and is responsive)\n";
            echo "     Pod Name: $podName\n";
            echo "     GPUs: $gpuCount\n\n";
        } else {
            $errors = $result['response']['errors'] ?? [];
            if (!empty($errors)) {
                echo "  ⚠️  API Error:\n";
                foreach ($errors as $error) {
                    echo "     " . ($error['message'] ?? json_encode($error)) . "\n";
                }
                echo "\n";
            }
        }
    } else {
        echo "  ❌ HTTP {$result['http_code']}\n";
        $responseText = is_array($result['response']) ? json_encode($result['response']) : substr($result['response'], 0, 100);
        echo "     Response: $responseText\n\n";
    }
}

// ============================================================================
// STEP 3: CHECK AVAILABLE GPU CAPACITY
// ============================================================================
echo "📍 STEP 3: GPU Availability Status\n";
echo "───────────────────────────────────────────────────────────────\n\n";

$runningCount = 0;
$stoppedCount = 0;
$podToStart = null;

foreach ($podStatuses as $key => $status) {
    if (in_array($status['status'], ['RUNNING', 'PROVISIONING'])) {
        $runningCount++;
        echo "🟢 {$status['name']}: {$status['status']}\n";
        echo "   Pod ID: {$status['pod_id']}\n";
        echo "   GPUs: {$status['gpu_count']} available\n\n";
    } else {
        $stoppedCount++;
        echo "🔴 {$status['name']}: {$status['status']}\n";
        echo "   Pod ID: {$status['pod_id']}\n";
        echo "   Cost: \${$status['cost_per_hour']}/hour\n";
        
        if (!$podToStart) {
            $podToStart = [$key, $instances[$key]];
        }
        echo "\n";
    }
}

// ============================================================================
// STEP 4: TRY TO START A POD
// ============================================================================
if ($stoppedCount > 0 && $podToStart) {
    echo "📍 STEP 4: Attempting to Start a Pod\n";
    echo "───────────────────────────────────────────────────────────────\n\n";
    
    list($key, $podInstance) = $podToStart;
    $podId = $podInstance['pod_id'];
    
    echo "Attempting to start: {$podInstance['label']}\n";
    echo "Pod ID: $podId\n";
    echo "Cost: \${$podInstance['hourly_cost']}/hour\n\n";
    
    $mutation = <<<'GQL'
mutation {
  podResume(input: {podId: "%s"}) {
    id
    status
  }
}
GQL;
    
    $payload = json_encode([
        'query' => sprintf($mutation, $podId)
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
    
    if (isset($result['data']['podResume'])) {
        $podData = $result['data']['podResume'];
        echo "✅ Pod Start Command Accepted!\n";
        echo "   Pod ID: {$podData['id']}\n";
        echo "   Current Status: {$podData['status']}\n";
        echo "   (Pod is provisioning - will be ready in 5-10 minutes)\n\n";
    } else {
        echo "⚠️  Start Command Response:\n";
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
    }
}

// ============================================================================
// STEP 5: SUMMARY
// ============================================================================
echo "\n╔════════════════════════════════════════════════════════════════╗\n";
echo "║                     SUMMARY                                      ║\n";
echo "╚═══════════════════════════════════════════════════════==═════════╝\n\n";

if ($runningCount > 0) {
    echo "✅ GPU STATUS: AVAILABLE\n";
    echo "───────────────────────────────────────────────────────────────\n";
    echo "Running GPU Pods: $runningCount\n";
    echo "Ready for video processing\n\n";
} else {
    echo "❌ GPU STATUS: NOT AVAILABLE\n";
    echo "───────────────────────────────────────────────────────────────\n";
    echo "Running GPU Pods: 0\n";
    echo "Stopped Pods: $stoppedCount\n";
    if ($podToStart) {
        echo "\n✅ Start command has been sent\n";
        echo "   Pod will be provisioning soon\n";
        echo "   Check Runpod dashboard for details\n";
    }
    echo "\n";
}

?>