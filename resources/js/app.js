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

function getAlertMinMagnitude() {
    const shell = document.querySelector('.seismo-shell');
    const value = parseFloat(shell?.dataset.alertMinMagnitude ?? '5');

    return Number.isNaN(value) ? 5 : value;
}

function isLiveMode() {
    const shell = document.querySelector('.seismo-shell');

    return shell?.dataset.mode === 'live';
}

function shouldPlayAlertSound(payload) {
    if (localStorage.getItem('seismo.soundEnabled') !== 'true') {
        return false;
    }

    if (!isLiveMode()) {
        return false;
    }

    const magnitude = payload.magnitude ?? 0;
    const tsunami = Boolean(payload.tsunami);
    const alertMin = getAlertMinMagnitude();

    return magnitude >= alertMin || tsunami;
}

function playAlertTone() {
    try {
        const AudioContext = window.AudioContext || window.webkitAudioContext;

        if (!AudioContext) {
            return;
        }

        const ctx = new AudioContext();
        const oscillator = ctx.createOscillator();
        const gain = ctx.createGain();

        oscillator.type = 'sine';
        oscillator.connect(gain);
        gain.connect(ctx.destination);
        oscillator.frequency.value = 880;
        gain.gain.setValueAtTime(0.12, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.45);
        oscillator.start(ctx.currentTime);
        oscillator.stop(ctx.currentTime + 0.45);
    } catch {
        // Web Audio unavailable
    }
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

                    if (shouldPlayAlertSound(payload)) {
                        playAlertTone();
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
