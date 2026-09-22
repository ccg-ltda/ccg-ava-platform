<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Plataforma CCG - Cyber Terminal</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- FontAwesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts: Orbitron & Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Orbitron:wght@500;700;800;900&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #0a0f1d;
            color: #e2e8f0;
            overflow: hidden;
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 0;
            padding: 0;
        }

        .font-orbitron {
            font-family: 'Orbitron', sans-serif;
        }

        /* Dark Circuit Pattern Background */
        .circuit-bg {
            background-color: #0a0f1d;
            background-image: 
                radial-gradient(circle at 50% 50%, rgba(0, 136, 255, 0.12) 0%, transparent 70%),
                linear-gradient(rgba(0, 240, 255, 0.04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0, 240, 255, 0.04) 1px, transparent 1px);
            background-size: 100% 100%, 45px 45px, 45px 45px;
        }

        /* Glassmorphism Panel */
        .glass-panel-animated {
            background: rgba(15, 23, 42, 0.78);
            backdrop-filter: blur(25px);
            -webkit-backdrop-filter: blur(25px);
            border: 1px solid rgba(0, 136, 255, 0.35);
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.9),
                        0 0 40px rgba(0, 136, 255, 0.2),
                        inset 0 1px 0 rgba(255, 255, 255, 0.15);
            animation: cardAppear 1s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            overflow: hidden;
        }

        @keyframes cardAppear {
            from { opacity: 0; transform: translateY(30px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* Neon Corner Brackets */
        .corner-bracket {
            position: absolute;
            width: 14px;
            height: 14px;
            border-color: #00f0ff;
            transition: all 0.3s ease;
            filter: drop-shadow(0 0 8px #00f0ff);
        }
        .corner-tl { top: -1px; left: -1px; border-top: 2px solid; border-left: 2px solid; border-top-left-radius: 1rem; }
        .corner-tr { top: -1px; right: -1px; border-top: 2px solid; border-right: 2px solid; border-top-right-radius: 1rem; }
        .corner-bl { bottom: -1px; left: -1px; border-bottom: 2px solid; border-left: 2px solid; border-bottom-left-radius: 1rem; }
        .corner-br { bottom: -1px; right: -1px; border-bottom: 2px solid; border-right: 2px solid; border-bottom-right-radius: 1rem; }

        /* Glowing Cyber Button */
        .btn-cyber {
            background: linear-gradient(135deg, #0088ff 0%, #00f0ff 100%);
            border: 1px solid rgba(0, 240, 255, 0.8);
            box-shadow: 0 0 25px rgba(0, 136, 255, 0.5);
            transition: all 0.3s ease;
        }
        .btn-cyber:hover {
            box-shadow: 0 0 40px rgba(0, 240, 255, 0.9);
            transform: translateY(-3px) scale(1.02);
        }

        .btn-cyber-outline {
            background: rgba(0, 136, 255, 0.05);
            border: 1px solid rgba(0, 240, 255, 0.5);
            color: #00f0ff;
            box-shadow: 0 0 15px rgba(0, 240, 255, 0.15);
            transition: all 0.3s ease;
        }
        .btn-cyber-outline:hover {
            background: rgba(0, 240, 255, 0.15);
            border-color: #00f0ff;
            box-shadow: 0 0 30px rgba(0, 240, 255, 0.5);
            transform: translateY(-3px) scale(1.02);
            color: #ffffff;
        }
    </style>
</head>
<body class="circuit-bg">

    <div class="w-full max-w-xl mx-auto p-6 z-10">
        <div class="glass-panel-animated rounded-3xl p-8 sm:p-12 text-center relative">
            
            <!-- Corner Brackets -->
            <div class="corner-bracket corner-tl"></div>
            <div class="corner-bracket corner-tr"></div>
            <div class="corner-bracket corner-bl"></div>
            <div class="corner-bracket corner-br"></div>

            <!-- Status Indicator -->
            <div class="inline-flex items-center gap-2 bg-slate-900/80 border border-cyan-500/30 px-4 py-1.5 rounded-full mb-6">
                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-ping"></span>
                <span class="font-orbitron text-[10px] tracking-widest text-cyan-400 uppercase">System Online v2.4</span>
            </div>

            <!-- Logo / Icon -->
            <div class="w-20 h-20 mx-auto mb-6 rounded-2xl bg-gradient-to-br from-cyan-500/20 to-blue-600/30 border border-cyan-400/50 flex items-center justify-center shadow-[0_0_25px_rgba(0,240,255,0.3)]">
                <i class="fa-solid fa-terminal text-3xl text-cyan-400 drop-shadow-[0_0_10px_#00f0ff]"></i>
            </div>

            <!-- Title -->
            <h1 class="font-orbitron text-2xl sm:text-4xl font-black text-white tracking-widest mb-3 drop-shadow-[0_0_20px_rgba(0,240,255,0.6)]">
                PLATAFORMA CCG
            </h1>
            <p class="font-orbitron text-xs sm:text-sm tracking-wider text-cyan-300/80 uppercase mb-8">
                Terminal Central de Operaciones & Ciberseguridad
            </p>

            <!-- Action Buttons -->
            <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                @if (Route::has('login'))
                    @auth
                        <a href="{{ url('/dashboard') }}" class="btn-cyber w-full sm:w-auto px-8 py-3.5 rounded-xl font-orbitron font-bold text-sm tracking-widest text-white uppercase text-center">
                            DASHBOARD
                        </a>
                    @else
                        <a href="{{ route('login') }}" class="btn-cyber w-full sm:w-auto px-8 py-3.5 rounded-xl font-orbitron font-bold text-sm tracking-widest text-white uppercase text-center">
                            ACCEDER
                        </a>

                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="btn-cyber-outline w-full sm:w-auto px-8 py-3.5 rounded-xl font-orbitron font-bold text-sm tracking-widest uppercase text-center">
                                REGISTRO
                            </a>
                        @endif
                    @endauth
                @endif
            </div>

            <!-- Footer info -->
            <div class="mt-10 pt-6 border-t border-cyan-500/20 text-[11px] font-orbitron text-slate-400 tracking-wider flex items-center justify-between">
                <span>SECURITY: SECURE</span>
                <span class="text-cyan-400">CCG NETWORK</span>
            </div>
        </div>
    </div>

</body>
</html>
