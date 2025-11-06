// =============== Globals ===============
let map, panorama, routePolyline = null;
let deviceId = window.deviceid;

let walkerMarker = null;
let __lastLL = null;
let __lastHeading = 0;
fetchStatus(); // wacht tot klaar fetchStatus();

// =============== Init Map ===============
function initMap() {
  map = new google.maps.Map(document.getElementById("map"), {
    center: { lat: 52.3813, lng: 5.1986 },
    zoom: 18,
    mapId: "3ef452ceeafb211310cef9f3", // jouw vector map ID
    tilt: 0,
    heading: 0,
    gestureHandling: "greedy",
    streetViewControl: false,  //geen geel pegmannetje
    scrollwheel: true
  });

  panorama = new google.maps.StreetViewPanorama(
    document.getElementById("pano"),
    { position: { lat: 52.3813, lng: 5.1986 }, pov: { heading: 150, pitch: 0 } }
  );

  //map.setStreetView(panorama);

  // ✅ Zet de listener hierna
  panorama.addListener("pov_changed", () => {
    const pov = panorama.getPov();
    if (__lastHeading !== null && Math.abs(pov.heading - __lastHeading) > 5) {
      console.log("[DEBUG] POV drift → correctie", pov.heading, "→", __lastHeading);
      panorama.setPov({ heading: __lastHeading, pitch: 0 });
    }
  });


  map.setStreetView(panorama);
  const img = document.createElement("img");
  img.src = window.location.origin + "/wp-content/uploads/icon_images/" + window.usericon;
  img.style.width = "40px";

  walkerMarker = new google.maps.marker.AdvancedMarkerElement({
    map,
    position: { lat: 52.3813, lng: 5.1986 },
    content: img,
    title: "Jij",
  });

  // Click-event per marker
  walkerMarker.addListener("click", () => {
    alert("nog ff over denken");
  });

  // 🔧 fetch pas als map er is
  setTimeout(() => {
    if (map) {
      fetchRoute();
    } else {
      console.warn("[ROUTE] map nog niet klaar, overslaan");
    }
  }, 500);
  fetchPois();
  fetchStatus();
  setInterval(fetchStatus, 3000);

  // annuleren van recenter bij user interactie
  map.addListener("dragstart", () => {
    if (recenterTimeout) clearTimeout(recenterTimeout);
  });
  map.addListener("zoom_changed", () => {
    if (recenterTimeout) clearTimeout(recenterTimeout);
  });
}
window.initMap = initMap;

// =============== Route ophalen ===============
async function fetchRoute() {
  console.log("[ROUTE] fetchRoute gestart...");
  if (!map) {
    console.warn("[ROUTE] map bestaat nog niet → skip");
    return;
  }
  try {
    const res = await fetch("api/get_route.php", { cache: "no-store" });
    const data = await res.json();

    console.log("[ROUTE] raw data:", data);

    if (Array.isArray(data) && data.length > 0) {
      console.log("[ROUTE] punten:", data.length);
      if (routePolyline) routePolyline.setMap(null);
      routePolyline = new google.maps.Polyline({
        path: data,
        geodesic: true,
        strokeColor: "#FF0000",
        strokeOpacity: 1.0,
        strokeWeight: 2,
        map
      });
      console.log("[ROUTE] polyline getekend");
    } else {
      console.warn("[ROUTE] geen array ontvangen of leeg:", data);
    }
  } catch (err) {
    console.error("[ROUTE] fetchRoute error", err);
  }
}

let poiMarkers = []; // globaal array voor markers

async function fetchPois() {
  try {
    const res = await fetch("api/get_pois.php", { cache: "no-store" });
    const data = await res.json();

    console.log("[POI] raw data:", data);

    // Data voorbereiden
    const points = Array.isArray(data) ? data : data.points || [];
    console.log("[POI] punten:", points.length);

    // Oude markers verwijderen
    poiMarkers.forEach(m => {
      if (m.map) m.map = null;
    });
    poiMarkers = [];

    // Nieuwe markers plaatsen
    points.forEach(poi => {
      const iconUrl = window.location.origin + "/wp-content/uploads/icon_images/" + poi.icon;
      console.log("[POI] icon:", poi.icon, "→", iconUrl);

      // Maak een img-element voor het icoon
      const img = document.createElement("img");
      img.src = iconUrl;
      img.style.width = "50px";
      img.style.height = "55px";

      // Maak de marker (werkt op vector maps)
      const marker = new google.maps.marker.AdvancedMarkerElement({
        map,
        position: { lat: Number(poi.lat), lng: Number(poi.lng) },
        content: img,
        title: poi.name || "POI"
      });

      // Click-event
      marker.addListener("click", () => {
        alert(`POI: ${poi.name}\n(${poi.lat}, ${poi.lng})`);
      });

      poiMarkers.push(marker);
    });

  } catch (e) {
    console.error("[POI] fetchPois error:", e);
  }
}



// =============== Walker Updaten ===============

let recenterTimeout = null;

let smoothCenterTimer = null;
function animateCenter(targetLatLng) {
  if (smoothCenterTimer) clearInterval(smoothCenterTimer);

  smoothCenterTimer = setInterval(() => {
    const current = map.getCenter();
    if (!current) return;

    const step = 0.1;
    const newLat = current.lat() + (targetLatLng.lat() - current.lat()) * step;
    const newLng = current.lng() + (targetLatLng.lng() - current.lng()) * step;

    map.setCenter(new google.maps.LatLng(newLat, newLng));

    if (Math.abs(newLat - targetLatLng.lat()) < 0.00001 &&
      Math.abs(newLng - targetLatLng.lng()) < 0.00001) {
      clearInterval(smoothCenterTimer);
      smoothCenterTimer = null;
    }
  }, 100);
}
// ===== computeHeading helper =====
function computeHeading(fromLL, toLL) {
  const dLat = (toLL.lat - fromLL.lat) * Math.PI / 180;
  const dLng = (toLL.lng - fromLL.lng) * Math.PI / 180;
  const y = Math.sin(dLng) * Math.cos(toLL.lat * Math.PI / 180);
  const x =
    Math.cos(fromLL.lat * Math.PI / 180) * Math.sin(toLL.lat * Math.PI / 180) -
    Math.sin(fromLL.lat * Math.PI / 180) * Math.cos(toLL.lat * Math.PI / 180) * Math.cos(dLng);
  return (Math.atan2(y, x) * 180 / Math.PI + 360) % 360;
}

// ===== fetchStatus (final, no smoothing) =====


function onGoogleReady() {
  console.log("Google Maps klaar");
  initMap();
}

async function fetchStatus() {
  console.log("[xxDEBUG] fetchStatus called");
  try {
    const url = `api/user_status.php?device_id=${encodeURIComponent(deviceId)}&_t=${Date.now()}`;
    const res = await fetch(url, { cache: "no-store" });
    const data = await res.json();
    console.log("User status:", data);
    window.usericon = data.icon;
    if (map == null) {
      //initMap();
      onGoogleReady();
    }
    const lat = Number(data.lat);
    const lng = Number(data.lon ?? data.lng);
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;

    let h = Number(data.heading) || 0;

    // Bereken heading uit beweging als GPS geen heading geeft
    if ((!h || h === 0) && __lastLL) {
      h = computeHeading(__lastLL, { lat, lng });
    }

    // ⚡ Geen smoothing meer → altijd directe heading gebruiken
    updateWalker(lat, lng, h);

    __lastLL = { lat, lng };
    __lastHeading = h;
  } catch (e) {
    console.error("fetchStatus error", e);
  }
}
function offsetLatLng(lat, lng, meters) {
  const earth = 6378137; // straal aarde in meters
  const dir = Math.random() * 2 * Math.PI; // willekeurige richting
  const dLat = (meters * Math.cos(dir)) / earth;
  const dLng = (meters * Math.sin(dir)) / (earth * Math.cos(lat * Math.PI / 180));

  return new google.maps.LatLng(
    lat + dLat * (180 / Math.PI),
    lng + dLng * (180 / Math.PI)
  );
}

// ===== updateWalker (final) =====



function updateWalker(lat, lng, heading) {
  console.log('in updatewalker, heading=' + heading);
  if (!walkerMarker || heading == 0) return;   //dus dit was 't   Geen update if heading = 0.  Goed gedaan, Bart

  const ll = new google.maps.LatLng(lat, lng);
  const shifted = offsetLatLng(ll.lat(), ll.lng(), 0); // 0 meter verschuiven BART dit uis voor maatjes in simulatie
  walkerMarker.position = shifted;

  if (typeof map.moveCamera === "function") {
    map.moveCamera({
center: walkerMarker.position,
heading: heading,
tilt: map.getTilt()
    });
  } else {
    if (typeof map.setHeading === "function") {
      map.setHeading(heading);
     map.setCenter(walkerMarker.position);
    }
  }

  if (panorama) {
    panorama.setPosition(ll);
    panorama.setPov({ heading: heading, pitch: 0 });
  }

  updateDebug(lat, lng, heading || 0);
}



// ===== updateWalker (clean test) =====
// ===== updateWalker (map-only test) =====
function updateWalker2(lat, lng, heading) {
  if (!walkerMarker) return;

  const ll = new google.maps.LatLng(lat, lng);
  walkerMarker.setPosition(ll);

  console.log("[DEBUG] updateWalker heading IN →", heading);

  if (typeof map.moveCamera === "function") {
    map.moveCamera({
      center: walkerMarker.getPosition(),
      heading: heading,
      tilt: map.getTilt()
    });
  } else if (typeof map.setHeading === "function") {
    map.setHeading(heading);
    map.setCenter(walkerMarker.getPosition());
  }

  // Na een tikje delay checken wat Maps er van maakte
  setTimeout(() => {
    console.log("[DEBUG] map.getHeading() AFTER →", map.getHeading?.());
  }, 200);

  updateDebug(lat, lng, heading || 0);
}





// =============== Debug ===============
function updateDebug(lat, lng, heading) {
  var timestamp = new Date(); // get current timestamp from now

  const tilt = (map.getTilt && map.getTilt()) ? map.getTilt() : 0;
  document.getElementById("dbgHeading").textContent = heading.toFixed(1);
  document.getElementById("dbgTilt").textContent = tilt;
  document.getElementById("dbgLat").textContent = lat.toFixed(6);
  document.getElementById("dbgLng").textContent = lng.toFixed(6);
  document.getElementById("deTijd").innerHTML = `${timestamp.getHours()}:${timestamp.getMinutes()}:${timestamp.getSeconds()}`;


}


//element.innerHTML = `${timestamp.getHours()}:${timestamp.getMinutes()}:${timestamp.getSeconds()}`;

function checkTime(i) {
  if (i < 10) {
    i = "0" + i;
  }
  return i;
}

function startTime() {
  var today = new Date();
  var h = today.getHours();
  var m = today.getMinutes();
  var s = today.getSeconds();

  m = checkTime(m);
  s = checkTime(s);
  document.getElementById('entryTime').innerHTML = h + ":" + m + ":" + s;
  t = setTimeout(function () {
    startTime()
  }, 500);
}
