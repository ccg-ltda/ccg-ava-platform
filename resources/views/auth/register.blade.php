<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Registro Operativo - Plataforma CCG</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- FontAwesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts: Orbitron & Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Orbitron:wght@500;700;800;900&display=swap" rel="stylesheet">

    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body, html { height: 100%; font-family: 'Inter', sans-serif; background-color: #0a0f1d; display: flex; justify-content: center; align-items: center; overflow-x: hidden; color: #e2e8f0; }
        .font-orbitron { font-family: 'Orbitron', sans-serif; }
        
        .circuit-bg {
            background-color: #0a0f1d;
            background-image: 
                radial-gradient(circle at 50% 50%, rgba(0, 136, 255, 0.12) 0%, transparent 70%),
                linear-gradient(rgba(0, 240, 255, 0.04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0, 240, 255, 0.04) 1px, transparent 1px);
            background-size: 100% 100%, 45px 45px, 45px 45px;
        }

        .glass-panel-animated {
            background: rgba(15, 23, 42, 0.82);
            backdrop-filter: blur(25px);
            -webkit-backdrop-filter: blur(25px);
            border: 1px solid rgba(0, 136, 255, 0.4);
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.9), 0 0 40px rgba(0, 136, 255, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.15);
            animation: cardAppear 1s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            overflow: hidden;
        }

        @keyframes cardAppear {
            from { opacity: 0; transform: translateY(30px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

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

        .sci-input {
            background: rgba(15, 23, 42, 0.9);
            border: 1px solid rgba(0, 240, 255, 0.3);
            color: #f8fafc;
            transition: all 0.3s ease;
        }
        .sci-input:focus {
            outline: none;
            border-color: #00f0ff;
            box-shadow: 0 0 20px rgba(0, 240, 255, 0.45), inset 0 0 10px rgba(0, 240, 255, 0.2);
            transform: translateX(2px);
        }

        .btn-cyber {
            background: linear-gradient(135deg, #0088ff 0%, #00f0ff 100%);
            border: 1px solid rgba(0, 240, 255, 0.8);
            box-shadow: 0 0 25px rgba(0, 136, 255, 0.5);
            transition: all 0.3s ease;
        }
        .btn-cyber:hover {
            box-shadow: 0 0 40px rgba(0, 240, 255, 0.9);
            transform: translateY(-2px) scale(1.01);
        }

        .eye-btn {
            transition: all 0.3s ease;
        }
        .eye-btn:hover {
            color: #00f0ff;
            transform: scale(1.15);
            filter: drop-shadow(0 0 8px #00f0ff);
        }
    </style>
</head>
<body class="circuit-bg min-h-screen flex items-center justify-center p-4">

    <div class="w-full max-w-md my-8">
        <div class="glass-panel-animated rounded-3xl p-8 sm:p-10 relative">
            
            <!-- Corner Brackets -->
            <div class="corner-bracket corner-tl"></div>
            <div class="corner-bracket corner-tr"></div>
            <div class="corner-bracket corner-bl"></div>
            <div class="corner-bracket corner-br"></div>

            <!-- Header -->
            <div class="mb-6 text-center">
                <div class="inline-flex items-center gap-2 bg-slate-900/80 border border-cyan-500/30 px-3.5 py-1 rounded-full mb-3">
                    <span class="w-2 h-2 rounded-full bg-cyan-400 animate-ping"></span>
                    <span class="font-orbitron text-[9px] tracking-widest text-cyan-400 uppercase">NEW OPERATIVE REGISTRATION</span>
                </div>
                <h1 class="font-orbitron text-xl sm:text-2xl font-black text-white tracking-wider drop-shadow-[0_0_15px_rgba(0,240,255,0.6)]">
                    REGISTRO CCG
                </h1>
                <p class="font-orbitron text-xs text-cyan-300/80 uppercase tracking-widest mt-1">
                    Cree sus credenciales de acceso
                </p>
            </div>

            <!-- Form -->
            <form method="POST" action="{{ route('register') }}" class="space-y-4">
                @csrf

                <!-- Name Input -->
                <div class="space-y-1.5">
                    <label for="name" class="block font-orbitron text-[11px] tracking-wider text-slate-300">
                        NOMBRE COMPLETO
                    </label>
                    <div class="relative">
                        <input 
                            id="name" 
                            type="text" 
                            name="name" 
                            value="{{ old('name') }}" 
                            required 
                            autofocus 
                            autocomplete="name"
                            placeholder="Operativo..."
                            class="sci-input w-full px-4 py-3 rounded-xl text-sm placeholder-slate-500 font-sans tracking-wide"
                        >
                    </div>
                    @error('name')
                        <p class="text-xs text-red-400 font-orbitron tracking-wider mt-1 drop-shadow-[0_0_8px_rgba(239,68,68,0.6)]">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Email Input -->
                <div class="space-y-1.5">
                    <label for="email" class="block font-orbitron text-[11px] tracking-wider text-slate-300">
                        CORREO ELECTRÓNICO
                    </label>
                    <div class="relative">
                        <input 
                            id="email" 
                            type="email" 
                            name="email" 
                            value="{{ old('email') }}" 
                            required 
                            autocomplete="username"
                            placeholder="operativo@ccg-platform.com"
                            class="sci-input w-full px-4 py-3 rounded-xl text-sm placeholder-slate-500 font-sans tracking-wide"
                        >
                    </div>
                    @error('email')
                        <p class="text-xs text-red-400 font-orbitron tracking-wider mt-1 drop-shadow-[0_0_8px_rgba(239,68,68,0.6)]">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Role Selection -->
                <div class="space-y-1.5">
                    <label for="role" class="block font-orbitron text-[11px] tracking-wider text-slate-300">
                        NIVEL DE ACCESO (ROL)
                    </label>
                    <div class="relative">
                        <select 
                            id="role" 
                            name="role" 
                            class="sci-input w-full px-4 py-3 rounded-xl text-sm font-sans tracking-wide bg-[#0f172a]"
                        >
                            <option value="cliente" {{ old('role', 'cliente') == 'cliente' ? 'selected' : '' }}>Cliente (Standard)</option>
                            <option value="supervisor" {{ old('role') == 'supervisor' ? 'selected' : '' }}>Supervisor</option>
                            <option value="admin" {{ old('role') == 'admin' ? 'selected' : '' }}>Administrador</option>
                        </select>
                    </div>
                    @error('role')
                        <p class="text-xs text-red-400 font-orbitron tracking-wider mt-1 drop-shadow-[0_0_8px_rgba(239,68,68,0.6)]">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Password Input -->
                <div class="space-y-1.5">
                    <label for="password" class="block font-orbitron text-[11px] tracking-wider text-slate-300">
                        CONTRASEÑA
                    </label>
                    <div class="relative">
                        <input 
                            id="password" 
                            type="password" 
                            name="password" 
                            required 
                            autocomplete="new-password"
                            placeholder="••••••••••••"
                            class="sci-input w-full px-4 py-3 rounded-xl text-sm placeholder-slate-500 font-sans tracking-wide pr-10"
                        >
                        <button 
                            type="button" 
                            onclick="togglePassword('password', 'eyeIcon1')"
                            class="eye-btn absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-cyan-400 focus:outline-none cursor-pointer"
                        >
                            <i id="eyeIcon1" class="fa-solid fa-eye text-sm"></i>
                        </button>
                    </div>
                    @error('password')
                        <p class="text-xs text-red-400 font-orbitron tracking-wider mt-1 drop-shadow-[0_0_8px_rgba(239,68,68,0.6)]">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Confirm Password Input -->
                <div class="space-y-1.5">
                    <label for="password_confirmation" class="block font-orbitron text-[11px] tracking-wider text-slate-300">
                        CONFIRMAR CONTRASEÑA
                    </label>
                    <div class="relative">
                        <input 
                            id="password_confirmation" 
                            type="password" 
                            name="password_confirmation" 
                            required 
                            autocomplete="new-password"
                            placeholder="••••••••••••"
                            class="sci-input w-full px-4 py-3 rounded-xl text-sm placeholder-slate-500 font-sans tracking-wide pr-10"
                        >
                        <button 
                            type="button" 
                            onclick="togglePassword('password_confirmation', 'eyeIcon2')"
                            class="eye-btn absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-cyan-400 focus:outline-none cursor-pointer"
                        >
                            <i id="eyeIcon2" class="fa-solid fa-eye text-sm"></i>
                        </button>
                    </div>
                    @error('password_confirmation')
                        <p class="text-xs text-red-400 font-orbitron tracking-wider mt-1 drop-shadow-[0_0_8px_rgba(239,68,68,0.6)]">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Submit Button -->
                <div class="pt-2">
                    <button 
                        type="submit" 
                        class="btn-cyber w-full py-3.5 px-6 rounded-xl text-white font-orbitron font-bold text-sm tracking-widest uppercase cursor-pointer"
                    >
                        REGISTRAR
                    </button>
                </div>
            </form>

            <!-- Login Link -->
            <div class="mt-6 text-center">
                <p class="font-orbitron text-xs text-slate-400">
                    ¿Ya tienes cuenta? 
                    <a href="{{ route('login') }}" class="text-cyan-400 hover:text-cyan-300 hover:underline tracking-wider font-bold drop-shadow-[0_0_8px_rgba(0,240,255,0.6)]">
                        Iniciar Sesión
                    </a>
                </p>
            </div>

            <!-- Footer Status -->
            <div class="mt-6 pt-4 border-t border-cyan-500/20 flex items-center justify-between text-[10px] font-orbitron text-slate-400">
                <span class="flex items-center gap-1.5">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                    Seguridad Activa
                </span>
                <span class="text-cyan-400">CCG NET</span>
            </div>
        </div>
    </div>

    <!-- JavaScript for Password Toggle -->
    <script>
        function togglePassword(fieldId, iconId) {
            const input = document.getElementById(fieldId);
            const icon = document.getElementById(iconId);

            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash', 'text-cyan-400');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash', 'text-cyan-400');
                icon.classList.add('fa-eye');
            }
        }
    </script>
</body>
</html>
