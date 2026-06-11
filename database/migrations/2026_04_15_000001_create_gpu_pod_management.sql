-- Migration: Create active GPU pods table for automatic pod management
-- Run this migration to enable automatic GPU pod provisioning

CREATE TABLE IF NOT EXISTS active_gpu_pods (
    id BIGSERIAL PRIMARY KEY,
    pod_id VARCHAR(255) NOT NULL UNIQUE,
    gpu_name VARCHAR(100) NOT NULL,
    hourly_cost NUMERIC(10, 4) DEFAULT 0.00,
    rented_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    last_activity TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    is_active BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE INDEX idx_active_gpu_pods_is_active ON active_gpu_pods(is_active);
CREATE INDEX idx_active_gpu_pods_last_activity ON active_gpu_pods(last_activity);

-- Add cost tracking table for historical analysis
CREATE TABLE IF NOT EXISTS gpu_pod_usage (
    id BIGSERIAL PRIMARY KEY,
    pod_id VARCHAR(255) NOT NULL,
    gpu_name VARCHAR(100) NOT NULL,
    match_id BIGINT,
    status VARCHAR(50) NOT NULL DEFAULT 'processing', -- processing, completed, failed
    started_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    completed_at TIMESTAMP WITH TIME ZONE,
    duration_minutes INT,
    hourly_rate NUMERIC(10, 4),
    total_cost NUMERIC(10, 4) GENERATED ALWAYS AS (
        CASE 
            WHEN duration_minutes IS NOT NULL 
            THEN ROUND((duration_minutes::numeric / 60) * hourly_rate, 4)
            ELSE NULL
        END
    ) STORED,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE INDEX idx_gpu_pod_usage_pod_id ON gpu_pod_usage(pod_id);
CREATE INDEX idx_gpu_pod_usage_match_id ON gpu_pod_usage(match_id);
CREATE INDEX idx_gpu_pod_usage_status ON gpu_pod_usage(status);
