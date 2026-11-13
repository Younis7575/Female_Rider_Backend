<x-filament-panels::page>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <style>
        .fr-ops-grid{display:grid;grid-template-columns:1fr;gap:1rem}
        @media(min-width:1280px){.fr-ops-grid{grid-template-columns:minmax(0,1fr) 360px}}
        .fr-kpis{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.75rem}
        @media(min-width:768px){.fr-kpis{grid-template-columns:repeat(6,minmax(0,1fr))}}
        .fr-kpi{border-radius:14px;padding:.85rem 1rem;background:var(--fr-card,#fff);border:1px solid rgba(0,0,0,.06);box-shadow:0 1px 2px rgba(0,0,0,.04)}
        .dark .fr-kpi{background:#18181b;border-color:rgba(255,255,255,.08)}
        .fr-kpi .v{font-size:1.6rem;font-weight:700;line-height:1.1}
        .fr-kpi .l{font-size:.75rem;color:#71717a;text-transform:uppercase;letter-spacing:.05em}
        #fr-map{height:640px;border-radius:16px;overflow:hidden;border:1px solid rgba(0,0,0,.08)}
        .fr-list{max-height:640px;overflow:auto;display:flex;flex-direction:column;gap:.75rem}
        .fr-card{border-radius:14px;padding:.85rem 1rem;background:#fff;border:1px solid rgba(0,0,0,.06)}
        .dark .fr-card{background:#18181b;border-color:rgba(255,255,255,.08)}
        .fr-pill{display:inline-block;border-radius:999px;padding:1px 8px;font-size:11px;font-weight:600}
        .fr-dot{width:14px;height:14px;border-radius:50%;border:2px solid #fff;box-shadow:0 0 0 2px rgba(0,0,0,.15)}
        .fr-legend span{display:inline-flex;align-items:center;gap:6px;margin-right:14px;font-size:12px;color:#52525b}
    </style>

    <div wire:poll.5s="refreshData"></div>

    <div class="fr-kpis">
        @foreach ([['online','Online drivers','#16a34a'],['available','Available','#0ea5e9'],['stale','Stale GPS','#f59e0b'],['trips','Active trips','#C8102E'],['searching','Searching','#7c3aed'],['alerts','Safety alerts','#dc2626']] as [$k,$label,$c])
            <div class="fr-kpi"><div class="l">{{ $label }}</div><div class="v" style="color:{{ $c }}">{{ $data['counts'][$k] ?? 0 }}</div></div>
        @endforeach
    </div>

    <div class="fr-legend">
        <span><i class="fr-dot" style="background:#16a34a"></i>Available driver</span>
        <span><i class="fr-dot" style="background:#C8102E"></i>On trip</span>
        <span><i class="fr-dot" style="background:#f59e0b"></i>Stale location</span>
        <span><i class="fr-dot" style="background:#7c3aed"></i>Searching request</span>
        <span><i class="fr-dot" style="background:#000"></i>SOS</span>
        <span style="float:right">Updated {{ $data['updated_at'] ?? '' }} · auto-refresh 5s</span>
    </div>

    <div class="fr-ops-grid">
        <div wire:ignore x-data="frOpsMap(@js($data))" x-init="init()" x-on:ops-data.window="update($event.detail.data)">
            <div id="fr-map"></div>
        </div>

        <div class="fr-list">
            @if (count($data['alerts']))
                <div class="fr-card" style="border-color:#fecaca;background:#fef2f2">
                    <div style="font-weight:700;color:#b91c1c;margin-bottom:.5rem">⚠ Safety alerts</div>
                    @foreach ($data['alerts'] as $a)
                        <a href="{{ $a['url'] }}" style="display:block;padding:.4rem 0;border-top:1px solid #fecaca">
                            <strong>{{ $a['reference'] }}</strong> · {{ strtoupper($a['type']) }} · {{ $a['reporter'] }}
                            <div style="font-size:12px;color:#7f1d1d">{{ ucfirst($a['status']) }} · {{ $a['since'] }}</div>
                        </a>
                    @endforeach
                </div>
            @endif

            <div class="fr-card">
                <div style="font-weight:700;margin-bottom:.5rem">Active trips ({{ count($data['trips']) }})</div>
                @forelse ($data['trips'] as $t)
                    <a href="{{ $t['url'] }}" style="display:block;padding:.5rem 0;border-top:1px solid rgba(0,0,0,.06)">
                        <span class="fr-pill" style="background:{{ $t['status']==='safety_hold' ? '#fee2e2;color:#b91c1c' : '#ffe4e6;color:#9f1239' }}">{{ $t['label'] }}</span>
                        <div style="font-size:13px;margin-top:4px"><strong>{{ $t['driver'] }}</strong> → {{ $t['customer'] }} · PKR {{ number_format($t['fare']) }}</div>
                        <div style="font-size:12px;color:#71717a">{{ \Illuminate\Support\Str::limit($t['pickup']['address'], 34) }} → {{ \Illuminate\Support\Str::limit($t['dropoff']['address'], 34) }} · {{ $t['since'] }}</div>
                    </a>
                @empty
                    <div style="font-size:13px;color:#71717a">No active trips.</div>
                @endforelse
            </div>

            <div class="fr-card">
                <div style="font-weight:700;margin-bottom:.5rem">Searching requests ({{ count($data['searching']) }})</div>
                @forelse ($data['searching'] as $s)
                    <a href="{{ $s['url'] }}" style="display:block;padding:.5rem 0;border-top:1px solid rgba(0,0,0,.06);font-size:13px">
                        <strong>{{ $s['customer'] }}</strong> · {{ $s['category'] }} · PKR {{ number_format($s['fare']) }}
                        <div style="font-size:12px;color:#71717a">{{ $s['offers'] }} offers · radius {{ $s['radius_km'] }} km · expires in {{ $s['expires_in'] }}s</div>
                    </a>
                @empty
                    <div style="font-size:13px;color:#71717a">No open requests.</div>
                @endforelse
            </div>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
        function frOpsMap(initial) {
            return {
                map: null, layer: null, fitted: false, data: initial,
                init() {
                    const boot = () => {
                        if (!window.L) { return setTimeout(boot, 100); }
                        this.map = L.map('fr-map', { zoomControl: true }).setView([24.8607, 67.0011], 12);
                        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }).addTo(this.map);
                        this.layer = L.layerGroup().addTo(this.map);
                        this.render();
                    };
                    boot();
                },
                update(d) { this.data = d; this.render(); },
                dot(color, size = 14) {
                    return L.divIcon({ className: '', html: `<div class="fr-dot" style="background:${color};width:${size}px;height:${size}px"></div>`, iconSize: [size, size] });
                },
                esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); },
                render() {
                    if (!this.map) return;
                    this.layer.clearLayers();
                    const pts = [];
                    (this.data.drivers || []).forEach(d => {
                        const color = d.stale ? '#f59e0b' : (d.busy ? '#C8102E' : '#16a34a');
                        L.marker([d.lat, d.lng], { icon: this.dot(color) }).addTo(this.layer)
                            .bindPopup(`<strong>${this.esc(d.name)}</strong><br>${this.esc(d.vehicle)}<br>${d.busy ? 'On trip' : 'Available'} · GPS ${d.age_s ?? '?'}s ago<br><a href="${d.url}">Open driver</a>`);
                        pts.push([d.lat, d.lng]);
                    });
                    (this.data.trips || []).forEach(t => {
                        L.polyline([[t.pickup.lat, t.pickup.lng], [t.dropoff.lat, t.dropoff.lng]], { color: '#C8102E', weight: 3, dashArray: '6 6', opacity: .7 }).addTo(this.layer)
                            .bindPopup(`<strong>${this.esc(t.label)}</strong><br>${this.esc(t.driver)} → ${this.esc(t.customer)}<br><a href="${t.url}">Open trip</a>`);
                        L.circleMarker([t.dropoff.lat, t.dropoff.lng], { radius: 5, color: '#7A0A12' }).addTo(this.layer);
                        pts.push([t.pickup.lat, t.pickup.lng]);
                    });
                    (this.data.searching || []).forEach(s => {
                        L.circle([s.lat, s.lng], { radius: s.radius_km * 1000, color: '#7c3aed', weight: 1, fillOpacity: .06 }).addTo(this.layer);
                        L.marker([s.lat, s.lng], { icon: this.dot('#7c3aed') }).addTo(this.layer)
                            .bindPopup(`<strong>${this.esc(s.customer)}</strong> · ${this.esc(s.category)}<br>PKR ${s.fare} · ${s.offers} offers<br><a href="${s.url}">Open request</a>`);
                        pts.push([s.lat, s.lng]);
                    });
                    (this.data.alerts || []).filter(a => a.lat).forEach(a => {
                        L.marker([a.lat, a.lng], { icon: this.dot('#000', 20) }).addTo(this.layer)
                            .bindPopup(`<strong style="color:#b91c1c">${this.esc(a.reference)} — ${this.esc(a.type.toUpperCase())}</strong><br>${this.esc(a.reporter)}<br><a href="${a.url}">Open incident</a>`);
                        pts.push([a.lat, a.lng]);
                    });
                    if (!this.fitted && pts.length) { this.map.fitBounds(pts, { padding: [40, 40], maxZoom: 14 }); this.fitted = true; }
                },
            };
        }
    </script>
</x-filament-panels::page>
