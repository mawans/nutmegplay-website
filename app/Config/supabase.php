<?php
/**
 * Supabase Configuration
 * 
 * Replace these values with your actual Supabase project credentials.
 * Find them at: https://supabase.com/dashboard/project/YOUR_PROJECT/settings/api
 */
return [
    'url'  => getenv('SUPABASE_URL')
        ?: getenv('SUPABASE_API_EXTERNAL_URL')
        ?: 'http://127.0.0.1:54321',
    'key'  => getenv('SUPABASE_KEY')
        ?: getenv('SUPABASE_ANON_KEY')
        ?: '',
    'service_role_key' => getenv('SUPABASE_SERVICE_ROLE_KEY')
        ?: '',
];
