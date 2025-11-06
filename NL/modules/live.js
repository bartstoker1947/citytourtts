// live.js — v12 (smooth rotate, SV follow, overlay-friendly, single hidden marker)
(function () {
  'use strict';

  window.__LIVE_TAG = 'v12e';
  console.log('live v12e gestart');

  // ---------- Config / state ----------
  const DEFAULTS = {
    intervalMs: 1000,
    ttlSeconds: 60,
    svFollow: false,
    snapToPano: true,
    jumpRejectDeg: 90,          // negeer onmogelijke sprongen
    debug: false,
    onUpdate: null,
    useAdvancedMarker: false    // klassiek = stabieler anchors
  };

  let _opts = { ...DEFAULTS };
  let _interval = null;
  let _prevLL = null;
  let _prevHeading = null;
  let last = null;              // { ll, heading }

  // ---------- Math helpers ----------
  const toRad = d => d * Math.PI / 180;
  const toDeg = r => r * 180 / Math.PI;
  const norm360 = a => (a % 360 + 360) % 360;
  function shortestDelta(from, to){
    let d = norm360(to) - norm360(from);
    if (d > 180) d -= 360;
    if (d < -180) d += 360;
    return d;
  }
  function angDelta(a, b){ return Math.abs(shortestDelta(a, b)); }

  function computeHeadingDegrees(a, b){
    const y = Math.sin(toRad(b.lng - a.lng)) * Math.cos(toRad(b.lat));
    const x = Math.cos(toRad(a.lat)) * Math.sin(toRad(b.lat))
            - Math.sin(toRad(a.lat)) * Math.cos(toRad(b.lat)) * Math.cos(toRad(b.lng - a.lng));
    return norm360(toDeg(Math.atan2(y, x)));
  }

  function parseHeading(h){ const n = Number(h); return Number.isFinite(n) ? norm360(n) : undefined; }

  // meters → lat/lng offset; negatieve dLat om mannetje visueel onderin te houden
  function shiftLatLng(ll, hdgDeg, offsetMeters = 35){
    const R = 6378137, rad = Math.PI/180, hdg = hdgDeg * rad;
    const dx = Math.sin(hdg) * offsetMeters, dy = Math.cos(hdg) * offsetMeters;
    const dLat = (dy / R) * (180 / Math.PI);
    const dLng = (dx / (R * Math.cos(ll.lat * rad))) * (180 / Math.PI);
    return { lat: ll.lat - dLat, lng: ll.lng - dLng };
  }
  window.__shiftLatLng = shiftLatLng;

  // ---------- Smooth heading ----------
  let __hdgAnim = null;
  function setHeadingSmooth(map, target, duration = 600){
    if (!map) return;
    if (__hdgAnim) cancelAnimationFrame(__hdgAnim);
    const start = map.getHeading?.() ?? 0;
    const delta = shortestDelta(start, target);
    if (Math.abs(delta) < 0.1){ map.setHeading?.(target); return; }
    const t0 = performance.now(), ease = t => 1 - Math.pow(1 - t, 3);
    function tick(now){
      const t = Math.min(1, (now - t0) / duration);
      map.setHeading?.(norm360(start + ease(t) * delta));
      if (t < 1) __hdgAnim = requestAnimationFrame(tick); else __hdgAnim = null;
    }
    __hdgAnim = requestAnimationFrame(tick);
  }

  // ---------- Street View follow/snap ----------
  const svSvc = () => (window.__svSvc ||= new google.maps.StreetViewService());
function updateStreetView(ll, hdg){
  if (!_opts || !_opts.svFollow) {
    if (_opts?.debug) console.log('[SV-LIVE] skip (svFollow=false)');
    return; // <-- live.js doet niets meer met SV
  }
  if (!window.panorama) return;
  window.panorama.setPosition(ll);
  if (typeof hdg === 'number') window.panorama.setPov({ heading: hdg, pitch: 0 });
}

  // ---------- Icon (queue tot marker klaar is) ----------
  function resolveIconUrl(u){
    try{
      if (!u) return null;
      if (/^(https?:)?\/\//i.test(u) || /^data:/i.test(u)) return u;
      if (u.startsWith('/')) return new URL(u, location.origin).href;
      return new URL('/wp-content/uploads/icon_images/' + encodeURIComponent(u), location.origin).href;
    }catch{ return u; }
  }
  let __pendingIcon = null;
  let _userIconUrl = null;
  let _userIconSize = 30;

  function setUserIcon(url, size){
    try{
      const marker = window.routeMarker || window.map?.__walker || null;
      if (!marker) { __pendingIcon = { url, size }; if(_opts.debug) console.log('[ICON] queued'); return; }

      const resolved = (typeof resolveIconUrl === 'function' ? resolveIconUrl(url) : url) || url;
      const s = Number(size) || _userIconSize || 30;

      // marker icon (klassiek)
      if (marker.setIcon) {
        marker.setIcon({
          url: resolved,
          scaledSize: new google.maps.Size(s, s),
          anchor: new google.maps.Point(Math.round(s/2), Math.round(s*0.8))
        });
      }
      // advanced marker
      if (marker.element){
        const img = marker.element.querySelector('img') || marker.element;
        img.src = resolved; img.style.width = s+'px'; img.style.height = s+'px';
      }

      // overlay bijwerken (zichtbare mannetje)
      try{
        const ovImg = document.getElementById('walkerOverlayImg');
        if (ovImg){
          ovImg.src = resolved;
          ovImg.style.width  = s + 'px';
          ovImg.style.height = s + 'px';
        }
      }catch{}

      _userIconUrl = resolved; _userIconSize = s;
      if(_opts.debug) console.log('[DEBUG] setUserIcon applied', resolved, 'size=', s);
    }catch(e){ console.warn('[setUserIcon] failed:', e); }
  }
  window.setUserIcon = setUserIcon;

  // ---------- Enige (onzichtbare) live-marker ----------
  function ensureLiveMarker(ll){
    if (!window.map || !window.google) return;

    const hasAdv = !!(google.maps.marker && google.maps.marker.AdvancedMarkerElement);
    const preferAdv = !!_opts.useAdvancedMarker;

    if (!window.map.__walker){
      let marker;
      if (preferAdv && hasAdv){
        const el = document.createElement('div');
        el.style.cssText = 'width:28px;height:28px;border-radius:50%;background:#1a73e8;border:2px solid #fff;box-shadow:0 1px 6px rgba(0,0,0,.3);transform:translate(-50%,-100%)';
        marker = new google.maps.marker.AdvancedMarkerElement({ map: window.map, position: ll, content: el, gmpClickable: false });
      } else {
        marker = new google.maps.Marker({ map: window.map, position: ll });
      }
      window.map.__walker = marker;
      window.routeMarker = marker;

      // altijd onzichtbaar houden (overlay is zichtbaar)
      try{ marker.setVisible?.(false); }catch{}
      try{ marker.element && (marker.element.style.display = 'none'); }catch{}

      if (__pendingIcon){ const { url, size } = __pendingIcon; __pendingIcon = null; setUserIcon(url, size); }
      if (_opts.debug) console.log('[DEBUG] live-marker created');
    } else {
      const m = window.map.__walker;
      if (typeof m.setPosition === 'function') m.setPosition(ll); else m.position = ll;
      window.routeMarker = m;
    }
    return window.routeMarker;
  }
  window.ensureLiveMarker = ensureLiveMarker;

  // ---------- API: laatste positie ----------
  async function getLatestPosition(deviceId){
    try {
      const q = new URLSearchParams(location.search);
      deviceId = deviceId || q.get('device_id') || 'id-mee6958o-m5gofa3bx';
    } catch { deviceId = deviceId || 'id-mee6958o-m5gofa3bx'; }

    const url = `api/user_status.php?device_id=${encodeURIComponent(deviceId)}&ttl=${_opts.ttlSeconds}&_t=${Date.now()}`;
    const res = await fetch(url, { cache: 'no-store' });
    if (!res.ok) throw new Error('HTTP '+res.status);
    const data = await res.json();
    if (!data || data.ok === false) return null;

    const ll = { lat: Number(data.lat), lng: Number(data.lon) };
    if (!Number.isFinite(ll.lat) || !Number.isFinite(ll.lng)) return null;

    // icon hint (geen spam)
    try{
      if (data.icon_url){
        const resolved = resolveIconUrl(data.icon_url);
        const newSize  = Number(data.icon_size) || _userIconSize || 30;
        if (_userIconUrl !== resolved || _userIconSize !== newSize) setUserIcon(resolved, newSize);
      }
    }catch{}

    let ts = null;
    if (typeof data.age_sec === 'number') ts = Date.now() - data.age_sec * 1000;
    else if (data.first_action){ const parsed = Date.parse(data.first_action); if(!isNaN(parsed)) ts = parsed; }

    return { ll, ts, heading: data.heading };
  }
  window.getLatestPosition = getLatestPosition;

  // ---------- Live loop ----------
  function startLive(deviceId, opts = {}) {
    if (_interval) { clearInterval(_interval); _interval = null; }
    _opts = { ...DEFAULTS, ...opts };
    _prevLL = null; _prevHeading = null; last = null;

    const tick = async () => {
  try {
    const payload = await getLatestPosition(deviceId);
    if (!payload) return;

    let { ll, heading } = payload;

    // 1) (onzichtbare) live-marker bijhouden
    ensureLiveMarker(ll);

    // 2) heading bepalen
    const hdgIn = parseHeading(heading);
    let hdg;
    if (typeof hdgIn === 'number' && !(hdgIn === 0 && _prevLL)) {
      // API-heading gebruiken, behalve als die 0 is én we al een vorig punt hebben
      hdg = hdgIn;
    } else if (_prevLL) {
      // geen bruikbare heading → bereken uit de beweging
      hdg = computeHeadingDegrees(_prevLL, ll);
    } else {
      // allereerste tick
      hdg = _prevHeading ?? 0;
    }
    if (_opts.debug) console.log('[HDG] in=', hdgIn, '→ used=', hdg, 'prev=', _prevHeading);

    // 3) (optioneel) Street View laten volgen via live.js (mag ook uit als je dit in main.js doet)
    updateStreetView(ll, hdg);

    // 4) identiek-check — BASEER op de berekende hdg (niet op ruwe heading)
    const sameLL  = last && last.ll && last.ll.lat === ll.lat && last.ll.lng === ll.lng;
    const sameHdg = (typeof last?.heading === 'number') ? angDelta(last.heading, hdg) < 0.5 : false;

    // 5) Dispatch naar main.js — stuur de BEREKENDE heading door
    if (!(sameLL && sameHdg)) {
      if (typeof _opts.onUpdate === 'function') {
        if (_opts.debug) console.log('[DISPATCH] to onUpdate → heading=', hdg);
        // Guard: nooit 0 dispatchen als we al een vorige heading hebben
if (hdg === 0 && _prevHeading != null) {
  if (_opts.debug) console.log('[HDG-GUARD] 0 replaced by prev', _prevHeading);
  hdg = _prevHeading;
}

        _opts.onUpdate({ ll, heading: hdg, ts: payload.ts || null });
      }
    }

    // 6) ALTIJD de “prev’s” bijwerken (geen early return!)
    _prevLL = ll;
    _prevHeading = hdg;
    last = { ll, heading: hdg };

  } catch (e) {
    if (_opts.debug) console.warn('tick error', e);
  }
};


    tick();
    _interval = setInterval(tick, _opts.intervalMs);
  }

  function stopLive(){ if (_interval) clearInterval(_interval); _interval = null; }

  // ---------- Public helpers ----------
  window.startLive = startLive;
  window.stopLive  = stopLive;

  // center + smooth rotate (voor main.js)
  window.__moveCamSafe = function(center, heading){
    const map = window.map; if (!map) return;
    try{
      // center
      if (center){
        if (typeof map.moveCamera === 'function') map.moveCamera({ center });
        else map.setCenter(center);
      }
      // smooth heading
      if (typeof heading === 'number') setHeadingSmooth(map, heading, 600);
    }catch{}
  };

  console.log('[LIVE] helpers OK');
})();
