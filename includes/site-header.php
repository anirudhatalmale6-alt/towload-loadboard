<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  THE SITE HEADER — one copy, every public page
 *
 *  Before this there were five headers. The logo was a link on three pages and
 *  a plain <div> on two; of the three, one went to /join, one to the customer
 *  page and one to the operator dashboard. Nobody decided that — it accreted,
 *  one page at a time, and the only way to notice was to click the logo on
 *  every page in turn.
 *
 *  WHY IT CARRIES ITS OWN CSS
 *  Each page defines its own .top and .brand, and they disagree: one styles the
 *  language button inline, another with a .lang class, and terms.html has no
 *  logo image at all. A shared header that relied on the host page's CSS would
 *  render five different ways and be "shared" in name only. So the classes here
 *  are prefixed sh- and the styles ship with it, once.
 *
 *  USAGE — before the markup, set what differs:
 *
 *      $navAudience = 'operator';   // or 'customer'  (default)
 *      require __DIR__ . '/includes/site-header.php';
 *
 *  WHERE THE LOGO GOES
 *  To the home page of whoever is reading. An operator on /join or
 *  /how_it_works goes to the operator landing page; a stranded motorist on
 *  /support or /terms goes to the page that books a tow. Sending an operator to
 *  the customer page is how somebody ends up requesting a tow instead of
 *  signing up for work.
 * ═══════════════════════════════════════════════════════════════════════════
 */

$navAudience = $navAudience ?? 'customer';
$isOperator  = ($navAudience === 'operator');

// '/join' is the operator front door, '/' is the customer one. Relative, with
// no leading slash, because every rewrite rule on this site is deliberately
// written to survive being moved into a subfolder.
$navHome = $isOperator ? 'join' : './';
?>
<style>
.sh-top{background:#fff;border-bottom:1px solid #e3e8ef;padding:14px 18px;
  display:flex;align-items:center;gap:10px;position:sticky;top:0;z-index:30}
.sh-brand{display:flex;align-items:center;gap:9px;font-weight:800;font-size:17px;
  letter-spacing:-.4px;text-decoration:none;color:#0b1220}
.sh-brand img{height:30px;width:auto;display:block}
.sh-lang{margin-left:auto;background:#f5f7fa;border:1px solid #e3e8ef;border-radius:8px;
  padding:7px 12px;font:inherit;font-size:13.5px;font-weight:700;color:#40506a;
  cursor:pointer;margin-right:12px}
.sh-link{font-size:14px;font-weight:650;text-decoration:none;color:#40506a;white-space:nowrap}
/* The three items stop fitting on a narrow phone and the last one wraps,
   dropping its arrow onto a line of its own. */
@media(max-width:430px){
  .sh-top{padding:12px 14px;gap:8px}
  .sh-brand{font-size:15.5px}
  .sh-brand img{height:26px}
  .sh-lang{padding:6px 9px;margin-right:8px;font-size:12.5px}
  .sh-link{font-size:12.5px}
}
</style>

<div class="sh-top">
  <a class="sh-brand" href="<?= $navHome ?>">
    <img src="assets/logo-96.png?v=1" alt="TowSling" width="35" height="30">TowSling
  </a>
  <button class="sh-lang" id="langBtn" onclick="tlToggle()">English</button>
<?php if ($isOperator): ?>
  <a class="sh-link" href="tow" data-i18n="nav.operator_login">Operator login &rarr;</a>
<?php else: ?>
  <a class="sh-link" href="join" data-i18n="nav.for_operators">For tow companies &rarr;</a>
<?php endif; ?>
</div>
