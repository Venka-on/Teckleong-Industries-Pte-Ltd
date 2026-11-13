/* Site search + form guard for the static copper.asia site.
   The original ASP.NET search postbacks do not exist here, so search goes to Google (site:copper.asia). */
(function () {
  var PLACEHOLDER = /^\s*(search(\s+by\s+keyword\(s\))?)?\s*$/i;
  function isSearchInput(el) {
    return el && el.tagName === 'INPUT' && /search/i.test((el.id || '') + (el.name || ''));
  }
  function go(input) {
    var q = (input && input.value || '').trim();
    if (PLACEHOLDER.test(q)) { if (input) input.focus(); return; }
    window.location.href = 'https://www.google.com/search?q=' + encodeURIComponent('site:copper.asia ' + q);
  }
  function findInput(from) {
    var box = from.closest ? from.closest('.search_box, #ctl00_panelSearch, #ctl00_panelMobSearch, td, form') : null;
    return box ? box.querySelector('input[name*="Search"]') : document.querySelector('input[name*="Search"]');
  }
  document.addEventListener('click', function (e) {
    var control = e.target.closest ? e.target.closest('a, button') : null;
    if (!control || !/(SearchLinkButton|lbMobileSearch)/i.test(control.id || '')) return;
    e.preventDefault();
    var input = /lbMobileSearch/i.test(control.id) ?
      document.getElementById('ctl00_txtMobileSearch') : findInput(control);
    go(input);
  }, true);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && isSearchInput(e.target)) { e.preventDefault(); go(e.target); }
  }, true);
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f && f.getAttribute && f.getAttribute('action') !== '/enquiry.php') e.preventDefault();
  }, true);
})();
