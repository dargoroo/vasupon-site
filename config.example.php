<?php
/**
 * Copy this file to config.php and fill in real values on the server/local machine only.
 * Do not commit config.php to Git.
 */

$DB_HOST = '127.0.0.1';
$DB_NAME = 'your_database_name';
$DB_USER = 'your_database_user';
$DB_PASS = 'your_database_password';

/**
 * Optional app-level settings that should stay outside Git when sensitive.
 * You can read them later via app_config('KEY_NAME').
 */
$AUNQA_WEBHOOK_URL = 'https://example.com/aunqa_php_portal/api_receive.php';
$AUNQA_ALLOWED_HOST = 'example.com';

$OFFICEFB_ADMIN_USERNAME = 'admin';
$OFFICEFB_ADMIN_PASSWORD = 'change-this-password';
$OFFICEFB_PATH_KIOSK = '/office_feedback';
$OFFICEFB_PATH_ADMIN = '/office_feedback/admin';
$OFFICEFB_PATH_REPORT = '/office_feedback/report';
$OFFICEFB_GEMINI_API_KEY = '';
$OFFICEFB_GEMINI_API_MODEL = 'gemini-2.5-flash';
$OFFICEFB_AI_AUTO_PASS_THRESHOLD = '80';

$CPEPORTAL_ADMIN_USERNAME = 'admin';
$CPEPORTAL_ADMIN_PASSWORD = 'change-this-password';
$CPEPORTAL_PATH_ROOT = '/cpe_portal';
$CPEPORTAL_PATH_ADMIN = '/cpe_portal/admin';

$GRADERAPP_ADMIN_USERNAME = 'admin';
$GRADERAPP_ADMIN_PASSWORD = 'change-this-password';
$GRADERAPP_PATH_ROOT = '/grader_app';
$GRADERAPP_PATH_ADMIN = '/grader_app/admin';
$GRADERAPP_PATH_API = '/grader_app/api';
$GRADERAPP_WORKER_ENDPOINT = 'https://rbruai2.rbru.ac.th';
$GRADERAPP_RUNNER_TARGET_DEFAULT = 'rbruai2';
$GRADERAPP_WORKER_SHARED_TOKEN = 'grader-worker-token';
$GRADERAPP_DEFAULT_WORKER_NAME = 'rbruai2-worker';
$GRADERAPP_DEFAULT_WORKER_HOST = 'rbruai2.rbru.ac.th';
