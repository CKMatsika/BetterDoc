<?php

declare(strict_types=1);

return [
    'air_gapped' => (bool) env('BETTERDOC_AIR_GAPPED', true),

    'hosting_mode' => env('BETTERDOC_HOSTING_MODE', 'air_gapped_on_prem'),

    'landing_mode' => env('LANDING_MODE', 'portal'), // portal | demo | off

    'default_profile' => env('BETTERDOC_DEFAULT_PROFILE', 'defence'),

    'demo_switcher_enabled' => (bool) env('BETTERDOC_DEMO_SWITCHER', false),

    'self_registration_enabled' => (bool) env('BETTERDOC_SELF_REGISTRATION', true),

    'demo_password' => env('BETTERDOC_DEMO_PASSWORD', 'BetterDocDemo!2026'),

    'profiles_path' => config_path('profiles'),

    /*
    |--------------------------------------------------------------------------
    | Internal Service Allowlist (Enforced in Air-Gapped Mode)
    |--------------------------------------------------------------------------
    */
    'internal_allowlist_hosts' => array_filter(array_map(
        'trim',
        explode(',', (string) env('BETTERDOC_INTERNAL_HOSTS', '127.0.0.1,localhost,postgres,valkey,meilisearch,minio,clamav,betterdoc-signer,betterdoc-pki,betterdoc-ocr,ollama,collabora,gotenberg,cups,reverb'))
    )),

    'external_allowlist_hosts' => array_filter(array_map(
        'trim',
        explode(',', (string) env('BETTERDOC_EXTERNAL_ALLOWLIST', ''))
    )),

    /*
    |--------------------------------------------------------------------------
    | Security & Session Controls
    |--------------------------------------------------------------------------
    */
    'security' => [
        'max_failed_logins' => (int) env('BETTERDOC_MAX_FAILED_LOGINS', 5),
        'lockout_minutes' => (int) env('BETTERDOC_LOCKOUT_MINUTES', 15),
        'step_up_mfa_ttl_minutes' => (int) env('BETTERDOC_STEP_UP_TTL', 5),
        'two_person_ttl_hours' => (int) env('BETTERDOC_TWO_PERSON_TTL_HOURS', 24),
        'break_glass_max_hours' => (int) env('BETTERDOC_BREAK_GLASS_MAX_HOURS', 4),
        'hsm_master_key_id' => env('BETTERDOC_KEK_ID', 'betterdoc-kek-v1'),
        'master_kek_b64' => env('BETTERDOC_MASTER_KEK_B64', ''),
        'trusted_proxies' => env('BETTERDOC_TRUSTED_PROXIES', ''),
        'enforce_https' => (bool) env('BETTERDOC_ENFORCE_HTTPS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ingestion, Malware (ClamAV), CDR, OCR & Search Drivers
    |--------------------------------------------------------------------------
    */
    'storage_driver' => env('BETTERDOC_STORAGE_DRIVER', 'local'),

    'upload' => [
        'chunk_size_bytes' => (int) env('BETTERDOC_UPLOAD_CHUNK_BYTES', 5 * 1024 * 1024),
        'max_size_bytes' => (int) env('BETTERDOC_UPLOAD_MAX_BYTES', 2 * 1024 * 1024 * 1024),
        'session_ttl_hours' => (int) env('BETTERDOC_UPLOAD_TTL_HOURS', 48),
    ],

    'clamav' => [
        'enabled' => (bool) env('BETTERDOC_CLAMAV_ENABLED', false),
        'host' => env('BETTERDOC_CLAMAV_HOST', 'clamav'),
        'port' => (int) env('BETTERDOC_CLAMAV_PORT', 3310),
        'fail_closed' => (bool) env('BETTERDOC_CLAMAV_FAIL_CLOSED', true),
    ],

    'ocr' => [
        'enabled' => (bool) env('BETTERDOC_OCR_ENABLED', false),
        'url' => env('BETTERDOC_OCR_URL', 'http://betterdoc-ocr:8002'),
        'languages' => env('BETTERDOC_OCR_LANGUAGES', 'eng'),
    ],

    'gotenberg' => [
        'enabled' => (bool) env('BETTERDOC_GOTENBERG_ENABLED', false),
        'url' => env('BETTERDOC_GOTENBERG_URL', 'http://gotenberg:3000'),
    ],

    'search' => [
        'driver' => env('BETTERDOC_SEARCH_DRIVER', 'postgres'),
        'meilisearch_host' => env('MEILISEARCH_HOST', 'http://meilisearch:7700'),
        'meilisearch_key' => env('MEILI_MASTER_KEY', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Internal PKI, HSM Custody & PAdES Signer Service
    |--------------------------------------------------------------------------
    */
    'pki' => [
        'hsm_driver' => env('BETTERDOC_HSM_DRIVER', 'sealed_file'), // sealed_file | pkcs11
        'hsm_path' => env('BETTERDOC_HSM_PATH', storage_path('app/hsm')),
        'signer_driver' => env('BETTERDOC_SIGNER_DRIVER', 'local_pyhanko'), // local_pyhanko | http
        'signer_url' => env('BETTERDOC_SIGNER_URL', 'http://betterdoc-signer:8003'),
        'pki_url' => env('BETTERDOC_PKI_URL', 'http://betterdoc-pki:8004'),
        'python_bin' => env('BETTERDOC_SIGNER_PYTHON', PHP_OS_FAMILY === 'Windows'
            ? base_path('services/betterdoc-signer/.venv/Scripts/python.exe')
            : base_path('services/betterdoc-signer/.venv/bin/python')),
        'pkcs11_library' => env('BETTERDOC_PKCS11_LIB', '/usr/lib/softhsm/libsofthsm2.so'),
        'pkcs11_slot' => env('BETTERDOC_PKCS11_SLOT', '0'),
        'require_fresh_mfa_for_pades' => (bool) env('BETTERDOC_PADES_REQUIRE_FRESH_MFA', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Offline Office Co-Editing (Collabora Online / WOPI) & Workflows
    |--------------------------------------------------------------------------
    */
    'collabora' => [
        'enabled' => (bool) env('BETTERDOC_COLLABORA_ENABLED', false),
        'url' => env('BETTERDOC_COLLABORA_URL', 'http://collabora:9980'),
        'wopi_base_url' => env('BETTERDOC_WOPI_BASE_URL', env('APP_URL', 'http://127.0.0.1:8000')),
        'token_ttl_minutes' => (int) env('BETTERDOC_WOPI_TOKEN_TTL_MINUTES', 120),
        'bind_client_ip' => (bool) env('BETTERDOC_WOPI_BIND_IP', false),
    ],

    'workflows' => [
        'default_sla_hours' => (int) env('BETTERDOC_WORKFLOW_SLA_HOURS', 48),
    ],

    /*
    |--------------------------------------------------------------------------
    | Local-First AI, IDP, Pre-Filtered Hybrid RAG & Anomaly Detection
    |--------------------------------------------------------------------------
    */
    'ai' => [
        'driver' => env('BETTERDOC_AI_DRIVER', 'local_hybrid'), // local_hybrid | ollama | cloud_openai
        'ollama_url' => env('BETTERDOC_OLLAMA_URL', 'http://ollama:11434'),
        'chat_model' => env('BETTERDOC_AI_CHAT_MODEL', 'qwen2.5:7b-instruct'),
        'embed_model' => env('BETTERDOC_AI_EMBED_MODEL', 'bge-m3'),
        'vision_model' => env('BETTERDOC_AI_VISION_MODEL', 'qwen2.5vl'),
        'translation_model' => env('BETTERDOC_AI_TRANSLATION_MODEL', 'nllb-200'),
        'cloud_ai_enabled' => (bool) env('BETTERDOC_CLOUD_AI_ENABLED', false),
        'cloud_endpoint' => env('BETTERDOC_CLOUD_AI_ENDPOINT', ''),
        'embedding_dims' => (int) env('BETTERDOC_AI_EMBED_DIMS', 128),
        'anomaly' => [
            'bulk_download_threshold' => (int) env('BETTERDOC_ANOMALY_BULK_DOWNLOADS', 5),
            'bulk_view_threshold' => (int) env('BETTERDOC_ANOMALY_BULK_VIEWS', 25),
            'window_minutes' => (int) env('BETTERDOC_ANOMALY_WINDOW_MINUTES', 15),
            'business_hours_start' => env('BETTERDOC_BUSINESS_HOURS_START', '06:00'),
            'business_hours_end' => env('BETTERDOC_BUSINESS_HOURS_END', '19:00'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Printing (CUPS/IPPS, Secure PIN/RFID Release), Scanning & Local Agent
    |--------------------------------------------------------------------------
    */
    'print_scan' => [
        'print_driver' => env('BETTERDOC_PRINT_DRIVER', 'local_spool'), // local_spool | cups_ipps
        'cups_url' => env('BETTERDOC_CUPS_URL', 'http://cups:631'),
        'max_pin_attempts' => (int) env('BETTERDOC_PRINT_MAX_PIN_ATTEMPTS', 3),
        'pin_ttl_hours' => (int) env('BETTERDOC_PRINT_PIN_TTL_HOURS', 24),
        'hot_folder_root' => env('BETTERDOC_HOT_FOLDER_ROOT', storage_path('app/scan_hotfolders')),
        'mail_in_root' => env('BETTERDOC_MAIL_IN_ROOT', storage_path('app/mail_in')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Finance AP Automation (2/3-Way Matching, UBL 2.1, ZIMRA FDMS) & Compliance
    |--------------------------------------------------------------------------
    */
    'finance' => [
        'ap' => [
            'price_variance_tolerance_pct' => (float) env('BETTERDOC_AP_PRICE_TOL_PCT', 2.0),
            'qty_variance_tolerance_pct' => (float) env('BETTERDOC_AP_QTY_TOL_PCT', 0.0),
            'high_value_approval_cents' => (int) env('BETTERDOC_AP_HIGH_VALUE_CENTS', 1000000),
        ],
        'zimra' => [
            'driver' => env('BETTERDOC_ZIMRA_DRIVER', 'offline_queue'), // offline_queue | fdms_http
            'fdms_url' => env('BETTERDOC_ZIMRA_FDMS_URL', 'http://127.0.0.1:8095'),
            'device_id' => env('BETTERDOC_ZIMRA_DEVICE_ID', 'FDMS-ZW-001'),
        ],
    ],

    'compliance' => [
        'siem_syslog_enabled' => (bool) env('BETTERDOC_SIEM_ENABLED', false),
        'siem_syslog_host' => env('BETTERDOC_SIEM_HOST', '127.0.0.1'),
        'siem_syslog_port' => (int) env('BETTERDOC_SIEM_PORT', 5140),
        'siem_app_name' => env('BETTERDOC_SIEM_APP', 'betterdoc-dms'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Phase 8: Optional Outbox Sync Gateway & 3-2-1 Encrypted Backup / DR
    |--------------------------------------------------------------------------
    */
    'sync' => [
        'enabled' => (bool) env('BETTERDOC_SYNC_ENABLED', false),
        'max_classification_rank' => (int) env('BETTERDOC_SYNC_MAX_RANK', 1),
        'mirror_disk' => env('BETTERDOC_SYNC_MIRROR_DISK', 'betterdoc_vault'),
    ],

    'backup' => [
        'storage_disk' => env('BETTERDOC_BACKUP_DISK', 'betterdoc_vault'),
        'target_rto_seconds' => (int) env('BETTERDOC_BACKUP_TARGET_RTO', 7200),
        'retention_days' => (int) env('BETTERDOC_BACKUP_RETENTION_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Optional LDAP / Active Directory / FreeIPA Connector
    |--------------------------------------------------------------------------
    */
    'ldap' => [
        'enabled' => (bool) env('BETTERDOC_LDAP_ENABLED', false),
        'host' => env('BETTERDOC_LDAP_HOST', ''),
        'port' => (int) env('BETTERDOC_LDAP_PORT', 636),
        'use_ldaps' => (bool) env('BETTERDOC_LDAP_LDAPS', true),
        'base_dn' => env('BETTERDOC_LDAP_BASE_DN', ''),
        'bind_dn' => env('BETTERDOC_LDAP_BIND_DN', ''),
        'bind_password' => env('BETTERDOC_LDAP_BIND_PASSWORD', ''),
    ],
];
