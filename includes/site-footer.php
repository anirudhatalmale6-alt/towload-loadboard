<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  THE SITE FOOTER — one copy, every public page
 *
 *  Only /how_it_works had one at all. Terms and Support were reachable from the
 *  signup form's small print and nowhere else, which for a page Stripe requires
 *  to be findable is thin.
 *
 *  Carries its own CSS for the same reason the header does: the pages disagree
 *  about what .foot means, and a footer that inherits five different sets of
 *  rules is not a shared footer.
 *
 *      require __DIR__ . '/includes/site-footer.php';
 * ═══════════════════════════════════════════════════════════════════════════
 */
?>
<style>
.sf-foot{border-top:1px solid #e3e8ef;padding:26px 20px;text-align:center;
  color:#7d8ba3;font-size:13px;line-height:1.9}
.sf-foot a{color:#40506a;text-decoration:none;margin:0 9px;white-space:nowrap}
.sf-foot a:hover{text-decoration:underline}
.sf-copy{margin-top:8px}
</style>

<div class="sf-foot">
  <a href="join" data-i18n="nav.for_operators_f">For tow companies</a>
  <a href="how_it_works" data-i18n="nav.how_it_works">How it works</a>
  <a href="./" data-i18n="nav.need_tow">Need a tow</a>
  <a href="support" data-i18n="nav.support">Support</a>
  <a href="terms" data-i18n="nav.terms">Terms</a>
  <div class="sf-copy">&copy; TowSling</div>
</div>
