<?php
require 'vendor/autoload.php';
require_once 'app/Services/RunpodActivePodService.php';
require_once 'app/Services/RunpodGpuMarketplaceService.php';

use App\Services\RunpodActivePodService;

$service = new RunpodActivePodService();
$pod = $service->getOrCreateActivePod();
print_r($pod);
