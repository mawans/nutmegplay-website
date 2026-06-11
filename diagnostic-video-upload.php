<?php
require_once 'app/bootstrap.php';

try {
    $videoController = new \App\Controllers\VideoController();
    $reflection = new ReflectionClass($videoController);
    $method = $reflection->getMethod('videoUpload');
    
    // Simulate the controller setup
    $videoController->videoUpload();
    
    echo "✓ Controller executed successfully\n";
    
} catch (\Throwable $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
?>
