<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Orbitron:wght@500;700;800;900&display=swap" rel="stylesheet">

        <style>
            .font-orbitron {
                font-family: 'Orbitron', sans-serif;
            }
            .circuit-bg {
                background-color: #080d14;
                background-image: 
                    radial-gradient(circle at 50% 50%, rgba(0, 136, 255, 0.08) 0%, transparent 60%),
                    linear-gradient(rgba(0, 240, 255, 0.03) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(0, 240, 255, 0.03) 1px, transparent 1px);
                background-size: 100% 100%, 40px 40px, 40px 40px;
            }
            .glass-panel {
                background: rgba(15, 23, 42, 0.75);
                backdrop-filter: blur(20px);
                -webkit-backdrop-filter: blur(20px);
                border: 1px solid rgba(0, 240, 255, 0.25);
                box-shadow: 0 20px 40px rgba(0, 0, 0, 0.7), 0 0 25px rgba(0, 240, 255, 0.1);
            }
            .sci-input {
                background: rgba(15, 23, 42, 0.9);
                border: 1px solid rgba(0, 240, 255, 0.3);
                color: #f8fafc;
                transition: all 0.3s ease;
            }
            .sci-input:focus {
                outline: none;
                border-color: #00f0ff;
                box-shadow: 0 0 20px rgba(0, 240, 255, 0.4), inset 0 0 10px rgba(0, 240, 255, 0.2);
            }
            .btn-cyber {
                background: linear-gradient(135deg, #0088ff 0%, #00f0ff 100%);
                border: 1px solid rgba(0, 240, 255, 0.6);
                box-shadow: 0 0 20px rgba(0, 136, 255, 0.4);
                transition: all 0.3s ease;
            }
            .btn-cyber:hover {
                box-shadow: 0 0 35px rgba(0, 240, 255, 0.8);
                transform: translateY(-2px);
            }
        </style>

        <!-- Scripts -->
        @routes
        @viteReactRefresh
        @vite(['resources/js/app.jsx', "resources/js/Pages/{$page['component']}.jsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
