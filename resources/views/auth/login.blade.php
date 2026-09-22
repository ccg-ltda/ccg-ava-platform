<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Plataforma CCG - Login Sci-Fi</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- FontAwesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Three.js for 3D Cyberpunk Hologram Globe -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
    <!-- Google Fonts: Orbitron & Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Orbitron:wght@500;700;800;900&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #080d14;
            color: #e2e8f0;
            overflow-x: hidden;
            margin: 0;
            padding: 0;
        }

        .font-orbitron {
            font-family: 'Orbitron', sans-serif;
        }

        /* Dark Circuit Pattern Background */
        .circuit-bg {
            background-color: #0a0f18;
            background-image: 
                radial-gradient(circle at 50% 50%, rgba(14, 165, 233, 0.1) 0%, transparent 60%),
                linear-gradient(rgba(14, 165, 233, 0.04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(14, 165, 233, 0.04) 1px, transparent 1px);
            background-size: 100% 100%, 40px 40px, 40px 40px;
        }

        /* Enhanced Animated Glassmorphism Panel */
        .glass-panel-animated {
            background: rgba(15, 23, 42, 0.72);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(56, 189, 248, 0.25);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.8),
                        0 0 30px rgba(56, 189, 248, 0.15),
                        inset 0 1px 0 rgba(255, 255, 255, 0.15);
            animation: floatCard 6s ease-in-out infinite, pulseGlow 4s ease-in-out infinite alternate;
            position: relative;
            overflow: hidden;
        }

        /* Floating Animation */
        @keyframes floatCard {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-8px) rotate(0.3deg); }
        }

        /* Ambient Glow Pulse */
        @keyframes pulseGlow {
            0% {
                border-color: rgba(56, 189, 248, 0.25);
                box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.8),
                            0 0 20px rgba(56, 189, 248, 0.15),
                            inset 0 1px 0 rgba(255, 255, 255, 0.15);
            }
            100% {
                border-color: rgba(56, 189, 248, 0.55);
                box-shadow: 0 25px 60px -10px rgba(0, 0, 0, 0.9),
                            0 0 35px rgba(56, 189, 248, 0.3),
                            inset 0 1px 0 rgba(255, 255, 255, 0.25);
            }
        }

        /* Holographic Laser Scanline Effect */
        .scanline-effect::after {
            content: '';
            position: absolute;
            top: -100%;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(
                180deg,
                transparent 0%,
                rgba(56, 189, 248, 0.03) 40%,
                rgba(56, 189, 248, 0.22) 50%,
                rgba(56, 189, 248, 0.03) 60%,
                transparent 100%
            );
            animation: scanline 4s cubic-bezier(0.4, 0, 0.2, 1) infinite;
            pointer-events: none;
        }

        @keyframes scanline {
            0% { top: -100%; }
            100% { top: 200%; }
        }

        /* Animated Neon Corner Brackets */
        .corner-bracket {
            position: absolute;
            width: 12px;
            height: 12px;
            border-color: #38bdf8;
            transition: all 0.3s ease;
            filter: drop-shadow(0 0 6px #38bdf8);
        }

        .glass-panel-animated:hover .corner-bracket {
            width: 18px;
            height: 18px;
            filter: drop-shadow(0 0 12px #38bdf8);
        }

        .corner-tl { top: -1px; left: -1px; border-top: 2px solid; border-left: 2px solid; border-top-left-radius: 1rem; }
        .corner-tr { top: -1px; right: -1px; border-top: 2px solid; border-right: 2px solid; border-top-right-radius: 1rem; }
        .corner-bl { bottom: -1px; left: -1px; border-bottom: 2px solid; border-left: 2px solid; border-bottom-left-radius: 1rem; }
        .corner-br { bottom: -1px; right: -1px; border-bottom: 2px solid; border-right: 2px solid; border-bottom-right-radius: 1rem; }

        /* Custom Input Focus Glow & Micro-animations */
        .sci-input {
            background: rgba(15, 23, 42, 0.85);
            border: 1px solid rgba(56, 189, 248, 0.25);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            color: #f1f5f9;
        }

        .sci-input:focus {
            outline: none;
            border-color: #38bdf8;
            box-shadow: 0 0 20px rgba(56, 189, 248, 0.45),
                        inset 0 0 12px rgba(56, 189, 248, 0.2);
            transform: translateX(3px);
        }

        /* Glowing Animated Neon Button */
        .btn-cyber {
            background: linear-gradient(135deg, #0284c7 0%, #2563eb 100%);
            border: 1px solid rgba(56, 189, 248, 0.6);
            box-shadow: 0 0 20px rgba(37, 99, 235, 0.4),
                        inset 0 1px 0 rgba(255, 255, 255, 0.3);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .btn-cyber::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
            transition: left 0.5s ease;
        }

        .btn-cyber:hover::before {
            left: 100%;
        }

        .btn-cyber:hover {
            box-shadow: 0 0 35px rgba(56, 189, 248, 0.8),
                        inset 0 1px 0 rgba(255, 255, 255, 0.6);
            transform: translateY(-2px) scale(1.01);
        }

        .btn-cyber:active {
            transform: translateY(1px) scale(0.99);
            box-shadow: 0 0 15px rgba(37, 99, 235, 0.7);
        }

        /* Password Eye Toggle Hover Effect */
        .eye-btn {
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        .eye-btn:hover {
            color: #38bdf8;
            transform: scale(1.2) rotate(5deg);
            filter: drop-shadow(0 0 8px rgba(56, 189, 248, 0.8));
        }

        /* Glowing Dots Background Animation */
        .glowing-dot {
            position: absolute;
            width: 4px;
            height: 4px;
            border-radius: 50%;
            background: #38bdf8;
            box-shadow: 0 0 10px #38bdf8, 0 0 20px #38bdf8;
            animation: pulse-glow-dots 3s infinite alternate;
        }

        @keyframes pulse-glow-dots {
            0% { opacity: 0.2; transform: scale(0.8); }
            100% { opacity: 1; transform: scale(1.5); }
        }
    </style>
</head>
<body class="circuit-bg min-h-screen flex items-center justify-center relative select-none">

    <div class="glowing-dot top-1/4 left-1/6" style="animation-delay: 0s;"></div>
    <div class="glowing-dot top-3/4 left-1/3" style="animation-delay: 0.7s;"></div>
    <div class="glowing-dot top-1/3 right-1/4" style="animation-delay: 1.2s;"></div>
    <div class="glowing-dot bottom-1/4 right-1/3" style="animation-delay: 1.8s;"></div>
    <div class="glowing-dot top-2/3 left-1/12" style="animation-delay: 0.4s;"></div>

    <div class="w-full max-w-6xl mx-auto min-h-screen lg:min-h-[650px] flex flex-col lg:flex-row items-center justify-between p-4 sm:p-8 z-10">
        
        <!-- Left Column: 3D Holographic Globe Simulation -->
        <div class="w-full lg:w-1/2 h-[350px] lg:h-[550px] relative flex items-center justify-center">
            <div id="globe-container" class="w-full h-full cursor-grab active:cursor-grabbing"></div>

            <div class="absolute bottom-4 pointer-events-none text-center">
                <div class="text-[10px] font-orbitron tracking-[0.3em] text-cyan-400/70 uppercase animate-pulse drop-shadow-[0_0_8px_rgba(56,189,248,0.5)]">
                    Global Network Protocol v1.0
                </div>
            </div>
        </div>

        <div class="w-full lg:w-[420px] glass-panel-animated scanline-effect rounded-2xl p-8 sm:p-10 relative">
            
            <!-- Tech Decorative Animated Corner Brackets -->
            <div class="corner-bracket corner-tl"></div>
            <div class="corner-bracket corner-tr"></div>
            <div class="corner-bracket corner-bl"></div>
            <div class="corner-bracket corner-br"></div>

            <!-- Header -->
            <div class="mb-8 text-left">
                <h1 class="font-orbitron text-xl sm:text-2xl font-extrabold tracking-wider text-white mb-2 drop-shadow-[0_0_12px_rgba(56,189,248,0.6)]">
                    LOGIN PLATAFORMA CCG
                </h1>
                <p class="text-xs font-orbitron tracking-widest text-cyan-400/90 uppercase flex items-center gap-2">
                    <span class="inline-block w-1.5 h-1.5 rounded-full bg-cyan-400 animate-ping"></span>
                    Bienvenido al Sistema Global
                </p>
            </div>

            <!-- Form -->
            <form action="{{ route('login') }}" method="POST" class="space-y-6">
                @csrf

                <!-- Email / Username Input -->
                <div class="space-y-2">
                    <label for="email" class="block text-xs font-orbitron tracking-wider text-slate-300">
                        USUARIO (E-mail)
                    </label>
                    <div class="relative group">
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            value="{{ old('email') }}"
                            required 
                            autocomplete="email"
                            placeholder="usuario@ccg-platform.com"
                            class="sci-input w-full px-4 py-3 rounded-lg text-sm placeholder-slate-500 font-sans tracking-wide"
                        >
                        <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none text-slate-500 group-focus-within:text-cyan-400 transition-colors">
                            <i class="fa-solid fa-user-gear text-xs"></i>
                        </div>
                    </div>
                </div>

                <!-- Password Input with Interactive Eye Toggle -->
                <div class="space-y-2">
                    <label for="password" class="block text-xs font-orbitron tracking-wider text-slate-300">
                        CONTRASEÑA
                    </label>
                    <div class="relative group">
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            required 
                            autocomplete="current-password"
                            placeholder="••••••••••••"
                            class="sci-input w-full px-4 py-3 rounded-lg text-sm placeholder-slate-500 font-sans tracking-wide pr-10"
                        >
                        <!-- Animated Eye Toggle Button -->
                        <button 
                            type="button" 
                            id="togglePassword"
                            aria-label="Toggle password visibility"
                            class="eye-btn absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-cyan-400 focus:outline-none"
                        >
                            <i id="eyeIcon" class="fa-solid fa-eye text-sm"></i>
                        </button>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="pt-2 space-y-3">
                    <button 
                        type="submit" 
                        class="btn-cyber w-full py-3.5 px-6 rounded-lg text-white font-orbitron font-bold text-sm tracking-widest uppercase cursor-pointer"
                    >
                        ACCEDER
                    </button>

                    <a 
                        href="{{ route('register') }}" 
                        class="block w-full py-3 px-6 rounded-lg text-center font-orbitron font-bold text-xs tracking-widest uppercase text-cyan-400 bg-cyan-500/10 border border-cyan-500/40 shadow-[0_0_15px_rgba(56,189,248,0.2)] transition-all hover:bg-cyan-500/25 hover:text-white hover:shadow-[0_0_25px_rgba(56,189,248,0.6)]"
                    >
                        ¿No tienes cuenta? Regístrate
                    </a>
                </div>
            </form>

            <!-- Card Footer / Status -->
            <div class="mt-8 pt-4 border-t border-slate-800/80 flex items-center justify-between text-[11px] text-slate-400 font-orbitron">
                <span class="flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    Estado: En Línea
                </span>
                <span class="text-slate-500">v2.4.0</span>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            /* 1. Password Visibility Toggle Animation */
            const passwordInput = document.getElementById('password');
            const togglePasswordBtn = document.getElementById('togglePassword');
            const eyeIcon = document.getElementById('eyeIcon');

            togglePasswordBtn.addEventListener('click', () => {
                const isPassword = passwordInput.getAttribute('type') === 'password';
                
                // Toggle Type
                passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
                
                // Animate Icon Switch
                eyeIcon.style.transform = 'scale(0.3) rotate(180deg)';
                setTimeout(() => {
                    if (isPassword) {
                        eyeIcon.classList.remove('fa-eye');
                        eyeIcon.classList.add('fa-eye-slash');
                        eyeIcon.classList.add('text-cyan-400');
                    } else {
                        eyeIcon.classList.remove('fa-eye-slash');
                        eyeIcon.classList.add('fa-eye');
                        eyeIcon.classList.remove('text-cyan-400');
                    }
                    eyeIcon.style.transform = 'scale(1) rotate(0deg)';
                }, 150);
            });

            /* 2. Three.js Sci-Fi Hologram Globe Simulation */
            const container = document.getElementById('globe-container');
            const scene = new THREE.Scene();

            const camera = new THREE.PerspectiveCamera(
                45, 
                container.clientWidth / container.clientHeight, 
                0.1, 
                1000
            );
            camera.position.z = 250;

            const renderer = new THREE.WebGLRenderer({ alpha: true, antialias: true });
            renderer.setSize(container.clientWidth, container.clientHeight);
            renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
            container.appendChild(renderer.domElement);

            const globeGroup = new THREE.Group();
            scene.add(globeGroup);

            // Create Point Particles Globe
            const radius = 65;
            const particleCount = 2200;
            const geometry = new THREE.BufferGeometry();
            const positions = new Float32Array(particleCount * 3);

            for (let i = 0; i < particleCount; i++) {
                const phi = Math.acos(-1 + (2 * i) / particleCount);
                const theta = Math.sqrt(particleCount * Math.PI) * phi;

                positions[i * 3] = radius * Math.cos(theta) * Math.sin(phi);
                positions[i * 3 + 1] = radius * Math.sin(theta) * Math.sin(phi);
                positions[i * 3 + 2] = radius * Math.cos(phi);
            }

            geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));

            const material = new THREE.PointsMaterial({
                color: 0x38bdf8,
                size: 1.6,
                transparent: true,
                opacity: 0.85,
                blending: THREE.AdditiveBlending
            });

            const points = new THREE.Points(geometry, material);
            globeGroup.add(points);

            // Orbital Rings
            function createRing(innerRadius, outerRadius, color, rotX, rotY) {
                const ringGeo = new THREE.RingGeometry(innerRadius, outerRadius, 64);
                const ringMat = new THREE.MeshBasicMaterial({
                    color: color,
                    side: THREE.DoubleSide,
                    transparent: true,
                    opacity: 0.4,
                    blending: THREE.AdditiveBlending
                });
                const ring = new THREE.Mesh(ringGeo, ringMat);
                ring.rotation.x = rotX;
                ring.rotation.y = rotY;
                return ring;
            }

            const ring1 = createRing(80, 81.5, 0x0284c7, Math.PI / 3, Math.PI / 6);
            const ring2 = createRing(95, 96, 0x38bdf8, -Math.PI / 4, Math.PI / 4);
            
            globeGroup.add(ring1);
            globeGroup.add(ring2);

            // Base Mechanical Ring Effect
            const baseRing = createRing(70, 85, 0x1e293b, Math.PI / 2, 0);
            baseRing.position.y = -80;
            globeGroup.add(baseRing);

            // Animation Loop
            function animate() {
                requestAnimationFrame(animate);

                points.rotation.y += 0.0025;
                ring1.rotation.z += 0.003;
                ring2.rotation.z -= 0.002;

                renderer.render(scene, camera);
            }
            animate();

            // Window Resize Handler
            window.addEventListener('resize', () => {
                if (!container) return;
                const width = container.clientWidth;
                const height = container.clientHeight;
                
                camera.aspect = width / height;
                camera.updateProjectionMatrix();
                
                renderer.setSize(width, height);
            });
        });
    </script>
</body>
</html>
