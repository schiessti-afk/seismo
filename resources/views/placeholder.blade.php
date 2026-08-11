<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', 'Seismo') }}</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
        @livewireStyles
        <style>
            :root {
                --seismo-bg: #1a1a1a;
                --seismo-accent: #e31a22;
                --seismo-text: #f2f2f2;
                --seismo-muted: #8a8a8a;
            }

            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                min-height: 100vh;
                display: grid;
                place-items: center;
                font-family: "Segoe UI", "Helvetica Neue", sans-serif;
                background:
                    radial-gradient(ellipse at 20% 0%, rgba(227, 26, 34, 0.18), transparent 45%),
                    radial-gradient(ellipse at 80% 100%, rgba(227, 26, 34, 0.08), transparent 40%),
                    var(--seismo-bg);
                color: var(--seismo-text);
            }

            main {
                text-align: center;
                padding: 2rem;
            }

            .brand {
                margin: 0;
                font-size: clamp(3rem, 10vw, 5.5rem);
                font-weight: 800;
                letter-spacing: 0.18em;
                color: var(--seismo-accent);
            }

            .tagline {
                margin: 1rem 0 0;
                color: var(--seismo-muted);
                font-size: 1rem;
                letter-spacing: 0.04em;
            }
        </style>
    </head>
    <body>
        <main>
            <h1 class="brand">SEISMO</h1>
            <p class="tagline">{{ __('seismo.placeholder_tagline') }}</p>
        </main>
        @livewireScripts
    </body>
</html>
