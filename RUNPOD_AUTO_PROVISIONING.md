# Automatic GPU Pod Provisioning System

> Important: the dynamic `/video-upload` path is now cache-first and template/image-based.
>
> Prefer `NUTMEG_RUNPOD_IMAGE_NAME` (image-based provisioning) for deterministic startup; template fallback is disabled by default. Use `NUTMEG_RUNPOD_ALLOW_TEMPLATE_FALLBACK=1` to re-enable templates.
>
> The old fixed-pod migration flow is only a fallback across preconfigured pod IDs and does not provide global GPU availability.
>
> The old optional GPU-tracking database migration is no longer required for the current dynamic worker flow.

## Overview

Your Nutmeg AI system now has **automatic GPU pod provisioning** that:

1. **Rents the cheapest available GPU** when video processing starts
2. **Tracks costs** for billing/transparency
3. **Auto-terminates dynamic pods** after 30 minutes of inactivity so stopped Pods do not accumulate
4. **Handles pod failures** gracefully (auto-rents replacement)
5. **Shows only cost to client** (hides all technical complexity)

## How It Works

### Architecture Flow

```
Client uploads video
    ↓
VideoAnalysisService checks for active GPU pod
    ↓
If no active pod → RunpodActivePodService.getOrCreateActivePod()
    ↓
RunpodGpuMarketplaceService queries available GPUs
    ↓
Finds cheapest GPU (RTX A5000, RTX 4090, etc.)
    ↓
Automatically rents it for 1 hour via Runpod API
    ↓
Pod ID stored in database (active_gpu_pods table)
    ↓
Video processing starts
    ↓
Pod auto-stops after 30 min inactivity
    ↓
Cost tracked in gpu_pod_usage table
    ↓
Client sees only: "Processing cost: $0.27"
```

## Key Components

### 1. **RunpodGpuMarketplaceService.php**
- Queries Runpod GPU marketplace for available GPUs
- Filters by preferred GPU types (RTX A5000, RTX 4090, etc.)
- Finds cheapest option
- Automatically rents GPUs via API
- Returns new Pod ID

### 2. **RunpodActivePodService.php**
- Manages the currently active GPU pod
- Stores active pod info in database
- Auto-health checks (if pod dies, rents new one)
- Tracks last activity for auto-stop
- Generates cost reports for client

### 3. **Database Tables**
- `active_gpu_pods` - Current active pod info
- `gpu_pod_usage` - Historical usage & cost tracking

## Configuration

### Environment Variables

```env
# Enable automatic provisioning (set to 1)
NUTMEG_RUNPOD_AUTO_PROVISION=1
NUTMEG_RUNPOD_DYNAMIC_ONLY=1

# Runpod API key (required)
NUTMEG_RUNPOD_API_KEY=rpa_YOUR_API_KEY_HERE

# Show costs to client (set to 1 to display, 0 to hide)
NUTMEG_SHOW_GPU_COSTS_TO_CLIENT=1
```

## How to Use

### For Clients (Frontend)

Clients never need to think about GPUs. They just:

1. Upload a video
2. Click "Process"
3. See: `"Processing... Estimated cost: $0.27"`
4. Get results when done

### For Admins (Backend)

#### Check Active Pod & Current Cost

```php
$podService = new RunpodActivePodService($database);
$costSummary = $podService->getCostSummary();

echo json_encode([
    'gpu' => $costSummary['gpu'],  // "RTX A5000"
    'hourly_rate' => $costSummary['hourly_rate'],  // 0.27
    'estimated_cost' => $costSummary['estimated_cost'],  // 0.27 (if 1hr active)
]);
```

#### View Cost History

```sql
-- Total costs by GPU type
SELECT gpu_name, COUNT(*) as jobs, SUM(total_cost) as total_spent
FROM gpu_pod_usage
WHERE status = 'completed'
GROUP BY gpu_name
ORDER BY total_spent DESC;

-- Cost per match
SELECT match_id, gpu_name, total_cost, completed_at
FROM gpu_pod_usage
WHERE status = 'completed'
ORDER BY completed_at DESC
LIMIT 20;
```

## Cost Optimization Features

### ✅ Auto-Stop (Already Implemented)
- Pods automatically stop after 30 minutes of no activity
- Saves money when no videos are being processed

### ✅ Cheapest GPU Selection
- System queries all available GPUs
- Automatically picks the cheapest one
- Preferred list: RTX A5000, RTX 4090, RTX 4080, RTX 4070

### ✅ Per-Video Cost Tracking
- Each video's processing cost is tracked
- Stored in `gpu_pod_usage` table with match_id reference
- Can be billed to clients or absorbed as service cost

## Database Setup

Run this SQL to create the required tables:

```sql
-- Run the migration file:
-- database/migrations/2026_04_15_000001_create_gpu_pod_management.sql
```

Or manually:

```sql
CREATE TABLE active_gpu_pods (
    id BIGSERIAL PRIMARY KEY,
    pod_id VARCHAR(255) NOT NULL UNIQUE,
    gpu_name VARCHAR(100),
    hourly_cost NUMERIC(10, 4),
    rented_at TIMESTAMP WITH TIME ZONE,
    last_activity TIMESTAMP WITH TIME ZONE,
    is_active BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE TABLE gpu_pod_usage (
    id BIGSERIAL PRIMARY KEY,
    pod_id VARCHAR(255),
    gpu_name VARCHAR(100),
    match_id BIGINT,
    status VARCHAR(50),
    started_at TIMESTAMP WITH TIME ZONE,
    completed_at TIMESTAMP WITH TIME ZONE,
    duration_minutes INT,
    hourly_rate NUMERIC(10, 4),
    total_cost NUMERIC(10, 4),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);
```

## Troubleshooting

### "No available GPUs found"
- Runpod's GPU cluster is at capacity
- Check https://www.runpod.io/console/gpu-cloud for availability
- Usually resolves within minutes

### Pod keeps dying
- Check if pod has valid GPU allocated
- Verify Runpod account has sufficient credits
- Check pod health in Runpod dashboard

### Costs seem high
- Review `gpu_pod_usage` table for unusual durations
- Check if auto-stop is working (30 min inactivity timeout)
- Verify GPU type selected is actually the cheapest available

## Future Enhancements

- [ ] Pre-allocate multiple pods (redundancy)
- [ ] Multi-GPU load balancing
- [ ] ML-based cost prediction
- [ ] Custom GPU filtering per client tier
- [ ] Monthly cost caps with alerts
