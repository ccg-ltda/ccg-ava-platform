<!DOCTYPE html>
<html lang="es" class="dark" data-accent="cyan">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Plataforma CCG | AAA Cyberpunk Edition</title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;600;700;900&family=Rajdhani:wght@400;500;600;700&family=Share+Tech+Mono&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Three.js & Icons -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        orbitron: ['Orbitron', 'sans-serif'],
                        rajdhani: ['Rajdhani', 'sans-serif'],
                        mono: ['Share Tech Mono', 'monospace'],
                    }
                }
            }
        }
    </script>
    <style>
        :root {
            --accent-primary: #00f0ff;
            --accent-glow: rgba(0, 240, 255, 0.4);
            --accent-secondary: #0284c7;
        }
        [data-accent="cyan"] { --accent-primary: #00f0ff; --accent-glow: rgba(0, 240, 255, 0.4); --accent-secondary: #0284c7; }
        [data-accent="purple"] { --accent-primary: #d946ef; --accent-glow: rgba(217, 70, 239, 0.4); --accent-secondary: #9333ea; }
        [data-accent="green"] { --accent-primary: #22c55e; --accent-glow: rgba(34, 197, 94, 0.4); --accent-secondary: #16a34a; }
        [data-accent="red"] { --accent-primary: #ef4444; --accent-glow: rgba(239, 68, 68, 0.4); --accent-secondary: #dc2626; }

        * {
            box-sizing: border-box;
        }
        html, body {
            max-width: 100vw;
            overflow-x: hidden;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: 'Rajdhani', sans-serif;
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        /* MODO OSCURO */
        html.dark body {
            background-color: #030712;
            color: #e2e8f0;
        }
        html.dark .cyber-grid {
            background-image: 
                linear-gradient(to right, rgba(0, 240, 255, 0.04) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(0, 240, 255, 0.04) 1px, transparent 1px);
            background-size: 35px 35px;
        }
        html.dark .scanline {
            background: linear-gradient(
                to bottom,
                rgba(255,255,255,0),
                rgba(255,255,255,0) 50%,
                rgba(0, 240, 255, 0.03) 50%,
                rgba(0, 240, 255, 0.03)
            );
            background-size: 100% 4px;
        }
        html.dark .cyber-card {
            background: linear-gradient(135deg, rgba(8, 25, 48, 0.9) 0%, rgba(4, 15, 30, 0.95) 100%);
            border: 1px solid var(--accent-primary);
            box-shadow: 0 0 35px var(--accent-glow);
        }
        html.dark .cyber-card::before, html.dark .cyber-card::after {
            border-color: var(--accent-primary);
        }
        html.dark .cyber-input {
            background: rgba(6, 20, 38, 0.8);
            border: 1px solid rgba(0, 240, 255, 0.25);
        }
        html.dark .cyber-input input {
            color: #cffaff !important;
        }
        html.dark .btn-secondary {
            background: rgba(0, 240, 255, 0.05);
            border: 1px solid rgba(0, 240, 255, 0.4);
            color: #67e8f9;
        }
        /* MODO CLARO */
        html:not(.dark) body {
            background-color: #cbd5e1;
            color: #0f172a;
        }
        html:not(.dark) .cyber-grid {
            background-image: 
                linear-gradient(to right, rgba(14, 116, 144, 0.15) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(14, 116, 144, 0.15) 1px, transparent 1px);
            background-size: 35px 35px;
        }
        html:not(.dark) .scanline {
            display: none;
        }
        html:not(.dark) .cyber-card {
            background: rgba(255, 255, 255, 0.95);
            border: 1px solid var(--accent-primary);
            box-shadow: 0 20px 40px rgba(14, 116, 144, 0.2);
        }
        html:not(.dark) .cyber-card::before, html:not(.dark) .cyber-card::after {
            border-color: var(--accent-primary);
        }
        html:not(.dark) .cyber-input {
            background: #f1f5f9;
            border: 1px solid #94a3b8;
        }
        html:not(.dark) .cyber-input input {
            color: #0f172a !important;
        }
        html:not(.dark) .glow-text {
            text-shadow: none;
            color: var(--accent-secondary) !important;
        }
        html:not(.dark) .btn-secondary {
            background: rgba(2, 132, 199, 0.08);
            border: 1px solid rgba(2, 132, 199, 0.5);
            color: #0369a1;
        }
        .cyber-card {
            backdrop-filter: blur(16px);
            position: relative;
            transform-style: preserve-3d;
            transition: transform 0.1s ease-out;
        }
        .cyber-card::before {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            width: 18px;
            height: 18px;
            border-top: 3px solid;
            border-left: 3px solid;
        }
        .cyber-card::after {
            content: '';
            position: absolute;
            bottom: -2px;
            right: -2px;
            width: 18px;
            height: 18px;
            border-bottom: 3px solid;
            border-right: 3px solid;
        }
        .glow-text {
            text-shadow: 0 0 10px var(--accent-primary), 0 0 20px var(--accent-glow);
        }
        .btn-glow {
            background: linear-gradient(90deg, var(--accent-secondary) 0%, var(--accent-primary) 100%);
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
            box-shadow: 0 0 20px var(--accent-glow);
        }
        .btn-glow:hover {
            box-shadow: 0 0 35px var(--accent-primary);
            transform: translateY(-2px);
        }
        #bg-canvas {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            pointer-events: none;
            z-index: 1;
        }
        #canvas-container canvas {
            width: 100% !important;
            height: 100% !important;
            display: block;
            position: absolute;
            top: 0;
            left: 0;
        }
        #connection-canvas {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 10;
        }
        @keyframes glitch {
            0% { transform: translate(0) }
            20% { transform: translate(-2px, 2px) }
            40% { transform: translate(-2px, -2px) }
            60% { transform: translate(2px, 2px) }
            80% { transform: translate(2px, -2px) }
            100% { transform: translate(0) }
        }
        .glitch-anim {
            animation: glitch 0.3s cubic-bezier(.25, .46, .45, .94) both;
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4 cyber-grid relative overflow-x-hidden">

    <!-- Canvas Fondo Matriz / Red -->
    <canvas id="bg-canvas"></canvas>

    <!-- Barra Superior de Controles (Tema, Sonido, Idioma, Paleta) -->
    <div class="fixed top-5 right-5 z-50 flex items-center gap-3">
        <!-- Selector de Paleta -->
        <div class="hidden sm:flex items-center gap-1.5 bg-slate-900/80 dark:bg-slate-900/80 bg-white/90 p-1.5 rounded-full border border-cyan-500/30 backdrop-blur-md shadow-lg">
            <button onclick="setAccent('cyan')" class="w-4 h-4 rounded-full bg-[#00f0ff] hover:scale-125 transition-transform" title="Cyan"></button>
            <button onclick="setAccent('purple')" class="w-4 h-4 rounded-full bg-[#d946ef] hover:scale-125 transition-transform" title="Purple"></button>
            <button onclick="setAccent('green')" class="w-4 h-4 rounded-full bg-[#22c55e] hover:scale-125 transition-transform" title="Green"></button>
            <button onclick="setAccent('red')" class="w-4 h-4 rounded-full bg-[#ef4444] hover:scale-125 transition-transform" title="Red"></button>
        </div>

        <!-- Selector de Idioma -->
        <button onclick="toggleLang()" class="px-3 py-2 rounded-full border border-cyan-400/50 bg-slate-900/80 text-cyan-300 dark:bg-slate-100/90 dark:text-slate-800 shadow-lg backdrop-blur-md hover:scale-110 transition-all font-mono text-xs font-bold" id="lang-btn">
            ES
        </button>

        <!-- Botón de Sonido Mute/Unmute -->
        <button onclick="toggleMute()" class="p-3 rounded-full border border-cyan-400/50 bg-slate-900/80 text-cyan-300 dark:bg-slate-100/90 dark:text-slate-800 shadow-lg backdrop-blur-md hover:scale-110 transition-all flex items-center justify-center" id="sound-btn" title="Audio SFX">
            <i class="fa-solid fa-volume-high text-lg" id="sound-icon"></i>
        </button>

        <!-- Switch Tema -->
        <button onclick="toggleTheme()" class="p-3 rounded-full border border-cyan-400/50 bg-slate-900/80 text-cyan-300 dark:bg-slate-100/90 dark:text-slate-800 shadow-lg backdrop-blur-md hover:scale-110 transition-all flex items-center justify-center" title="Cambiar Modo Claro/Oscuro">
            <i id="theme-icon" class="fa-solid fa-sun text-lg"></i>
        </button>
    </div>

    <div class="pointer-events-none fixed inset-0 scanline z-20"></div>

    <svg id="connection-canvas" class="z-20">
        <path id="line1" d="" stroke="rgba(0, 240, 255, 0.5)" stroke-width="1.5" fill="none" stroke-dasharray="4,2" />
        <path id="line2" d="" stroke="rgba(0, 240, 255, 0.5)" stroke-width="1.5" fill="none" stroke-dasharray="4,2" />
    </svg>

    <!-- Grid Principal Contenido -->
    <div class="w-full max-w-6xl grid grid-cols-1 lg:grid-cols-12 gap-6 items-center z-30 my-auto">
        
        <!-- Esfera 3D Interactiva -->
        <div class="lg:col-span-7 flex flex-col items-center justify-center relative w-full">
            <div id="canvas-container" class="w-full h-[320px] sm:h-[420px] relative cursor-grab active:cursor-grabbing overflow-hidden rounded-2xl shadow-2xl border border-cyan-500/20 bg-slate-950/40 backdrop-blur-md">
                <div id="node-anchor-1" class="absolute top-[45%] right-[25%] w-2.5 h-2.5 rounded-full bg-cyan-400 shadow-[0_0_15px_#00f0ff] pointer-events-none animate-ping"></div>
                <div id="node-anchor-2" class="absolute top-[60%] right-[30%] w-2.5 h-2.5 rounded-full bg-cyan-400 shadow-[0_0_15px_#00f0ff] pointer-events-none animate-pulse"></div>
            </div>
            
            <!-- Widget Telemetría y Nodo -->
            <div class="mt-3 text-center flex flex-wrap items-center justify-center gap-4 bg-slate-950/80 dark:bg-slate-950/80 bg-white/90 backdrop-blur-md px-6 py-2.5 rounded-full border border-cyan-500/30 shadow-xl">
                <div class="flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-ping"></span>
                    <h3 class="font-orbitron text-xs sm:text-sm tracking-widest text-cyan-500 dark:text-cyan-300 font-bold uppercase glow-text" data-i18n="node_title">
                        NODO GLOBAL CCG
                    </h3>
                </div>
                <div class="font-mono text-[11px] text-cyan-600 dark:text-cyan-400/80 tracking-wider flex items-center gap-3">
                    <span>LATENCIA: <span id="latency-val" class="text-cyan-400 font-bold">12ms</span></span>
                    <span>CPU: <span id="cpu-val" class="text-cyan-400 font-bold">14%</span></span>
                    <span>SEC: <span class="text-emerald-400 font-bold">256-bit</span></span>
                </div>
            </div>
        </div>

        <!-- Tarjeta de Login (Efecto Tilt 3D) -->
        <div class="lg:col-span-5 flex justify-center w-full">
            <div id="login-card" class="cyber-card w-full max-w-md rounded-2xl p-6 relative glitch-anim">
                <div class="mb-5 text-left">
                    <h1 class="font-orbitron text-2xl font-black tracking-wider text-slate-900 dark:text-white glow-text uppercase mb-1" data-i18n="main_heading">
                        LOGIN PLATAFORMA CCG
                    </h1>
                    <p class="font-orbitron text-xs tracking-widest text-cyan-600 dark:text-cyan-400 font-medium uppercase" data-i18n="sub_heading">
                        SISTEMA GLOBAL
                    </p>
                    <div class="w-full h-[1px] bg-gradient-to-r from-cyan-500/50 via-cyan-400/20 to-transparent mt-3"></div>
                </div>

                <form id="loginForm" class="space-y-4" action="{{ route('login') }}" method="POST" onmouseenter="playSFX('hover')">
                    @csrf
                    <div class="space-y-1" id="input-group-user">
                        <label class="block font-orbitron text-xs tracking-wider text-cyan-600 dark:text-cyan-300 font-bold uppercase" data-i18n="label_user">USUARIO (E-mail)</label>
                        <div class="cyber-input rounded-xl flex items-center px-3 py-2.5 transition-all">
                            <input type="email" name="email" id="email" required placeholder="usuario@ccg-platform.com" onfocus="playSFX('focus')" class="w-full bg-transparent text-sm placeholder-cyan-600/50 focus:outline-none font-mono" />
                            <i class="fa-solid fa-user-gear text-cyan-500 ml-2"></i>
                        </div>
                    </div>

                    <div class="space-y-1" id="input-group-pass">
                        <label class="block font-orbitron text-xs tracking-wider text-cyan-600 dark:text-cyan-300 font-bold uppercase" data-i18n="label_pass">CONTRASEÑA</label>
                        <div class="cyber-input rounded-xl flex items-center px-3 py-2.5 transition-all">
                            <input type="password" name="password" id="password" required placeholder="••••••••••••" onfocus="playSFX('focus')" class="w-full bg-transparent text-sm placeholder-cyan-600/50 focus:outline-none font-mono tracking-widest" />
                            <button type="button" onclick="togglePasswordVisibility(); playSFX('click');" class="text-cyan-500 ml-2 hover:text-white transition-colors"><i id="eye-icon" class="fa-solid fa-eye"></i></button>
                        </div>
                    </div>

                    <button type="submit" id="btn-submit" onclick="playSFX('click')" class="btn-glow w-full py-3 rounded-xl font-orbitron font-bold text-white tracking-widest text-sm uppercase mt-2">
                        <span data-i18n="btn_login">ACCEDER</span>
                    </button>
                    
                    <button type="button" onclick="playSFX('click'); window.location.href='{{ route('register') }}';" class="btn-secondary w-full py-2.5 rounded-xl font-orbitron font-semibold tracking-wider text-xs uppercase transition-all">
                        <span data-i18n="btn_register">REGÍSTRATE</span>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Script Web Audio API + Three.js + UI interactivity -->
    <script>
        // --- 1. WEB AUDIO API SYNTHESIZER SFX ---
        let audioCtx = null;
        let isMuted = false;

        function initAudio() {
            if (!audioCtx) {
                audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            }
        }

        function playSFX(type) {
            if (isMuted) return;
            initAudio();
            if (!audioCtx) return;

            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.connect(gain);
            gain.connect(audioCtx.destination);

            const now = audioCtx.currentTime;

            if (type === 'hover') {
                osc.type = 'sine';
                osc.frequency.setValueAtTime(440, now);
                osc.frequency.exponentialRampToValueAtTime(880, now + 0.05);
                gain.gain.setValueAtTime(0.02, now);
                gain.gain.exponentialRampToValueAtTime(0.001, now + 0.05);
                osc.start(now);
                osc.stop(now + 0.05);
            } else if (type === 'focus') {
                osc.type = 'triangle';
                osc.frequency.setValueAtTime(587.33, now); // D5
                osc.frequency.exponentialRampToValueAtTime(1174.66, now + 0.08);
                gain.gain.setValueAtTime(0.03, now);
                gain.gain.exponentialRampToValueAtTime(0.001, now + 0.08);
                osc.start(now);
                osc.stop(now + 0.08);
            } else if (type === 'click') {
                osc.type = 'square';
                osc.frequency.setValueAtTime(880, now);
                osc.frequency.exponentialRampToValueAtTime(220, now + 0.1);
                gain.gain.setValueAtTime(0.05, now);
                gain.gain.exponentialRampToValueAtTime(0.001, now + 0.1);
                osc.start(now);
                osc.stop(now + 0.1);
            }
        }

        function toggleMute() {
            isMuted = !isMuted;
            const icon = document.getElementById('sound-icon');
            icon.className = isMuted ? 'fa-solid fa-volume-xmark text-lg text-red-400' : 'fa-solid fa-volume-high text-lg';
            playSFX('click');
        }

        // --- 2. THEME & ACCENT & LANG ---
        function toggleTheme() {
            const html = document.documentElement;
            const icon = document.getElementById('theme-icon');
            playSFX('click');
            if (html.classList.contains('dark')) {
                html.classList.remove('dark');
                icon.className = 'fa-solid fa-moon text-lg';
            } else {
                html.classList.add('dark');
                icon.className = 'fa-solid fa-sun text-lg';
            }
        }

        function setAccent(color) {
            document.documentElement.setAttribute('data-accent', color);
            playSFX('click');
        }

        let currentLang = 'es';
        const translations = {
            es: {
                node_title: "NODO GLOBAL CCG",
                main_heading: "LOGIN PLATAFORMA CCG",
                sub_heading: "SISTEMA GLOBAL",
                label_user: "USUARIO (E-mail)",
                label_pass: "CONTRASEÑA",
                btn_login: "ACCEDER",
                btn_register: "REGÍSTRATE"
            },
            en: {
                node_title: "CCG GLOBAL NODE",
                main_heading: "CCG PLATFORM LOGIN",
                sub_heading: "GLOBAL SYSTEM",
                label_user: "USER (E-mail)",
                label_pass: "PASSWORD",
                btn_login: "ACCESS",
                btn_register: "REGISTER"
            }
        };

        function toggleLang() {
            currentLang = currentLang === 'es' ? 'en' : 'es';
            document.getElementById('lang-btn').textContent = currentLang.toUpperCase();
            playSFX('click');
            
            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (translations[currentLang][key]) {
                    el.textContent = translations[currentLang][key];
                }
            });
        }

        // --- 3. THREE.JS SPHERE & BACKGROUND ---
        let scene, camera, renderer, globeGroup;
        let mouseX = 0, mouseY = 0;
        let targetX = 0, targetY = 0;

        function initThreeJS() {
            const container = document.getElementById('canvas-container');
            const width = container.clientWidth || 400;
            const height = container.clientHeight || 400;

            scene = new THREE.Scene();
            camera = new THREE.PerspectiveCamera(45, width / height, 0.1, 1000);
            camera.position.z = 210;

            renderer = new THREE.WebGLRenderer({ alpha: true, antialias: true });
            renderer.setSize(width, height);
            renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
            
            container.innerHTML = ''; 
            container.appendChild(renderer.domElement);

            // Re-inject anchors
            const a1 = document.createElement('div');
            a1.id = 'node-anchor-1';
            a1.className = 'absolute top-[45%] right-[25%] w-2.5 h-2.5 rounded-full bg-cyan-400 shadow-[0_0_15px_#00f0ff] pointer-events-none animate-ping';
            const a2 = document.createElement('div');
            a2.id = 'node-anchor-2';
            a2.className = 'absolute top-[60%] right-[30%] w-2.5 h-2.5 rounded-full bg-cyan-400 shadow-[0_0_15px_#00f0ff] pointer-events-none animate-pulse';
            container.appendChild(a1);
            container.appendChild(a2);

            globeGroup = new THREE.Group();
            scene.add(globeGroup);

            const sphereRadius = 60;
            const particleCount = 2500;
            const geometry = new THREE.BufferGeometry();
            const positions = new Float32Array(particleCount * 3);

            for (let i = 0; i < particleCount; i++) {
                const phi = Math.acos(-1 + (2 * i) / particleCount);
                const theta = Math.sqrt(particleCount * Math.PI) * phi;
                positions[i * 3] = sphereRadius * Math.cos(theta) * Math.sin(phi);
                positions[i * 3 + 1] = sphereRadius * Math.sin(theta) * Math.sin(phi);
                positions[i * 3 + 2] = sphereRadius * Math.cos(phi);
            }
            geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
            
            const material = new THREE.PointsMaterial({ size: 1.8, color: 0x00f0ff, transparent: true, opacity: 0.85 });
            const particles = new THREE.Points(geometry, material);
            globeGroup.add(particles);

            // Background digital rain / network canvas
            initBgCanvas();

            window.addEventListener('resize', onWindowResize);
            document.addEventListener('mousemove', onMouseMove);
        }

        function onMouseMove(event) {
            mouseX = (event.clientX - window.innerWidth / 2) * 0.001;
            mouseY = (event.clientY - window.innerHeight / 2) * 0.001;

            // 3D Tilt Parallax on Login Card
            const card = document.getElementById('login-card');
            if (card) {
                const xVal = (event.clientX / window.innerWidth - 0.5) * 15;
                const yVal = (event.clientY / window.innerHeight - 0.5) * -15;
                card.style.transform = `perspective(1000px) rotateY(${xVal}deg) rotateX(${yVal}deg)`;
            }
        }

        function onWindowResize() {
            const container = document.getElementById('canvas-container');
            if (!container || !renderer) return;
            const width = container.clientWidth;
            const height = container.clientHeight;
            camera.aspect = width / height;
            camera.updateProjectionMatrix();
            renderer.setSize(width, height);
        }

        // Background Particles Canvas
        function initBgCanvas() {
            const canvas = document.getElementById('bg-canvas');
            const ctx = canvas.getContext('2d');
            let w = canvas.width = window.innerWidth;
            let h = canvas.height = window.innerHeight;

            window.addEventListener('resize', () => {
                w = canvas.width = window.innerWidth;
                h = canvas.height = window.innerHeight;
            });

            const pts = [];
            for (let i = 0; i < 60; i++) {
                pts.push({
                    x: Math.random() * w,
                    y: Math.random() * h,
                    vx: (Math.random() - 0.5) * 0.5,
                    vy: (Math.random() - 0.5) * 0.5,
                    size: Math.random() * 2 + 1
                });
            }

            function renderBg() {
                ctx.clearRect(0, 0, w, h);
                ctx.fillStyle = 'rgba(0, 240, 255, 0.3)';
                ctx.strokeStyle = 'rgba(0, 240, 255, 0.05)';

                pts.forEach((p, index) => {
                    p.x += p.vx;
                    p.y += p.vy;
                    if (p.x < 0) p.x = w; if (p.x > w) p.x = 0;
                    if (p.y < 0) p.y = h; if (p.y > h) p.y = 0;

                    ctx.beginPath();
                    ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2);
                    ctx.fill();

                    for (let j = index + 1; j < pts.length; j++) {
                        const p2 = pts[j];
                        const dist = Math.hypot(p.x - p2.x, p.y - p2.y);
                        if (dist < 120) {
                            ctx.beginPath();
                            ctx.moveTo(p.x, p.y);
                            ctx.lineTo(p2.x, p2.y);
                            ctx.stroke();
                        }
                    }
                });
                requestAnimationFrame(renderBg);
            }
            renderBg();
        }

        function animate() {
            requestAnimationFrame(animate);
            if (globeGroup) {
                targetX += (mouseX - targetX) * 0.05;
                targetY += (mouseY - targetY) * 0.05;
                globeGroup.rotation.y += 0.003 + targetX;
                globeGroup.rotation.x += 0.001 + targetY;
            }
            renderer.render(scene, camera);
        }

        function togglePasswordVisibility() {
            const passInput = document.getElementById('password');
            const eyeIcon = document.getElementById('eye-icon');
            if (passInput.type === 'password') {
                passInput.type = 'text';
                eyeIcon.className = 'fa-solid fa-eye-slash';
            } else {
                passInput.type = 'password';
                eyeIcon.className = 'fa-solid fa-eye';
            }
        }

        // Dynamic Telemetry simulation
        setInterval(() => {
            const latency = Math.floor(Math.random() * 6) + 10;
            const cpu = Math.floor(Math.random() * 8) + 12;
            const latEl = document.getElementById('latency-val');
            const cpuEl = document.getElementById('cpu-val');
            if (latEl) latEl.textContent = latency + 'ms';
            if (cpuEl) cpuEl.textContent = cpu + '%';
        }, 2000);

        window.onload = function() {
            initThreeJS();
            animate();
        };
    </script>
</body>
</html>
