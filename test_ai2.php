<?php
require_once 'app/bootstrap.php';
use App\Services\RunpodActivePodService;
$service = new RunpodActivePodService();
$pod = $service->getOrCreateActivePod();
print_r($pod);
