<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Plataforma CCG' }} - Cyber Terminal</title>
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
                radial-gradient(circle at 50% 50%, rgba(0, 136, 255, 0.08) 0%, transparent 70%),
                linear-gradient(rgba(0, 240, 255, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0, 240, 255, 0.03) 1px, transparent 1px);
            background-size: 100% 100%, 45px 45px, 45px 45px;
        }

        /* Glassmorphism Panel */
        .glass-panel {
            background: rgba(15, 23, 42, 0.78);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(0, 240, 255, 0.25);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.7), 0 0 25px rgba(0, 240, 255, 0.1);
        }

        /* Glowing Cyber Button */
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

        /* Sci-Fi Inputs */
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
    </style>
</head>
<body class="circuit-bg min-h-screen flex text-slate-200">

    <!-- Sidebar -->
    <aside class="fixed inset-y-0 left-0 z-40 w-64 bg-[#0a0f18]/90 backdrop-blur-xl border-r border-cyan-500/20 text-white flex flex-col">
        <div class="flex h-16 items-center justify-center border-b border-cyan-500/20 px-4">
            <span class="font-orbitron text-base font-black tracking-widest text-cyan-400 drop-shadow-[0_0_10px_rgba(0,240,255,0.6)]">
                PLATAFORMA CCG
            </span>
        </div>
        <nav class="mt-6 space-y-2 px-3 flex-1">
            <a href="{{ route('dashboard') }}" class="flex items-center rounded-xl px-4 py-3 text-sm font-orbitron tracking-wider text-slate-300 transition-all hover:bg-cyan-500/10 hover:text-cyan-400 hover:shadow-[0_0_15px_rgba(0,240,255,0.2)] border border-transparent hover:border-cyan-500/30">
                <i class="fa-solid fa-chart-line me-3 text-cyan-400 drop-shadow-[0_0_6px_rgba(0,240,255,0.8)]"></i>
                Dashboard
            </a>
            <a href="{{ route('users.index') ?? '#' }}" class="flex items-center rounded-xl px-4 py-3 text-sm font-orbitron tracking-wider text-slate-300 transition-all hover:bg-cyan-500/10 hover:text-cyan-400 hover:shadow-[0_0_15px_rgba(0,240,255,0.2)] border border-transparent hover:border-cyan-500/30">
                <i class="fa-solid fa-users-gear me-3 text-cyan-400 drop-shadow-[0_0_6px_rgba(0,240,255,0.8)]"></i>
                Usuarios
            </a>
        </nav>
        <div class="p-4 border-t border-cyan-500/20 text-[10px] font-orbitron text-slate-400 text-center tracking-widest">
            CCG SECURE v2.4
        </div>
    </aside>

    <!-- Main Content Area -->
    <div class="flex flex-1 flex-col ml-64">
        <!-- Top Navbar -->
        <header class="sticky top-0 z-30 bg-[#0a0f18]/80 backdrop-blur-xl border-b border-cyan-500/20 shadow-lg shadow-cyan-500/5">
            <div class="flex items-center justify-between px-6 py-3.5">
                <div class="font-orbitron text-xs tracking-widest text-cyan-400 uppercase flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    Terminal Activa
                </div>
                <div class="flex items-center gap-4">
                    @auth
                        <span class="font-orbitron text-xs tracking-wider text-cyan-300/80">
                            {{ auth()->user()->email }}
                        </span>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="rounded-xl bg-red-600/20 border border-red-500/40 px-3.5 py-1.5 text-xs font-orbitron tracking-wider text-red-400 transition-all hover:bg-red-600 hover:text-white hover:shadow-[0_0_15px_rgba(239,68,68,0.6)] cursor-pointer">
                                CERRAR SESIÓN
                            </button>
                        </form>
                    @endauth
                </div>
            </div>
        </header>

        <!-- Page Content -->
        <main class="flex-1 p-8">
            @yield('content')
        </main>
    </div>

</body>
</html>
