import L from 'leaflet';

const ACCENT = '#E31A22';

const TILE_URL = 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png';
const TILE_ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>';

function markerStyle(magnitude) {
    const mag = magnitude ?? 0;

    if (mag >= 6) {
        return { radius: 14, fillOpacity: 0.98, color: '#FF3340', weight: 2.5, rings: [22, 30, 38] };
    }
    if (mag >= 5) {
        return { radius: 12, fillOpacity: 0.95, color: '#FF2838', weight: 2.5, rings: [18, 26, 34] };
    }
    if (mag >= 4) {
        return { radius: 8, fillOpacity: 0.85, color: '#E31A22', weight: 1.5, rings: [] };
    }

    return { radius: 5, fillOpacity: 0.75, color: '#C4161D', weight: 1, rings: [] };
}

function formatLocal(iso) {
    const date = new Date(iso);

    return {
        date: date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }),
        time: date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }),
    };
}

function formatUtc(iso) {
    const date = new Date(iso);

    return date.toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'UTC',
        hour12: false,
    });
}

function popupHtml(event, labels) {
    const local = formatLocal(event.occurred_at);
    const mag = event.magnitude?.toFixed(1) ?? '—';
    const tsunamiLine = event.tsunami
        ? `<div class="seismo-popup-tsunami">${labels.tsunami ?? 'Tsunami'}</div>`
        : '';

    return `
        <div class="seismo-popup">
            <button type="button" class="seismo-popup-close" aria-label="${labels.close}">&times;</button>
            <div class="seismo-popup-mag">${mag}</div>
            <div class="seismo-popup-place">${event.place ?? ''}</div>
            ${tsunamiLine}
            <div class="seismo-popup-local">${local.date} ${local.time} ${labels.local}</div>
            <div class="seismo-popup-utc">${labels.utc} ${formatUtc(event.occurred_at)}</div>
        </div>
    `;
}

function normalizeEvent(event) {
    return {
        id: event.id,
        magnitude: event.magnitude ?? null,
        latitude: event.latitude ?? event.lat ?? null,
        longitude: event.longitude ?? event.lon ?? null,
        place: event.place ?? null,
        occurred_at: event.occurred_at,
        tsunami: Boolean(event.tsunami),
    };
}

class SeismoMap {
    constructor(container, events, labels) {
        this.container = container;
        this.labels = labels;
        this.markers = [];
        this.rings = [];
        this.markerRings = new Map();

        this.map = L.map(container, {
            center: [20, 0],
            zoom: 2,
            zoomControl: false,
            attributionControl: true,
        });

        this.baseLayer = L.tileLayer(TILE_URL, {
            attribution: TILE_ATTRIBUTION,
            subdomains: 'abcd',
            maxZoom: 19,
        }).addTo(this.map);

        L.control.zoom({ position: 'bottomright' }).addTo(this.map);

        L.control.scale({ imperial: false, position: 'bottomright' }).addTo(this.map);

        this.locateControl = L.control({ position: 'bottomright' });
        this.locateControl.onAdd = () => {
            const div = L.DomUtil.create('div', 'leaflet-bar leaflet-control seismo-locate-control');
            const btn = L.DomUtil.create('a', 'seismo-locate-btn', div);
            btn.href = '#';
            btn.title = this.labels.locate ?? 'Locate';
            btn.innerHTML = '&#8982;';
            btn.setAttribute('role', 'button');
            btn.setAttribute('aria-label', this.labels.locate ?? 'Locate');

            L.DomEvent.disableClickPropagation(div);
            L.DomEvent.on(btn, 'click', (e) => {
                L.DomEvent.preventDefault(e);
                this.map.locate({ setView: true, maxZoom: 6 });
            });

            return div;
        };
        this.locateControl.addTo(this.map);

        this.layersControl = L.control({ position: 'bottomright' });
        this.layersControl.onAdd = () => {
            const div = L.DomUtil.create('div', 'leaflet-bar leaflet-control seismo-layers-control');
            const btn = L.DomUtil.create('a', 'seismo-layers-btn', div);
            btn.href = '#';
            btn.title = this.labels.layers ?? 'Layers';
            btn.innerHTML = '&#9776;';
            btn.setAttribute('role', 'button');
            btn.setAttribute('aria-label', this.labels.layers ?? 'Layers');

            L.DomEvent.disableClickPropagation(div);
            L.DomEvent.on(btn, 'click', (e) => {
                L.DomEvent.preventDefault(e);
                if (this.map.hasLayer(this.baseLayer)) {
                    this.map.removeLayer(this.baseLayer);
                } else {
                    this.baseLayer.addTo(this.map);
                }
            });

            return div;
        };
        this.layersControl.addTo(this.map);

        this.setEvents(events);
    }

    clearMarkers() {
        this.markers.forEach((marker) => this.map.removeLayer(marker));
        this.rings.forEach((ring) => this.map.removeLayer(ring));
        this.markers = [];
        this.rings = [];
        this.markerRings.clear();
    }

    bindPopup(marker, event) {
        marker.bindPopup(popupHtml(event, this.labels), {
            className: 'seismo-leaflet-popup',
            closeButton: false,
        });

        marker.on('popupopen', (e) => {
            const popup = e.popup.getElement();
            const closeBtn = popup?.querySelector('.seismo-popup-close');
            closeBtn?.addEventListener('click', () => marker.closePopup());
        });
    }

    addMarkerRings(latlng, ringRadii) {
        ringRadii.forEach((ringRadius) => {
            const ring = L.circleMarker(latlng, {
                radius: ringRadius,
                fillColor: ACCENT,
                fillOpacity: 0.08,
                color: ACCENT,
                weight: 1,
                opacity: 0.35,
            }).addTo(this.map);

            this.rings.push(ring);
        });
    }

    createMarker(event) {
        const style = markerStyle(event.magnitude);
        const latlng = [event.latitude, event.longitude];

        this.addMarkerRings(latlng, style.rings);

        const marker = L.circleMarker(latlng, {
            radius: style.radius,
            fillColor: ACCENT,
            fillOpacity: style.fillOpacity,
            color: style.color,
            weight: style.weight,
        }).addTo(this.map);

        this.bindPopup(marker, event);
        marker._seismoId = event.id;
        this.markers.push(marker);

        return marker;
    }

    setEvents(events) {
        this.clearMarkers();

        events.forEach((event) => {
            const normalized = normalizeEvent(event);

            if (normalized.latitude == null || normalized.longitude == null) {
                return;
            }

            this.createMarker(normalized);
        });
    }

    upsertAndRipple(rawEvent) {
        const event = normalizeEvent(rawEvent);

        if (event.latitude == null || event.longitude == null || !event.id) {
            return;
        }

        const latlng = [event.latitude, event.longitude];
        let marker = this.markers.find((m) => m._seismoId === event.id);

        if (marker) {
            const style = markerStyle(event.magnitude);
            marker.setStyle({
                radius: style.radius,
                fillOpacity: style.fillOpacity,
                color: style.color,
                weight: style.weight,
            });
            marker.setLatLng(latlng);
            marker.getPopup()?.setContent(popupHtml(event, this.labels));
        } else {
            marker = this.createMarker(event);
        }

        this.playRipple(latlng, event.magnitude);
    }

    playRipple(latlng, magnitude) {
        const baseRadius = markerStyle(magnitude).radius;
        const ripple = L.circleMarker(latlng, {
            radius: baseRadius,
            fillColor: ACCENT,
            fillOpacity: 0.35,
            color: ACCENT,
            weight: 2,
            opacity: 0.7,
            className: 'seismo-ripple-ring',
        }).addTo(this.map);

        setTimeout(() => {
            this.map.removeLayer(ripple);
        }, 1200);
    }

    panToEvent(id) {
        const marker = this.markers.find((m) => m._seismoId === id);
        if (!marker) {
            return;
        }
        const latlng = marker.getLatLng();
        this.map.setView(latlng, Math.max(this.map.getZoom(), 5));
        marker.openPopup();
    }

    getCenter() {
        const center = this.map.getCenter();

        return { lat: center.lat, lon: center.lng };
    }
}

let instance = null;

export function initSeismoMap(container, events, labels) {
    if (instance) {
        instance.labels = labels;
        instance.setEvents(events);
        instance._events = events;

        return instance;
    }

    instance = new SeismoMap(container, events, labels);
    instance._events = events;

    return instance;
}

export function refreshSeismoMap(events) {
    if (instance) {
        instance._events = events;
        instance.setEvents(events);
    }
}

export function panSeismoMapTo(id) {
    instance?.panToEvent(id);
}

export function rippleSeismoMapEvent(event) {
    instance?.upsertAndRipple(event);
}

export function getSeismoMapCenter() {
    return instance?.getCenter() ?? null;
}
