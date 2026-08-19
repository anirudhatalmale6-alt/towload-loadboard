<?php
require_once __DIR__ . '/helpers.php';

// ═══════════════════════════════════════════════════════════════════════════
//  COMPANY LOGOS
//
//  Deliberately NOT stored like compliance documents. Those live under
//  uploads/ behind a deny-all .htaccess and are served by an authenticated
//  endpoint, because an insurance certificate is private. A logo is the exact
//  opposite: its whole job is to be shown to a stranded customer who has no
//  account, no login and no token beyond the tracking link.
//
//  So it is written to a public directory — which means the usual rule about
//  never letting an upload land somewhere the web server will execute applies
//  with full force. Four things stop that here:
//
//    1. The type is SNIFFED, never taken from the browser or the extension.
//    2. The image is DECODED AND RE-ENCODED by GD. A PHP payload smuggled in
//       the comment field of a real JPEG does not survive being turned into
//       a pixel buffer and written back out.
//    3. The filename is random and its extension comes from what we wrote,
//       not from what was uploaded.
//    4. An .htaccess in the directory turns off every handler anyway.
//
//  Any one of those is enough on its own. All four are cheap.
// ═══════════════════════════════════════════════════════════════════════════

const LOGO_MIMES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

/** Longest edge of the logo shown beside the company name. */
const LOGO_MAX_PX = 256;
/** The round badge dropped on the map. Retina for a 40pt marker. */
const LOGO_PIN_PX = 96;

function logoDir(): string {
    $dir = __DIR__ . '/../assets/logos';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    // Rewritten whenever it is missing, for the same reason uploadRoot() does
    // it: a deploy that copies only tracked files would leave a directory that
    // happily executes whatever is in it.
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) {
        // NO php_flag here. This host runs PHP as FPM, not mod_php, so
        // php_flag/php_value are unknown directives and Apache answers 500 for
        // every file in the directory — including the logos themselves. The
        // upload succeeds, the URL is returned, and the image is simply
        // unreachable, which looks like a broken feature rather than a broken
        // config. Verified by fetching a stored logo, not by reading the code.
        //
        // FilesMatch does the same job and is core mod_authz, always available.
        @file_put_contents($ht,
            "# Company logos. Public by design - shown to customers who have no\n" .
            "# login. Nothing in here may ever be EXECUTED.\n" .
            "<FilesMatch \"\\.(php|phtml|php[0-9]|phps|pl|py|cgi|shtml|htaccess)$\">\n" .
            "  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n" .
            "  <IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n  </IfModule>\n" .
            "</FilesMatch>\n" .
            "Options -Indexes\n"
        );
    }
    return $dir;
}

/**
 * The round map badge that belongs to a logo.
 *
 * Derived from the main path rather than stored in a second column: both are
 * written in the same operation, and a name that can be computed cannot drift
 * out of step with the one it is computed from. Callers must still cope with
 * it being absent — every company without a logo is that case anyway.
 */
function logoPinUrl(?string $logoUrl): ?string {
    if (!$logoUrl) return null;
    $pin = preg_replace('/\.(jpg|png|webp)$/i', '-pin.png', $logoUrl);
    if ($pin === $logoUrl) return null;
    return is_file(__DIR__ . '/../' . $pin) ? $pin : null;
}

/** Decode whatever GD will take, from a path, honouring the sniffed type. */
function logoDecode(string $path, string $mime) {
    switch ($mime) {
        case 'image/jpeg': return @imagecreatefromjpeg($path);
        case 'image/png':  return @imagecreatefrompng($path);
        case 'image/webp': return @imagecreatefromwebp($path);
    }
    return false;
}

/**
 * Square canvas, image centred and scaled to fit, transparent background.
 * Fit rather than fill: cropping a wide logo to a square is how you cut a
 * company's name in half.
 */
function logoSquare($src, int $size) {
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min($size / max(1, $w), $size / max(1, $h));
    // Never blow a small logo up past its own resolution.
    $scale = min($scale, 1.0);
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));

    $out = imagecreatetruecolor($size, $size);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagealphablending($out, true);
    imagecopyresampled($out, $src, (int)(($size - $nw) / 2), (int)(($size - $nh) / 2),
                       0, 0, $nw, $nh, $w, $h);
    return $out;
}

/**
 * The map marker: the logo on a white disc with a ring, so it reads as a pin
 * against satellite imagery and city colour rather than as a floating
 * rectangle. Drawn at 4x and scaled down, because GD has no antialiasing on
 * filled arcs and a jagged circle looks broken at any size.
 */
function logoPin($src, int $size) {
    $ss = $size * 4;
    $big = imagecreatetruecolor($ss, $ss);
    imagealphablending($big, false);
    imagesavealpha($big, true);
    imagefill($big, 0, 0, imagecolorallocatealpha($big, 0, 0, 0, 127));
    imagealphablending($big, true);

    $ring  = imagecolorallocate($big, 255, 255, 255);
    $edge  = imagecolorallocatealpha($big, 11, 18, 32, 90);
    imagefilledellipse($big, $ss / 2, $ss / 2, $ss - 2, $ss - 2, $edge);
    imagefilledellipse($big, $ss / 2, $ss / 2, $ss - 10, $ss - 10, $ring);

    // The logo sits inside the ring, fitted to the largest square that fits
    // in the circle.
    $inner = (int)($ss * 0.68);
    $fitted = logoSquare($src, $inner);
    imagecopy($big, $fitted, (int)(($ss - $inner) / 2), (int)(($ss - $inner) / 2),
              0, 0, $inner, $inner);
    imagedestroy($fitted);

    $out = imagecreatetruecolor($size, $size);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagealphablending($out, true);
    imagecopyresampled($out, $big, 0, 0, 0, 0, $size, $size, $ss, $ss);
    imagedestroy($big);
    return $out;
}

/**
 * Store a company logo. Returns
 * ['ok'=>bool, 'logo_url'=>relative, 'pin_url'=>relative, 'error'=>string].
 */
function storeCompanyLogo(array $file, int $accountId): array {
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['ok' => false, 'error' => t('err.upload_failed')];
    }
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => t('err.upload_none')];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => t('err.upload_failed')];
    }

    $maxBytes = (int)setting('max_logo_mb', 8) * 1024 * 1024;
    if ($file['size'] > $maxBytes) {
        return ['ok' => false, 'error' => t('err.logo_too_big',
            ['mb' => (int)setting('max_logo_mb', 8)])];
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    if (!isset(LOGO_MIMES[$mime])) {
        return ['ok' => false, 'error' => t('err.logo_type')];
    }

    if (!extension_loaded('gd')) {
        return ['ok' => false, 'error' => t('err.upload_failed')];
    }

    $src = logoDecode($file['tmp_name'], $mime);
    if (!$src) return ['ok' => false, 'error' => t('err.logo_unreadable')];

    // A 20000x20000 PNG is a few hundred KB on disk and gigabytes once GD
    // expands it, which takes the whole site down rather than just this
    // request. Checked after decode so the number is the real one.
    if (imagesx($src) > 6000 || imagesy($src) > 6000) {
        imagedestroy($src);
        return ['ok' => false, 'error' => t('err.logo_dimensions')];
    }

    $dir  = logoDir();
    $stem = $accountId . '-' . bin2hex(random_bytes(8));

    $main = logoSquare($src, LOGO_MAX_PX);
    $pin  = logoPin($src, LOGO_PIN_PX);
    imagedestroy($src);

    $okMain = imagepng($main, $dir . '/' . $stem . '.png', 6);
    $okPin  = imagepng($pin,  $dir . '/' . $stem . '-pin.png', 6);
    imagedestroy($main);
    imagedestroy($pin);

    if (!$okMain || !$okPin) {
        @unlink($dir . '/' . $stem . '.png');
        @unlink($dir . '/' . $stem . '-pin.png');
        return ['ok' => false, 'error' => t('err.upload_failed')];
    }
    @chmod($dir . '/' . $stem . '.png', 0644);
    @chmod($dir . '/' . $stem . '-pin.png', 0644);

    return [
        'ok'       => true,
        'logo_url' => 'assets/logos/' . $stem . '.png',
        'pin_url'  => 'assets/logos/' . $stem . '-pin.png',
    ];
}

/**
 * Remove the files behind a logo_url. Best effort: a logo row that points at
 * a file already gone is not an error worth failing a save for.
 *
 * Only ever deletes inside assets/logos, checked with realpath rather than by
 * string — a logo_url is a value from the database and the database is not a
 * trust boundary this code gets to assume.
 */
function deleteCompanyLogo(?string $logoUrl): void {
    if (!$logoUrl) return;
    $root = realpath(logoDir());
    foreach ([$logoUrl, preg_replace('/\.png$/', '-pin.png', $logoUrl)] as $rel) {
        $p = realpath(__DIR__ . '/../' . $rel);
        if ($p && $root && strpos($p, $root . DIRECTORY_SEPARATOR) === 0) @unlink($p);
    }
}
