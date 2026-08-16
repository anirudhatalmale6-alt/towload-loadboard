/* ═══════════════════════════════════════════════════════════════════════════
   GOOGLE ADDRESS SUGGESTIONS — shared helper

   TLPlaces.attach(input, onPick) turns a plain <input> into an address box with
   Google's suggestions, and calls onPick({lat,lng,address,city,state,zip}) when
   one is chosen.

   Uses PlaceAutocompleteElement, not the old Autocomplete widget: the old one
   is refused for any Google Cloud project created after 1 March 2025, and it
   refuses by DISABLING the input and writing "Oops! Something went wrong." over
   the placeholder — leaving somebody unable to type an address at all.

   Everything is written so a missing key, a blocked script or a refused request
   leaves the original input working exactly as it did.
   ═══════════════════════════════════════════════════════════════════════════ */
window.TLPlaces = (function () {
  var loading = null;

  function loadMaps() {
    if (loading) return loading;
    loading = (async function () {
      var key = '';
      try {
        var r = await fetch('api/geo/maps-key').then(function (x) { return x.json(); });
        key = (r && r.success && r.enabled) ? r.key : '';
      } catch (e) { return null; }
      if (!key) return null;

      if (!(window.google && window.google.maps)) {
        await new Promise(function (resolve) {
          var s = document.createElement('script');
          s.src = 'https://maps.googleapis.com/maps/api/js?loading=async&key=' +
                  encodeURIComponent(key);
          s.async = true;
          s.onload = resolve;
          s.onerror = function () { console.warn('[places] script failed'); resolve(); };
          document.head.appendChild(s);
        });
      }

      // onload is NOT the signal that the API is usable — under loading=async
      // the bootstrap defines google.maps.importLibrary a tick later. Checking
      // immediately finds nothing and gives up silently.
      var ready = await new Promise(function (resolve) {
        var started = Date.now();
        (function poll() {
          if (window.google && google.maps && google.maps.importLibrary) return resolve(true);
          if (Date.now() - started > 6000) return resolve(false);
          setTimeout(poll, 60);
        })();
      });
      if (!ready) return null;

      try { return await google.maps.importLibrary('places'); }
      catch (e) { console.warn('[places] library failed', e); return null; }
    })();
    return loading;
  }

  async function attach(input, onPick) {
    if (!input) return false;
    var places = await loadMaps();
    if (!places || !places.PlaceAutocompleteElement) return false;

    var widget;
    try {
      widget = new places.PlaceAutocompleteElement({ includedRegionCodes: ['us'] });
    } catch (e) { console.warn('[places] element failed', e); return false; }

    widget.className = 'pac-el';
    widget.setAttribute('placeholder', input.placeholder || '');
    if (input.value) { try { widget.value = input.value; } catch (e) {} }
    input.parentNode.insertBefore(widget, input);
    input.classList.add('hide');

    var handle = async function (ev) {
      try {
        var pred = ev.placePrediction || (ev.detail && ev.detail.placePrediction);
        if (!pred) return;
        var place = pred.toPlace();
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
      } catch (e) { console.warn('[places] read failed', e); }
    };

    widget.addEventListener('gmp-select', handle);
    widget.addEventListener('gmp-placeselect', handle);

    // Mirror typed text on EVERY keystroke, not only on select. Otherwise, any
    // time Google returns nothing — key restriction, outage, or someone who
    // types the address and never touches the dropdown — the visible box has
    // text while the real input is empty.
    widget.addEventListener('input', function () {
      if (typeof widget.value === 'string') input.value = widget.value;
    });

    return true;
  }

  return { attach: attach };
})();
