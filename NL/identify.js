console.log('identify');

window.__LIVE_TAG = 'identify v001';
console.log('identify v001 gestart');

const deviceid = generateUUID();
window.deviceid = deviceid;
alert('===' + deviceid);

function generateUUID() {
    let deviceid = localStorage.getItem("deviceid");
    if (!deviceid) {
        deviceid = 'id-' + Date.now().toString(36) + '-' + Math.random().toString(36).substr(2, 9);
        localStorage.setItem("deviceid", deviceid);
    }
    return deviceid;
}