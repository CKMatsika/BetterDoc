<?php

declare(strict_types=1);

return [
    'air_gapped' => (bool) env('SENTINEL_AIR_GAPPED', true),

    'hosting_mode' => env('SENTINEL_HOSTING_MODE', 'air_gapped_on_prem'),

    'default_profile' => env('SENTINEL_DEFAULT_PROFILE', 'defence'),

    'demo_switcher_enabled' => (bool) env('SENTINEL_DEMO_SWITCHER', env('APP_ENV', 'production') !== 'production'),

    'demo_password' => env('SENTINEL_DEMO_PASSWORD', 'SentinelDemo!2026'),

    'profiles_path' => config_path('profiles'),

    /*
    |--------------------------------------------------------------------------
    | Internal Service Allowlist (Enforced in Air-Gapped Mode)
    |--------------------------------------------------------------------------
    */
    'internal_allowlist_hosts' => array_filter(array_map(
        'trim',
        explode(',', (string) env('SENTINEL_INTERNAL_HOSTS', '127.0.0.1,localhost,postgres,valkey,meilisearch,minio,clamav,sentinel-signer,sentinel-pki,sentinel-ocr,ollama,collabora,gotenberg,cups,reverb'))
    )),

    'external_allowlist_hosts' => array_filter(array_map(
        'trim',
        explode(',', (string) env('SENTINEL_EXTERNAL_ALLOWLIST', ''))
    )),

    /*
    |--------------------------------------------------------------------------
    | Security & Session Controls
    |--------------------------------------------------------------------------
    */
    'security' => [
        'max_failed_logins' => (int) env('SENTINEL_MAX_FAILED_LOGINS', 5),
        'lockout_minutes' => (int) env('SENTINEL_LOCKOUT_MINUTES', 15),
        'step_up_mfa_ttl_minutes' => (int) env('SENTINEL_STEP_UP_TTL', 5),
        'two_person_ttl_hours' => (int) env('SENTINEL_TWO_PERSON_TTL_HOURS', 24),
        'break_glass_max_hours' => (int) env('SENTINEL_BREAK_GLASS_MAX_HOURS', 4),
        'hsm_master_key_id' => env('SENTINEL_KEK_ID', 'sentinel-kek-v1'),
        'master_kek_b64' => env('SENTINEL_MASTER_KEK_B64', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ingestion, Malware (ClamAV), CDR, OCR & Search Drivers
    |--------------------------------------------------------------------------
    */
    'storage_driver' => env('SENTINEL_STORAGE_DRIVER', 'local'),

    'upload' => [
        'chunk_size_bytes' => (int) env('SENTINEL_UPLOAD_CHUNK_BYTES', 5 * 1024 * 1024),
        'max_size_bytes' => (int) env('SENTINEL_UPLOAD_MAX_BYTES', 2 * 1024 * 1024 * 1024),
        'session_ttl_hours' => (int) env('SENTINEL_UPLOAD_TTL_HOURS', 48),
    ],

    'clamav' => [
        'enabled' => (bool) env('SENTINEL_CLAMAV_ENABLED', false),
        'host' => env('SENTINEL_CLAMAV_HOST', 'clamav'),
        'port' => (int) env('SENTINEL_CLAMAV_PORT', 3310),
        'fail_closed' => (bool) env('SENTINEL_CLAMAV_FAIL_CLOSED', true),
    ],

    'ocr' => [
        'enabled' => (bool) env('SENTINEL_OCR_ENABLED', false),
        'url' => env('SENTINEL_OCR_URL', 'http://sentinel-ocr:8002'),
        'languages' => env('SENTINEL_OCR_LANGUAGES', 'eng'),
    ],

    'gotenberg' => [
        'enabled' => (bool) env('SENTINEL_GOTENBERG_ENABLED', false),
        'url' => env('SENTINEL_GOTENBERG_URL', 'http://gotenberg:3000'),
    ],

    'search' => [
        'driver' => env('SENTINEL_SEARCH_DRIVER', 'postgres'),
        'meilisearch_host' => env('MEILISEARCH_HOST', 'http://meilisearch:7700'),
        'meilisearch_key' => env('MEILI_MASTER_KEY', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Internal PKI, HSM Custody & PAdES Signer Service
    |--------------------------------------------------------------------------
    */
    'pki' => [
        'hsm_driver' => env('SENTINEL_HSM_DRIVER', 'sealed_file'), // sealed_file | pkcs11
        'hsm_path' => env('SENTINEL_HSM_PATH', storage_path('app/hsm')),
        'signer_driver' => env('SENTINEL_SIGNER_DRIVER', 'local_pyhanko'), // local_pyhanko | http
        'signer_url' => env('SENTINEL_SIGNER_URL', 'http://sentinel-signer:8003'),
        'pki_url' => env('SENTINEL_PKI_URL', 'http://sentinel-pki:8004'),
        'python_bin' => env('SENTINEL_SIGNER_PYTHON', PHP_OS_FAMILY === 'Windows'
            ? base_path('services/sentinel-signer/.venv/Scripts/python.exe')
            : base_path('services/sentinel-signer/.venv/bin/python')),
        'pkcs11_library' => env('SENTINEL_PKCS11_LIB', '/usr/lib/softhsm/libsofthsm2.so'),
        'pkcs11_slot' => env('SENTINEL_PKCS11_SLOT', '0'),
        'require_fresh_mfa_for_pades' => (bool) env('SENTINEL_PADES_REQUIRE_FRESH_MFA', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Offline Office Co-Editing (Collabora Online / WOPI) & Workflows
    |--------------------------------------------------------------------------
    */
    'collabora' => [
        'enabled' => (bool) env('SENTINEL_COLLABORA_ENABLED', false),
        'url' => env('SENTINEL_COLLABORA_URL', 'http://collabora:9980'),
        'wopi_base_url' => env('SENTINEL_WOPI_BASE_URL', env('APP_URL', 'http://127.0.0.1:8000')),
        'token_ttl_minutes' => (int) env('SENTINEL_WOPI_TOKEN_TTL_MINUTES', 120),
        'bind_client_ip' => (bool) env('SENTINEL_WOPI_BIND_IP', false),
    ],

    'workflows' => [
        'default_sla_hours' => (int) env('SENTINEL_WORKFLOW_SLA_HOURS', 48),
    ],

    /*
    |--------------------------------------------------------------------------
    | Local-First AI, IDP, Pre-Filtered Hybrid RAG & Anomaly Detection
    |--------------------------------------------------------------------------
    */
    'ai' => [
        'driver' => env('SENTINEL_AI_DRIVER', 'local_hybrid'), // local_hybrid | ollama | cloud_openai
        'ollama_url' => env('SENTINEL_OLLAMA_URL', 'http://ollama:11434'),
        'chat_model' => env('SENTINEL_AI_CHAT_MODEL', 'qwen2.5:7b-instruct'),
        'embed_model' => env('SENTINEL_AI_EMBED_MODEL', 'bge-m3'),
        'vision_model' => env('SENTINEL_AI_VISION_MODEL', 'qwen2.5vl'),
        'translation_model' => env('SENTINEL_AI_TRANSLATION_MODEL', 'nllb-200'),
        'cloud_ai_enabled' => (bool) env('SENTINEL_CLOUD_AI_ENABLED', false),
        'cloud_endpoint' => env('SENTINEL_CLOUD_AI_ENDPOINT', ''),
        'embedding_dims' => (int) env('SENTINEL_AI_EMBED_DIMS', 128),
        'anomaly' => [
            'bulk_download_threshold' => (int) env('SENTINEL_ANOMALY_BULK_DOWNLOADS', 5),
            'bulk_view_threshold' => (int) env('SENTINEL_ANOMALY_BULK_VIEWS', 25),
            'window_minutes' => (int) env('SENTINEL_ANOMALY_WINDOW_MINUTES', 15),
            'business_hours_start' => env('SENTINEL_BUSINESS_HOURS_START', '06:00'),
            'business_hours_end' => env('SENTINEL_BUSINESS_HOURS_END', '19:00'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Printing (CUPS/IPPS, Secure PIN/RFID Release), Scanning & Local Agent
    |--------------------------------------------------------------------------
    */
    'print_scan' => [
        'print_driver' => env('SENTINEL_PRINT_DRIVER', 'local_spool'), // local_spool | cups_ipps
        'cups_url' => env('SENTINEL_CUPS_URL', 'http://cups:631'),
        'max_pin_attempts' => (int) env('SENTINEL_PRINT_MAX_PIN_ATTEMPTS', 3),
        'pin_ttl_hours' => (int) env('SENTINEL_PRINT_PIN_TTL_HOURS', 24),
        'hot_folder_root' => env('SENTINEL_HOT_FOLDER_ROOT', storage_path('app/scan_hotfolders')),
        'mail_in_root' => env('SENTINEL_MAIL_IN_ROOT', storage_path('app/mail_in')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Optional LDAP / Active Directory / FreeIPA Connector
    |--------------------------------------------------------------------------
    */
    'ldap' => [
        'enabled' => (bool) env('SENTINEL_LDAP_ENABLED', false),
        'host' => env('SENTINEL_LDAP_HOST', ''),
        'port' => (int) env('SENTINEL_LDAP_PORT', 636),
        'use_ldaps' => (bool) env('SENTINEL_LDAP_LDAPS', true),
        'base_dn' => env('SENTINEL_LDAP_BASE_DN', ''),
        'bind_dn' => env('SENTINEL_LDAP_BIND_DN', ''),
        'bind_password' => env('SENTINEL_LDAP_BIND_PASSWORD', ''),
    ],
];
