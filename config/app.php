<?php
// ─── APP CONFIGURATION ──────────────────────────────────────────────────────
// Working name only — change APP_NAME/APP_URL once the brand is decided.
define('APP_NAME', getenv('TL_APP_NAME') ?: 'TowLoad');
define('APP_URL',  getenv('TL_APP_URL')  ?: 'https://board.towmasterscorp.com');

define('JWT_SECRET', getenv('TL_JWT_SECRET') ?: 'CHANGE_ME_IN_PRODUCTION');
define('JWT_EXPIRY', 86400 * 30); // 30 days

define('MAX_PHOTO_SIZE', 10 * 1024 * 1024); // 10MB
define('UPLOAD_DIR', __DIR__ . '/../uploads/');

// Hard ceiling on how far a tower can ever see. Keeps the board relevant and
// stops someone in Miami bidding a call in Jacksonville.
define('MAX_SEARCH_RADIUS_MILES', 150);
