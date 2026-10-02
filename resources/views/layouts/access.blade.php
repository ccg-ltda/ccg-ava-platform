<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'CCG Platform - Login')</title>
    <script>
        try { if (localStorage.getItem('ccg-theme') === 'dark') { document.documentElement.className = 'dark'; } } catch (e) {}
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;500;600;700;800;900&family=Rajdhani:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-primary: #0a0a0f;
            --bg-secondary: #12121a;
            --bg-card: rgba(18, 18, 26, 0.85);
            --text-primary: #e0e0e8;
            --text-secondary: #8a8a9a;
            --text-muted: #5a5a6a;
            --neon-cyan: #00f0ff;
            --neon-pink: #ff2d95;
            --neon-purple: #b44dff;
            --neon-green: #39ff14;
            --border-color: rgba(0, 240, 255, 0.2);
            --card-shadow: 0 0 30px rgba(0, 240, 255, 0.1), inset 0 0 30px rgba(0, 240, 255, 0.05);
            --input-bg: rgba(20, 20, 30, 0.9);
            --input-border: rgba(0, 240, 255, 0.3);
            --grid-color: rgba(0, 240, 255, 0.04);
            --overlay-color: rgba(10, 10, 15, 0.7);
            --svg-line-color: rgba(0, 240, 255, 0.4);
            --toast-bg: rgba(18, 18, 26, 0.95);
            --clip-polygon: polygon(0 0, 100% 0, 100% 85%, 95% 85%, 95% 100%, 5% 100%, 5% 85%, 0 85%);
        }

        html.dark {
            --bg-primary: #0a0a0f;
            --bg-secondary: #12121a;
            --bg-card: rgba(18, 18, 26, 0.85);
            --text-primary: #e0e0e8;
            --text-secondary: #8a8a9a;
            --text-muted: #5a5a6a;
            --neon-cyan: #00f0ff;
            --neon-pink: #ff2d95;
            --neon-purple: #b44dff;
            --neon-green: #39ff14;
            --border-color: rgba(0, 240, 255, 0.2);
            --card-shadow: 0 0 30px rgba(0, 240, 255, 0.15), inset 0 0 30px rgba(0, 240, 255, 0.05);
            --input-bg: rgba(20, 20, 30, 0.9);
            --input-border: rgba(0, 240, 255, 0.3);
            --grid-color: rgba(0, 240, 255, 0.04);
            --overlay-color: rgba(10, 10, 15, 0.7);
            --svg-line-color: rgba(0, 240, 255, 0.4);
            --toast-bg: rgba(18, 18, 26, 0.95);
        }

        html.light {
            --bg-primary: #e8eaf0;
            --bg-secondary: #d0d4e0;
            --bg-card: rgba(255, 255, 255, 0.88);
            --text-primary: #1a1a2e;
            --text-secondary: #4a4a6a;
            --text-muted: #8a8aa0;
            --neon-cyan: #0090a0;
            --neon-pink: #c02060;
            --neon-purple: #7030b0;
            --neon-green: #20a010;
            --border-color: rgba(0, 144, 160, 0.25);
            --card-shadow: 0 0 30px rgba(0, 144, 160, 0.1), inset 0 0 30px rgba(0, 144, 160, 0.03);
            --input-bg: rgba(245, 245, 250, 0.95);
            --input-border: rgba(0, 144, 160, 0.35);
            --grid-color: rgba(0, 144, 160, 0.06);
            --overlay-color: rgba(232, 234, 240, 0.85);
            --svg-line-color: rgba(0, 144, 160, 0.45);
            --toast-bg: rgba(255, 255, 255, 0.95);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            width: 100%;
            min-height: 100%;
            overflow-x: hidden;
            font-family: 'Rajdhani', sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            transition: background 0.5s ease, color 0.5s ease;
        }

        .cyber-grid-bg {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            background-color: var(--bg-primary);
            background-image:
                linear-gradient(var(--grid-color) 1px, transparent 1px),
                linear-gradient(90deg, var(--grid-color) 1px, transparent 1px);
            background-size: 60px 60px;
            transition: background-color 0.5s ease, background-image 0.5s ease;
        }

        .cyber-grid-bg::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(ellipse at center, transparent 0%, var(--bg-primary) 70%);
            transition: background 0.5s ease;
        }

        #three-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 1;
            pointer-events: none;
        }

        #three-container canvas {
            display: block;
        }

        .svg-connections {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 5;
            pointer-events: none;
        }

        .svg-connections line {
            stroke: var(--svg-line-color);
            stroke-width: 1;
            stroke-dasharray: 4 4;
            animation: dashMove 2s linear infinite;
            transition: stroke 0.5s ease;
        }

        @keyframes dashMove {
            to { stroke-dashoffset: -8; }
        }

        .theme-toggle {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            border: 2px solid var(--border-color);
            background: var(--bg-card);
            color: var(--neon-cyan);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            transition: all 0.4s ease;
            backdrop-filter: blur(10px);
            box-shadow: 0 0 20px rgba(0, 240, 255, 0.15);
            clip-path: polygon(0 0, 100% 0, 100% 85%, 95% 85%, 95% 100%, 5% 100%, 5% 85%, 0 85%);
        }

        .theme-toggle:hover {
            background: var(--input-bg);
            box-shadow: 0 0 30px rgba(0, 240, 255, 0.3);
            transform: scale(1.1);
        }

        .theme-toggle:focus {
            outline: 2px solid var(--neon-cyan);
            outline-offset: 3px;
        }

        .login-wrapper {
            position: relative;
            z-index: 10;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            min-height: 100vh;
            min-height: 100dvh;
            padding: 20px;
        }

        .login-card {
            width: 100%;
            max-width: 440px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 0;
            padding: 40px 35px;
            backdrop-filter: blur(20px);
            box-shadow: var(--card-shadow);
            clip-path: none;
            transition: all 0.5s ease;
            position: relative;
            overflow: visible;
        }

        .login-card::before {
            content: '';
            position: absolute;
            top: -1px;
            left: -1px;
            right: -1px;
            bottom: -1px;
            border: 1px solid var(--border-color);
            border-radius: 0;
            pointer-events: none;
            clip-path: none;
        }

        .logo-section {
            text-align: center;
            margin-bottom: 35px;
        }

        .logo-section .logo-icon {
            width: 70px;
            height: 70px;
            margin: 0 auto 15px;
            border: 2px solid var(--neon-cyan);
            display: flex;
            align-items: center;
            justify-content: center;
            clip-path: polygon(50% 0%, 100% 25%, 100% 75%, 50% 100%, 0% 75%, 0% 25%);
            box-shadow: 0 0 20px rgba(0, 240, 255, 0.3);
            transition: all 0.5s ease;
        }

        .logo-icon svg {
            width: 36px;
            height: 36px;
            fill: var(--neon-cyan);
        }

        .logo-section h1 {
            font-family: 'Orbitron', monospace;
            font-size: 22px;
            font-weight: 700;
            letter-spacing: 3px;
            color: var(--neon-cyan);
            text-transform: uppercase;
            text-shadow: 0 0 15px rgba(0, 240, 255, 0.5);
            margin-bottom: 5px;
            transition: all 0.5s ease;
        }

        .logo-section p {
            font-family: 'Rajdhani', sans-serif;
            font-size: 14px;
            font-weight: 400;
            color: var(--text-secondary);
            letter-spacing: 1px;
            transition: color 0.5s ease;
        }

        .input-group {
            position: relative;
            margin-bottom: 25px;
        }

        .input-group label {
            display: block;
            font-family: 'Rajdhani', sans-serif;
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: var(--text-secondary);
            margin-bottom: 8px;
            transition: color 0.5s ease;
        }

        .input-group input {
            width: 100%;
            padding: 14px 45px 14px 15px;
            background: var(--input-bg);
            border: 1px solid var(--input-border);
            color: var(--text-primary);
            font-family: 'Rajdhani', sans-serif;
            font-size: 15px;
            font-weight: 500;
            letter-spacing: 1px;
            outline: none;
            transition: all 0.3s ease;
            clip-path: polygon(0 0, 100% 0, 100% 80%, 97% 80%, 97% 100%, 3% 100%, 3% 80%, 0 80%);
        }

        .input-group input::placeholder {
            color: var(--text-muted);
        }

        .input-group input:focus {
            border-color: var(--neon-cyan);
            box-shadow: 0 0 15px rgba(0, 240, 255, 0.2), inset 0 0 10px rgba(0, 240, 255, 0.05);
        }

        .input-group input.error {
            border-color: var(--neon-pink);
            box-shadow: 0 0 15px rgba(255, 45, 149, 0.2);
        }

        .input-group input.success {
            border-color: var(--neon-green);
            box-shadow: 0 0 15px rgba(57, 255, 20, 0.15);
        }

        .password-wrapper {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: color 0.3s ease;
            z-index: 2;
        }

        .password-toggle:hover {
            color: var(--neon-cyan);
        }

        .password-toggle svg {
            width: 20px;
            height: 20px;
            fill: none;
            stroke: currentColor;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .error-message {
            font-size: 12px;
            color: var(--neon-pink);
            margin-top: 6px;
            letter-spacing: 0.5px;
            display: none;
            font-weight: 500;
        }

        .error-message.show {
            display: block;
        }

        .remember-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 30px;
        }

        .remember-row label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: var(--text-secondary);
            cursor: pointer;
            transition: color 0.5s ease;
        }

        .remember-row label input[type="checkbox"] {
            accent-color: var(--neon-cyan);
            width: 16px;
            height: 16px;
        }

        .remember-row a {
            font-size: 13px;
            color: var(--neon-cyan);
            text-decoration: none;
            letter-spacing: 1px;
            transition: all 0.3s ease;
        }

        .remember-row a:hover {
            text-shadow: 0 0 10px rgba(0, 240, 255, 0.5);
        }

        .login-btn {
            width: 100%;
            padding: 15px;
            background: transparent;
            border: 2px solid var(--neon-cyan);
            color: var(--neon-cyan);
            font-family: 'Orbitron', monospace;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 4px;
            text-transform: uppercase;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: all 0.4s ease;
            clip-path: polygon(0 0, 100% 0, 100% 85%, 95% 85%, 95% 100%, 5% 100%, 5% 85%, 0 85%);
        }

        .login-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(0, 240, 255, 0.15), transparent);
            transition: left 0.5s ease;
        }

        .login-btn:hover::before {
            left: 100%;
        }

        .login-btn:hover {
            background: rgba(0, 240, 255, 0.1);
            box-shadow: 0 0 25px rgba(0, 240, 255, 0.3);
            text-shadow: 0 0 10px rgba(0, 240, 255, 0.5);
        }

        .login-btn:focus {
            outline: 2px solid var(--neon-cyan);
            outline-offset: 3px;
        }

        .login-btn:active {
            transform: scale(0.98);
        }

        .footer-text {
            text-align: center;
            margin-top: 25px;
            font-size: 12px;
            color: var(--text-muted);
            letter-spacing: 1px;
            transition: color 0.5s ease;
        }

        .footer-text a {
            color: var(--neon-purple);
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .footer-text a:hover {
            text-shadow: 0 0 10px rgba(180, 77, 255, 0.5);
        }

        .toast-container {
            position: fixed;
            top: 90px;
            right: 20px;
            z-index: 2000;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .toast {
            padding: 14px 20px;
            background: var(--toast-bg);
            border: 1px solid var(--border-color);
            border-left: 3px solid var(--neon-cyan);
            color: var(--text-primary);
            font-family: 'Rajdhani', sans-serif;
            font-size: 14px;
            font-weight: 500;
            letter-spacing: 0.5px;
            backdrop-filter: blur(15px);
            box-shadow: 0 5px 25px rgba(0, 0, 0, 0.3);
            transform: translateX(120%);
            transition: transform 0.4s ease, opacity 0.4s ease;
            opacity: 0;
            clip-path: polygon(0 0, 100% 0, 100% 85%, 95% 85%, 95% 100%, 5% 100%, 5% 85%, 0 85%);
        }

        .toast.show {
            transform: translateX(0);
            opacity: 1;
        }

        .toast.error {
            border-left-color: var(--neon-pink);
        }

        .toast.success {
            border-left-color: var(--neon-green);
        }

        .toast.warning {
            border-left-color: #ffaa00;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .pulse {
            animation: pulse 2s ease-in-out infinite;
        }

        @media (max-width: 480px) {
            .login-wrapper {
                padding-top: 75px;
            }
            .login-card {
                padding: 30px 20px;
            }
            .logo-section h1 {
                font-size: 18px;
            }
            .theme-toggle {
                width: 48px;
                height: 48px;
                top: 15px;
                right: 15px;
                font-size: 20px;
            }
        }

        /* Workspace selection (reuses the existing tokens) */
        .workspace-badge {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 25px;
            padding: 10px 15px;
            background: var(--input-bg);
            border: 1px solid var(--input-border);
            font-size: 13px;
            letter-spacing: 1px;
            color: var(--text-secondary);
            transition: all 0.5s ease;
        }

        .workspace-badge strong {
            font-family: 'Orbitron', monospace;
            font-size: 12px;
            letter-spacing: 2px;
            color: var(--neon-cyan);
        }

        .workspace-badge button {
            background: none;
            border: none;
            padding: 0;
            font: inherit;
            letter-spacing: 1px;
            color: var(--neon-cyan);
            cursor: pointer;
        }

        .workspace-badge button:hover {
            text-shadow: 0 0 10px rgba(0, 240, 255, 0.5);
        }
    </style>
</head>
<body>
    <div class="cyber-grid-bg" id="cyberGrid"></div>
    <div id="three-container"></div>
    <svg class="svg-connections" id="svgConnections"></svg>

    <button class="theme-toggle" id="themeToggle" aria-label="Toggle theme">
        <span id="themeIcon">🌙</span>
    </button>

    <div class="toast-container" id="toastContainer"></div>

    <div class="login-wrapper">
        <div class="login-card" id="loginCard">
            <div class="logo-section">
                <div class="logo-icon">
                    <svg viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
                </div>
                <h1>CCG Platform</h1>
                <p>Secure Access Portal</p>
            </div>

            @yield('content')
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
    <script>
        (function() {
            'use strict';

            /* ===== Theme Toggle ===== */
            const htmlEl = document.documentElement;
            const themeToggle = document.getElementById('themeToggle');
            const themeIcon = document.getElementById('themeIcon');

            function setTheme(isDark) {
                if (isDark) {
                    htmlEl.classList.remove('light');
                    htmlEl.classList.add('dark');
                    themeIcon.textContent = '☀️';
                } else {
                    htmlEl.classList.remove('dark');
                    htmlEl.classList.add('light');
                    themeIcon.textContent = '🌙';
                }
                applyParticleTheme();
                applyParticleTheme();
                updateConnections();
                try { localStorage.setItem('ccg-theme', isDark ? 'dark' : 'light'); } catch (e) {}
            }

            themeToggle.addEventListener('click', function() {
                const isDark = htmlEl.classList.contains('light');
                setTheme(isDark);
                showToast(isDark ? 'Modo Oscuro activado' : 'Modo Claro activado', 'success');
            });

            /* ===== Toast System ===== */
            function showToast(message, type) {
                type = type || 'info';
                var container = document.getElementById('toastContainer');
                var toast = document.createElement('div');
                toast.className = 'toast ' + type;
                toast.textContent = message;
                container.appendChild(toast);
                requestAnimationFrame(function() {
                    toast.classList.add('show');
                });
                setTimeout(function() {
                    toast.classList.remove('show');
                    setTimeout(function() {
                        if (toast.parentNode) toast.parentNode.removeChild(toast);
                    }, 400);
                }, 3000);
            }

            /* ===== Three.js Particle Sphere ===== */
            var container = document.getElementById('three-container');
            var scene = new THREE.Scene();
            var camera = new THREE.PerspectiveCamera(75, window.innerWidth / window.innerHeight, 0.1, 1000);
            var renderer = new THREE.WebGLRenderer({ alpha: true, antialias: true });
            renderer.setSize(window.innerWidth, window.innerHeight);
            renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
            container.appendChild(renderer.domElement);

            var particleCount = 2000;
            var positions = new Float32Array(particleCount * 3);
            var colors = new Float32Array(particleCount * 3);
            var speeds = new Float32Array(particleCount);

            var neonCyan = new THREE.Color(0x00f0ff);
            var neonPink = new THREE.Color(0xff2d95);
            var neonPurple = new THREE.Color(0xb44dff);
            var neonGreen = new THREE.Color(0x39ff14);

            for (var i = 0; i < particleCount; i++) {
                var theta = Math.random() * Math.PI * 2;
                var phi = Math.acos(2 * Math.random() - 1);
                var r = 2 + Math.random() * 0.5;

                positions[i * 3] = r * Math.sin(phi) * Math.cos(theta);
                positions[i * 3 + 1] = r * Math.sin(phi) * Math.sin(theta);
                positions[i * 3 + 2] = r * Math.cos(phi);

                speeds[i] = 0.002 + Math.random() * 0.005;

                var colorChoice = Math.random();
                var color;
                if (colorChoice < 0.33) color = neonCyan;
                else if (colorChoice < 0.66) color = neonPurple;
                else color = neonPink;

                colors[i * 3] = color.r;
                colors[i * 3 + 1] = color.g;
                colors[i * 3 + 2] = color.b;
            }

            var geometry = new THREE.BufferGeometry();
            geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
            geometry.setAttribute('color', new THREE.BufferAttribute(colors, 3));

            var material = new THREE.PointsMaterial({
                size: 0.022,
                vertexColors: true,
                transparent: true,
                opacity: 0.85,
                blending: THREE.AdditiveBlending,
                depthWrite: false
            });

            var bgGroup = new THREE.Group();
            scene.add(bgGroup);

            var particles = new THREE.Points(geometry, material);
            bgGroup.add(particles);

            var innerGeo = new THREE.IcosahedronGeometry(1.5, 1);
            var innerMat = new THREE.MeshBasicMaterial({
                color: 0x00f0ff,
                wireframe: true,
                transparent: true,
                opacity: 0.15
            });
            var innerSphere = new THREE.Mesh(innerGeo, innerMat);
            bgGroup.add(innerSphere);

            var outerGeo = new THREE.IcosahedronGeometry(2.2, 0);
            var outerMat = new THREE.MeshBasicMaterial({
                color: 0xff2d95,
                wireframe: true,
                transparent: true,
                opacity: 0.08
            });
            var outerSphere = new THREE.Mesh(outerGeo, outerMat);
            bgGroup.add(outerSphere);

            camera.position.z = 5;

            /* Escala el fondo según el aspecto del viewport para cubrir la pantalla */
            function fitBackground() {
                var aspect = window.innerWidth / window.innerHeight;
                bgGroup.scale.setScalar(Math.min(2, Math.max(1.4, aspect * 1.1)));
            }
            fitBackground();

            /* En modo claro: mezcla normal y tono más oscuro para que las partículas se vean */
            function applyParticleTheme() {
                var light = htmlEl.classList.contains('light');
                material.blending = light ? THREE.NormalBlending : THREE.AdditiveBlending;
                material.color.setScalar(light ? 0.65 : 1);
                material.needsUpdate = true;
            }
            applyParticleTheme();

            var mouseX = 0, mouseY = 0;
            document.addEventListener('mousemove', function(e) {
                mouseX = (e.clientX / window.innerWidth - 0.5) * 2;
                mouseY = (e.clientY / window.innerHeight - 0.5) * 2;
            });

            var isPageVisible = true;
            document.addEventListener('visibilitychange', function() {
                isPageVisible = !document.hidden;
            });

            function animate() {
                requestAnimationFrame(animate);
                if (!isPageVisible) return;

                var time = performance.now() * 0.001;

                particles.rotation.y = time * 0.05;
                particles.rotation.x = time * 0.02;

                var pos = geometry.attributes.position.array;
                for (var i = 0; i < particleCount; i++) {
                    var idx = i * 3;
                    var speed = speeds[i];
                    pos[idx + 1] += Math.sin(time + i * 0.1) * speed * 0.3;
                    pos[idx] += Math.cos(time + i * 0.15) * speed * 0.2;
                }
                geometry.attributes.position.needsUpdate = true;

                innerSphere.rotation.x = time * 0.3;
                innerSphere.rotation.z = time * 0.2;
                innerSphere.scale.setScalar(1 + Math.sin(time * 1.5) * 0.05);

                outerSphere.rotation.y = -time * 0.15;
                outerSphere.rotation.z = time * 0.1;

                camera.position.x += (mouseX * 0.5 - camera.position.x) * 0.02;
                camera.position.y += (-mouseY * 0.5 - camera.position.y) * 0.02;
                camera.lookAt(scene.position);

                renderer.render(scene, camera);
            }
            animate();

            /* ===== SVG Connection Lines (one per [data-connect] element) ===== */
            var svgEl = document.getElementById('svgConnections');
            var connTargets = Array.prototype.slice.call(document.querySelectorAll('[data-connect]'));
            var connLines = connTargets.map(function() {
                var line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
                line.setAttribute('x1', 0); line.setAttribute('y1', 0);
                line.setAttribute('x2', 0); line.setAttribute('y2', 0);
                svgEl.appendChild(line);
                return line;
            });

            function updateConnections() {
                var sphereScreen = worldToScreen(innerSphere.position, camera);
                connTargets.forEach(function(el, i) {
                    var rect = el.getBoundingClientRect();
                    connLines[i].setAttribute('x1', sphereScreen.x);
                    connLines[i].setAttribute('y1', sphereScreen.y);
                    connLines[i].setAttribute('x2', rect.left + rect.width / 2);
                    connLines[i].setAttribute('y2', rect.top + rect.height / 2);
                });
            }

            function worldToScreen(position, camera) {
                var vector = position.clone().project(camera);
                return {
                    x: (vector.x * 0.5 + 0.5) * window.innerWidth,
                    y: (-vector.y * 0.5 + 0.5) * window.innerHeight
                };
            }

            function onResize() {
                camera.aspect = window.innerWidth / window.innerHeight;
                camera.updateProjectionMatrix();
                renderer.setSize(window.innerWidth, window.innerHeight);
                fitBackground();
                updateConnections();
            }

            window.addEventListener('resize', onResize);
            window.addEventListener('scroll', updateConnections);

            /* Init */
            setTimeout(updateConnections, 100);
            setTimeout(updateConnections, 500);

            window.CCG = { showToast: showToast, updateConnections: updateConnections };

            var savedTheme = null;
            try { savedTheme = localStorage.getItem('ccg-theme'); } catch (e) {}
            if (savedTheme === 'dark') { setTheme(true); }

        })();
    </script>
    @stack('scripts')
</body>
</html>
