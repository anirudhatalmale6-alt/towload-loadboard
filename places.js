/* ═══════════════════════════════════════════════════════════════════════════
   ADDRESS SUGGESTIONS — one implementation, used by the booking form and by a
   towing company setting its base location.

     TLPlaces.attach(inputEl, onPick)
       -> onPick({lat, lng, address, city, state, zip})

   Deliberately NOT Google's PlaceAutocompleteElement. That component takes over
   the ENTIRE screen on a phone: the booking form vanishes, replaced by a white
   full-page search view. A stranded motorist halfway through asking for a truck
   suddenly cannot see the form they were filling in, and there is no setting to
   turn it off — the behaviour lives inside a closed shadow root.

   So this drives the same data through AutocompleteSuggestion and renders the
   list itself, anchored under the input. The customer's own input element stays
   exactly where it was, which also removes the hidden-mirror hack the component
   forced on us.

   (The older google.maps.places.Autocomplete is not an option either: it is
   refused outright for Google Cloud projects created after 1 March 2025, and
   it refuses by DISABLING the input.)
   ═══════════════════════════════════════════════════════════════════════════ */
window.TLPlaces = (function () {
  var apiLoading = null;
  var loading = null;
  var MIN_CHARS = 3;
  var DEBOUNCE_MS = 250;

  /* Loads the Google Maps JS bootstrap once, whoever asks first.
     Shared by the address suggestions and by the customer's tracking map, so
     the key is fetched once and the script tag exists once — two callers each
     appending their own would load the API twice and Google logs a warning
     about exactly that. Resolves true when google.maps.importLibrary is
     genuinely callable. */
  function loadApi() {
    if (apiLoading) return apiLoading;
    apiLoading = (async function () {
      var key = '';
      try {
        var r = await fetch('api/geo/maps-key').then(function (x) { return x.json(); });
        key = (r && r.success && r.enabled) ? r.key : '';
      } catch (e) { return null; }
      if (!key) return null;

      if (!(window.google && window.google.maps)) {
        await new Promise(function (resolve) {
          var s = document.createElement('script');
          s.src = 'https://maps.googleapis.com/maps/api/js?loading=async&key=' + encodeURIComponent(key);
          s.async = true;
          s.onload = resolve;
          s.onerror = function () { console.warn('[places] script failed'); resolve(); };
          document.head.appendChild(s);
        });
      }

      // script.onload does NOT mean the API is usable — under loading=async the
      // bootstrap defines google.maps.importLibrary a tick later, so an
      // immediate check finds nothing and gives up silently.
      var ready = await new Promise(function (resolve) {
        var t0 = Date.now();
        (function poll() {
          if (window.google && google.maps && google.maps.importLibrary) return resolve(true);
          if (Date.now() - t0 > 6000) return resolve(false);
          setTimeout(poll, 60);
        })();
      });
      return ready;
    })();
    return apiLoading;
  }

  function loadPlaces() {
    if (loading) return loading;
    loading = (async function () {
      if (!await loadApi()) return null;
      try { return await google.maps.importLibrary('places'); }
      catch (e) { console.warn('[places] library failed', e); return null; }
    })();
    return loading;
  }

  async function attach(input, onPick) {
    if (!input) return false;
    var places = await loadPlaces();
    if (!places || !places.AutocompleteSuggestion) return false;

    // The list is positioned relative to the field, so the field's wrapper has
    // to be the containing block.
    var wrap = input.parentElement;
    if (getComputedStyle(wrap).position === 'static') wrap.style.position = 'relative';

    var list = document.createElement('div');
    list.className = 'tl-ac';
    list.setAttribute('role', 'listbox');
    list.hidden = true;
    wrap.appendChild(list);

    input.setAttribute('autocomplete', 'off');
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');

    var token = null, items = [], active = -1, timer = null, seq = 0;

    // One session token per search-then-pick cycle. Google bills a session
    // rather than every keystroke, so reusing it until a place is chosen is
    // both correct and considerably cheaper.
    function newSession() {
      try { token = new places.AutocompleteSessionToken(); } catch (e) { token = null; }
    }
    newSession();

    function close() { list.hidden = true; active = -1; }

    function draw() {
      if (!items.length) return close();
      list.innerHTML = items.map(function (s, i) {
        var p = s.placePrediction;
        var main = p.mainText ? p.mainText.toString() : p.text.toString();
        var sec  = p.secondaryText ? p.secondaryText.toString() : '';
        return '<button type="button" class="tl-ac-item' + (i === active ? ' on' : '') +
               '" role="option" data-i="' + i + '">' +
               '<span class="tl-ac-pin" aria-hidden="true">📍</span>' +
               '<span class="tl-ac-txt"><b></b><small></small></span></button>';
      }).join('');
      // Text set as textContent, never interpolated into the HTML above: these
      // strings come from a third party and land in the page.
      list.querySelectorAll('.tl-ac-item').forEach(function (el, i) {
        var p = items[i].placePrediction;
        el.querySelector('b').textContent = p.mainText ? p.mainText.toString() : p.text.toString();
        el.querySelector('small').textContent = p.secondaryText ? p.secondaryText.toString() : '';
        el.addEventListener('mousedown', function (ev) { ev.preventDefault(); choose(i); });
      });
      list.hidden = false;
    }

    async function search(q) {
      var mine = ++seq;
      try {
        var res = await places.AutocompleteSuggestion.fetchAutocompleteSuggestions({
          input: q,
          includedRegionCodes: ['us'],
          sessionToken: token,
        });
        // A slow earlier request must never overwrite a newer one's results.
        if (mine !== seq) return;
        items = (res.suggestions || []).filter(function (s) { return s.placePrediction; }).slice(0, 5);
        active = -1;
        draw();
      } catch (e) {
        // Blocked key, quota, offline. Silence is right: the box still works as
        // a plain text field and the form still submits.
        if (mine === seq) { items = []; close(); }
      }
    }

    async function choose(i) {
      var s = items[i];
      if (!s) return;
      close();
      try {
        var place = s.placePrediction.toPlace();
        await place.fetchFields({ fields: ['location', 'formattedAddress', 'addressComponents'] });
        var loc = place.location;
        if (!loc) return;

        var parts = {};
        (place.addressComponents || []).forEach(function (c) {
          var ty = c.types || [];
          if (ty.indexOf('locality') >= 0) parts.city = c.longText;
          else if (!parts.city && ty.indexOf('sublocality') >= 0) parts.city = c.longText;
          if (ty.indexOf('administrative_area_level_1') >= 0) parts.state = c.shortText;
          if (ty.indexOf('postal_code') >= 0) parts.zip = c.longText;
        });

        input.value = place.formattedAddress || input.value;
        onPick({
          lat: typeof loc.lat === 'function' ? loc.lat() : loc.lat,
          lng: typeof loc.lng === 'function' ? loc.lng() : loc.lng,
          address: place.formattedAddress || '',
          city: parts.city || null,
          state: parts.state || null,
          zip: parts.zip || null
        });
      } catch (e) {
        console.warn('[places] could not read that place', e);
      }
      // That session is spent; the next search starts a new one.
      newSession();
    }

    input.addEventListener('input', function () {
      clearTimeout(timer);
      var q = input.value.trim();
      if (q.length < MIN_CHARS) { items = []; return close(); }
      timer = setTimeout(function () { search(q); }, DEBOUNCE_MS);
    });

    input.addEventListener('keydown', function (e) {
      if (list.hidden || !items.length) {
        // Enter inside an address box should never submit the step early.
        if (e.key === 'Enter') e.preventDefault();
        return;
      }
      if (e.key === 'ArrowDown')      { e.preventDefault(); active = Math.min(active + 1, items.length - 1); draw(); }
      else if (e.key === 'ArrowUp')   { e.preventDefault(); active = Math.max(active - 1, 0); draw(); }
      else if (e.key === 'Enter')     { e.preventDefault(); if (active >= 0) choose(active); }
      else if (e.key === 'Escape')    { close(); }
    });

    // Delay so a tap on a suggestion is not cancelled by the blur that precedes it.
    input.addEventListener('blur', function () { setTimeout(close, 150); });

    return true;
  }

  return { attach: attach, loadApi: loadApi };
})();
