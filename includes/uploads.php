<?php
require_once __DIR__ . '/helpers.php';

// ═══════════════════════════════════════════════════════════════════════════
//  COMPLIANCE FILE STORAGE
//
//  These uploads are scans of driver's licences, EIN letters and insurance
//  policies. That is identity-document territory, and the default way to
//  handle uploads — drop them in a folder under the web root and hand out the
//  URL — turns a directory listing or a guessed filename into a data breach
//  that belongs to Ricardo, not to the tower who uploaded it.
//
//  So, three layers:
//
//   1. The directory is denied at the web server (.htaccess written on first
//      use, so a fresh deploy cannot forget it).
//   2. Filenames are 32 random hex characters. Nothing is guessable and
//      nothing leaks the company name or the document type.
//   3. Nothing is ever linked directly. Files are served by api/docs.php,
//      which checks that the requester is either the account that uploaded it
//      or a platform admin.
//
//  Belt and braces on purpose: any one of the three failing still leaves the
//  documents unreachable.
// ═══════════════════════════════════════════════════════════════════════════

function uploadRoot(): string {
    $dir = __DIR__ . '/../uploads';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    // Rewritten every time it is missing. A deploy that copies only tracked
    // files would otherwise leave a world-readable directory of ID scans.
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht,
            "# Compliance documents. Never served directly - see api/docs.php\n" .
            "Require all denied\n" .
            "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n" .
            "Options -Indexes\n"
        );
    }
    return $dir;
}

const ALLOWED_DOC_MIMES = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/heic'      => 'heic',
    'image/heif'      => 'heif',
    'image/webp'      => 'webp',
];

/**
 * Store an uploaded compliance document.
 *
 * $file is a single entry from $_FILES. Returns
 * ['ok'=>bool, 'path'=>relative, 'mime'=>..., 'size'=>..., 'error'=>...].
 */
function storeComplianceFile(array $file, int $accountId): array {
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['ok' => false, 'error' => t('err.upload_failed')];
    }
    switch ($file['error']) {
        case UPLOAD_ERR_OK: break;
        case UPLOAD_ERR_NO_FILE:
            return ['ok' => false, 'error' => t('err.upload_none')];
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return ['ok' => false, 'error' => t('err.upload_too_big',
                ['mb' => (int)setting('max_upload_mb', 12)])];
        default:
            return ['ok' => false, 'error' => t('err.upload_failed')];
    }

    $maxBytes = (int)setting('max_upload_mb', 12) * 1024 * 1024;
    if ($file['size'] > $maxBytes) {
        return ['ok' => false, 'error' => t('err.upload_too_big',
            ['mb' => (int)setting('max_upload_mb', 12)])];
    }

    // Sniff the real type. The browser-supplied type is attacker-controlled and
    // the extension is meaningless — a .pdf that is actually a .php is the
    // whole ballgame if it ever lands somewhere executable.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']) ?: '';
    if (!isset(ALLOWED_DOC_MIMES[$mime])) {
        return ['ok' => false, 'error' => t('err.upload_type')];
    }

    $dir = uploadRoot() . '/' . $accountId;
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
        return ['ok' => false, 'error' => t('err.upload_failed')];
    }

    $name = bin2hex(random_bytes(16)) . '.' . ALLOWED_DOC_MIMES[$mime];
    $dest = $dir . '/' . $name;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok' => false, 'error' => t('err.upload_failed')];
    }
    @chmod($dest, 0640);

    return [
        'ok'   => true,
        'path' => $accountId . '/' . $name,   // relative, never a URL
        'mime' => $mime,
        'size' => (int)$file['size'],
    ];
}

/**
 * Absolute path for a stored document, or null if the relative path tries to
 * escape the upload root. Checked with realpath rather than by string, because
 * "../" is not the only way out of a directory.
 */
function complianceFilePath(?string $relative): ?string {
    if (!$relative) return null;
    $root = realpath(uploadRoot());
    $full = realpath($root . '/' . $relative);
    if (!$root || !$full) return null;
    if (strpos($full, $root . DIRECTORY_SEPARATOR) !== 0) return null;
    return is_file($full) ? $full : null;
}
