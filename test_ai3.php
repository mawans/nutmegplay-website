<?php
require_once 'app/bootstrap.php';
use App\Services\VideoAnalysisService;
$service = new VideoAnalysisService();
$service->processMatchVideo(16);
