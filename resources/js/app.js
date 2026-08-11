import './bootstrap';

// Alpine.js is provided by Livewire (@livewireScripts). Keep alpinejs in
// package.json for Vite tooling / future non-Livewire islands.

if (window.Echo) {
    window.Echo.channel('earthquakes')
        .listen('.EarthquakeDetected', (payload) => {
            console.info('[Seismo] EarthquakeDetected', payload);
            window.dispatchEvent(new CustomEvent('seismo:earthquake-detected', { detail: payload }));
        });

    console.info('[Seismo] Subscribed to public channel earthquakes');
}
