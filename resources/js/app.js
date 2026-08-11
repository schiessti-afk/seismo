import './bootstrap';
import 'leaflet/dist/leaflet.css';
import {
    initSeismoMap,
    refreshSeismoMap,
    panSeismoMapTo,
    rippleSeismoMapEvent,
    getSeismoMapCenter,
} from './map';

window.SeismoMap = {
    init: initSeismoMap,
    refresh: refreshSeismoMap,
    panTo: panSeismoMapTo,
    ripple: rippleSeismoMapEvent,
    getCenter: getSeismoMapCenter,
};

function handleLivewireComponent() {
    const component = Livewire.first();

    if (!component) {
        return null;
    }

    return component;
}

if (window.Echo) {
    window.Echo.channel('earthquakes')
        .listen('.EarthquakeDetected', (payload) => {
            window.dispatchEvent(new CustomEvent('seismo:earthquake-detected', { detail: payload }));

            const component = handleLivewireComponent();

            if (component) {
                component.call('onLiveEarthquake', payload).then((matched) => {
                    if (!matched) {
                        return;
                    }
                });
            }
        });
}

document.addEventListener('DOMContentLoaded', () => {
    const mapEl = document.getElementById('seismo-map');
    const payloadEl = document.getElementById('seismo-map-data');

    if (mapEl && payloadEl) {
        const events = JSON.parse(payloadEl.textContent);
        const labels = JSON.parse(payloadEl.dataset.labels ?? '{}');
        initSeismoMap(mapEl, events, labels);
    }
});

document.addEventListener('livewire:init', () => {
    Livewire.on('seismo-map-refresh', ({ events }) => {
        refreshSeismoMap(events);
    });

    Livewire.on('seismo-map-ripple', ({ mapEvent }) => {
        rippleSeismoMapEvent(mapEvent);
    });

    Livewire.on('seismo-window-changed', ({ hours }) => {
        localStorage.setItem('seismo.liveWindowHours', String(hours));
    });

    Livewire.on('seismo-mode-changed', ({ mode }) => {
        localStorage.setItem('seismo.mode', mode);
    });

    Livewire.on('seismo-scrubber-changed', ({ at }) => {
        localStorage.setItem('seismo.historyScrubAt', at);
    });
});

window.addEventListener('seismo-pan-to', (event) => {
    const id = event.detail.id ?? event.detail[0]?.id;
    if (id) {
        panSeismoMapTo(id);
    }
});
