/**
 * FemaleRider live trip share page.
 * Leaflet (window.L) is loaded from a pinned CDN build in the page <head>.
 */

const POLL_MS = 10000;

const dataEl = document.getElementById('trip-data');
const mapEl = document.getElementById('trip-map');

if (dataEl && mapEl && window.L) {
    const { statusUrl, initial } = JSON.parse(dataEl.textContent);
    const L = window.L;

    const map = L.map(mapEl, { zoomControl: false, attributionControl: true, scrollWheelZoom: false });
    L.control.zoom({ position: 'bottomright' }).addTo(map);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    }).addTo(map);

    const pinIcon = (color, label) =>
        L.divIcon({
            className: '',
            iconSize: [34, 42],
            iconAnchor: [17, 40],
            html: `<svg width="34" height="42" viewBox="0 0 34 42" aria-hidden="true"><path d="M17 41s14-13.2 14-24A14 14 0 0 0 3 17c0 10.8 14 24 14 24Z" fill="${color}" stroke="#fff" stroke-width="2.5"/><text x="17" y="22" text-anchor="middle" font-family="Inter,sans-serif" font-size="12" font-weight="700" fill="#fff">${label}</text></svg>`,
        });

    const driverIcon = L.divIcon({
        className: '',
        iconSize: [44, 44],
        iconAnchor: [22, 22],
        html: `<div class="fr-marker" style="width:44px;height:44px"><span class="fr-marker-ring"></span><span style="position:relative;display:flex;width:36px;height:36px;border-radius:9999px;background:#c8102e;border:3px solid #fff;box-shadow:0 6px 16px rgba(122,10,18,.45);align-items:center;justify-content:center"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="5.5" cy="17.5" r="3"/><circle cx="18.5" cy="17.5" r="3"/><path d="M15 6h2l3 7.5M5.5 17.5 9 10h6l3.5 7.5M9 10 7.5 6H5"/></svg></span></div>`,
    });

    let pickupLayer = null;
    let dropoffLayer = null;
    let driverMarker = null;
    let fitted = false;
    let lastUpdated = null;
    let failures = 0;
    let timer = null;

    const $ = (name) => document.querySelectorAll(`[data-field="${name}"]`);
    const setText = (name, value) => $(name).forEach((el) => (el.textContent = value ?? '—'));

    const areaLayer = (pt, color, label, title) => {
        const group = L.layerGroup();
        L.circle([pt.lat, pt.lng], { radius: 160, color, weight: 1.5, fillColor: color, fillOpacity: 0.12 }).addTo(group);
        L.marker([pt.lat, pt.lng], { icon: pinIcon(color, label), title, keyboard: false }).addTo(group);
        return group.addTo(map);
    };

    const fitAll = () => {
        const pts = [];
        if (initialState.pickup) pts.push([initialState.pickup.lat, initialState.pickup.lng]);
        if (initialState.dropoff) pts.push([initialState.dropoff.lat, initialState.dropoff.lng]);
        if (driverMarker) pts.push(driverMarker.getLatLng());
        if (pts.length === 1) map.setView(pts[0], 15);
        else if (pts.length) map.fitBounds(pts, { paddingTopLeft: [48, 64], paddingBottomRight: [48, 56], maxZoom: 16 });
        else map.setView([30.3753, 69.3451], 5); // Pakistan
    };

    const relative = (date) => {
        if (!date) return 'No location yet';
        const s = Math.max(0, Math.round((Date.now() - date.getTime()) / 1000));
        if (s < 10) return 'Updated just now';
        if (s < 60) return `Updated ${s}s ago`;
        const m = Math.round(s / 60);
        if (m < 60) return `Updated ${m} min ago`;
        return `Updated ${date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;
    };

    let initialState = initial;

    const render = (d) => {
        initialState = d;
        setText('status_label', d.status_label);
        setText('status_message', d.status_message);
        setText('driver_first_name', d.driver?.first_name);
        setText('vehicle_label', d.vehicle?.label || d.vehicle?.category);
        setText('vehicle_plate', d.vehicle?.plate_number);
        setText('pickup_address', d.pickup?.address);
        setText('dropoff_address', d.dropoff?.address);

        document.querySelectorAll('[data-step]').forEach((el) => {
            const order = ['driver_assigned', 'driver_en_route', 'arrived', 'in_progress'];
            const reached = order.indexOf(el.dataset.step) <= order.indexOf(d.status);
            el.dataset.reached = reached ? 'true' : 'false';
            if (el.dataset.step === d.status) el.setAttribute('aria-current', 'step');
            else el.removeAttribute('aria-current');
        });

        if (d.pickup && !pickupLayer) pickupLayer = areaLayer(d.pickup, '#0f766e', 'A', 'Pickup area');
        if (d.dropoff && !dropoffLayer) dropoffLayer = areaLayer(d.dropoff, '#7a0a12', 'B', 'Destination area');

        const loc = d.driver_location;
        if (loc) {
            const ll = [loc.lat, loc.lng];
            if (!driverMarker) {
                driverMarker = L.marker(ll, { icon: driverIcon, title: 'Driver location', zIndexOffset: 1000, keyboard: false }).addTo(map);
            } else {
                driverMarker.setLatLng(ll);
            }
            lastUpdated = loc.updated_at ? new Date(loc.updated_at) : null;
            document.querySelectorAll('[data-stale]').forEach((el) => el.classList.toggle('hidden', !loc.stale));
        } else {
            lastUpdated = null;
            document.querySelectorAll('[data-stale]').forEach((el) => el.classList.remove('hidden'));
        }
        setText('updated', relative(lastUpdated));

        if (!fitted) {
            fitAll();
            fitted = true;
        }
    };

    const setConnection = (ok) => {
        document.querySelectorAll('[data-connection]').forEach((el) => el.classList.toggle('hidden', ok));
    };

    const poll = async () => {
        try {
            const res = await fetch(statusUrl, {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
                credentials: 'same-origin',
            });
            if (res.status === 410 || res.status === 404) {
                window.location.reload(); // server renders the friendly "expired" page
                return;
            }
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            render(await res.json());
            failures = 0;
            setConnection(true);
        } catch (e) {
            failures += 1;
            if (failures >= 2) setConnection(false);
        } finally {
            schedule();
        }
    };

    const schedule = () => {
        clearTimeout(timer);
        const backoff = failures ? Math.min(POLL_MS * 2 ** failures, 60000) : POLL_MS;
        timer = setTimeout(poll, document.hidden ? Math.max(backoff, 30000) : backoff);
    };

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            clearTimeout(timer);
            poll();
        }
    });

    let resizeTimer = null;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => map.invalidateSize(), 150);
    });

    document.querySelectorAll('[data-recenter]').forEach((btn) => btn.addEventListener('click', fitAll));
    setInterval(() => setText('updated', relative(lastUpdated)), 5000);

    render(initial);
    schedule();
}
