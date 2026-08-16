<?php
require_once __DIR__ . '/helpers.php';

// ═══════════════════════════════════════════════════════════════════════════
//  WHAT A TOWING COMPANY HAS TO HAVE ON FILE
//
//  This lived inside api/docs.php, which meant the only way to ask "is this
//  company's paperwork in order?" was to be inside that one endpoint. The
//  dashboard banner needs the same answer, and a second copy of the rule is how
//  the two screens end up disagreeing about whether somebody can work.
// ═══════════════════════════════════════════════════════════════════════════

const DOC_TYPES = ['coi_liability','coi_garage_keepers','coi_on_hook','w9','business_license',
                   'tow_license','dot_authority','ein_letter','state_registration','owner_id','other'];

// Insurance is the only document where an expiry date is meaningful enough to
// insist on — it is the one that blocks dispatch when it lapses.
const DOCS_NEEDING_EXPIRY = ['coi_liability','coi_garage_keepers','coi_on_hook'];

function requiredDocTypes(): array {
    $raw = (string)setting('required_tower_docs', 'ein_letter,state_registration,coi_liability,owner_id');
    return array_values(array_filter(array_map('trim', explode(',', $raw))));
}

/** What is still missing, in the order the signup page asks for it. */
function docChecklist(int $accountId): array {
    $stmt = getDB()->prepare(
        "SELECT doc_type, status, expires_at, created_at, review_notes
           FROM compliance_docs
          WHERE account_id = :a AND status IN ('pending','approved','rejected')
          ORDER BY created_at DESC"
    );
    $stmt->execute([':a' => $accountId]);

    $have = [];
    foreach ($stmt as $row) {
        if (!isset($have[$row['doc_type']])) $have[$row['doc_type']] = $row;
    }

    $out = [];
    foreach (requiredDocTypes() as $type) {
        $row = $have[$type] ?? null;
        $out[] = [
            'doc_type'   => $type,
            'label'      => t('doc.' . $type),
            'uploaded'   => $row !== null,
            'status'     => $row['status'] ?? 'missing',
            'expires_at' => $row['expires_at'] ?? null,
            'notes'      => $row['review_notes'] ?? null,
        ];
    }
    return $out;
}

/**
 * Reduce the checklist to one word.
 *
 *   missing   — at least one required document has never been uploaded
 *   rejected  — a reviewer sent one back
 *   expired   — an approved certificate has lapsed
 *   pending   — everything is in, waiting on a human
 *   approved  — all present, all approved, nothing lapsed
 *
 * The distinction between `missing` and `pending` is the whole point. Before
 * this existed the dashboard could only ask "is there an approved insurance
 * certificate?", so a company that had uploaded all four documents an hour ago
 * was shown the identical red warning as one that had uploaded nothing —
 * telling an operator who had already done the work that they had not done it.
 */
function docsState(int $accountId): string {
    $list = docChecklist($accountId);
    if (!$list) return 'approved';

    $today    = date('Y-m-d');
    $missing  = false;
    $rejected = false;
    $pending  = false;
    $expired  = false;

    foreach ($list as $c) {
        if (!$c['uploaded'])              { $missing  = true; continue; }
        if ($c['status'] === 'rejected')  { $rejected = true; continue; }
        if ($c['status'] === 'pending')   { $pending  = true; continue; }
        if ($c['status'] === 'approved' && $c['expires_at'] && $c['expires_at'] < $today) {
            $expired = true;
        }
    }

    // Ordered by what the operator must act on first. An expired certificate
    // outranks a pending one: it is the item actually stopping dispatch today.
    if ($expired)  return 'expired';
    if ($rejected) return 'rejected';
    if ($missing)  return 'missing';
    if ($pending)  return 'pending';
    return 'approved';
}
