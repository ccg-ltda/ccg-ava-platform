<!DOCTYPE html>
<html lang="es" class="dark" data-accent="cyan">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Login - Plataforma CCG</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        (function() {
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'light') {
                document.documentElement.classList.remove('dark');
            } else if (savedTheme === 'dark') {
                document.documentElement.classList.add('dark');
            } else {
                if (window.matchMedia('(prefers-color-scheme: light)').matches) {
                    document.documentElement.classList.remove('dark');
                } else {
                    document.documentElement.classList.add('dark');
                }
            }
        })();
        function toggleTheme() {
            const html = document.documentElement;
            const isDark = html.classList.toggle('dark');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            document.querySelectorAll('.theme-toggle-icon').forEach(icon => {
                icon.className = isDark ? 'fa-solid fa-moon theme-toggle-icon' : 'fa-solid fa-sun theme-toggle-icon';
            });
        }
    </script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --cyan: #00eaff;
            --cyan-dark: #009fc4;
            --blue: #008cff;
            --bg: #020b18;
            --panel: rgba(3, 17, 34, 0.88);
            --border: rgba(0, 225, 255, 0.75);
            --text: #eefcff;
            --muted: #66b9cf;
        }

        body {
            min-height: 100vh;
            overflow-x: hidden;
            font-family: "Orbitron", sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at 15% 30%, rgba(0, 217, 255, 0.13), transparent 25%),
                radial-gradient(circle at 85% 70%, rgba(0, 135, 255, 0.12), transparent 30%),
                #020914;
            transition: background-color 0.3s ease, color 0.3s ease;
        }

        html.light body {
            background:
                radial-gradient(circle at 15% 30%, rgba(0, 140, 255, 0.08), transparent 25%),
                radial-gradient(circle at 85% 70%, rgba(0, 80, 200, 0.06), transparent 30%),
                #e8eef5;
            color: #0f172a;
        }

        .background {
            position: fixed; inset: 0; z-index: -10;
            overflow: hidden;
            background:
                linear-gradient(rgba(0, 226, 255, 0.035) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0, 226, 255, 0.035) 1px, transparent 1px);
            background-size: 48px 48px;
        }
        html.light .background {
            background:
                linear-gradient(rgba(0, 140, 255, 0.06) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0, 140, 255, 0.06) 1px, transparent 1px);
        }

        .background::before {
            content: ""; position: absolute; inset: 0;
            background:
                linear-gradient(rgba(0, 226, 255, 0.025) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0, 226, 255, 0.025) 1px, transparent 1px);
            background-size: 8px 8px; opacity: 0.35;
        }
        html.light .background::before {
            background:
                linear-gradient(rgba(0, 140, 255, 0.05) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0, 140, 255, 0.05) 1px, transparent 1px);
        }

        .background::after {
            content: ""; position: absolute; width: 800px; height: 800px; left: -300px; bottom: -400px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(0, 225, 255, 0.08), transparent 70%);
            filter: blur(20px);
        }

        .particle {
            position: absolute; width: 3px; height: 3px; border-radius: 50%;
            background: var(--cyan);
            box-shadow: 0 0 8px var(--cyan), 0 0 16px var(--cyan);
            opacity: 0.55;
            animation: floatParticle linear infinite;
        }
        html.light .particle {
            background: var(--blue);
            box-shadow: 0 0 8px var(--blue), 0 0 16px var(--blue);
        }

        @keyframes floatParticle {
            from { transform: translateY(20px); opacity: 0; }
            20% { opacity: 0.7; }
            80% { opacity: 0.7; }
            to { transform: translateY(-120px); opacity: 0; }
        }

        .app { width: min(1750px, 94%); min-height: 100vh; margin: auto; display: flex; flex-direction: column; padding: 24px 0 30px; }

        .topbar { display: flex; justify-content: flex-end; align-items: center; gap: 16px; height: 70px; }

        .color-selector {
            display: flex; align-items: center; gap: 10px; padding: 8px 12px;
            border: 1px solid rgba(0, 225, 255, 0.5); border-radius: 30px;
            background: rgba(0, 15, 30, 0.75);
            box-shadow: 0 0 20px rgba(0, 225, 255, 0.08), inset 0 0 20px rgba(0, 225, 255, 0.04);
        }
        html.light .color-selector { background: rgba(255,255,255,0.7); border-color: rgba(0, 140, 255, 0.4); }

        .color { width: 22px; height: 22px; border-radius: 50%; cursor: pointer; transition: 0.25s ease; border: 2px solid transparent; }
        .color:hover { transform: scale(1.2); }
        .color.active { outline: 2px solid white; outline-offset: 3px; }
        .cyan { background: #00eaff; } .purple { background: #c83cff; } .green { background: #18c875; } .red { background: #ff3f4c; }

        .round-button {
            width: 56px; height: 56px; border: none; border-radius: 50%;
            background: #edf6fc; color: #0a1724;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: 0.3s;
        }
        .round-button:hover { transform: translateY(-3px); box-shadow: 0 0 20px rgba(0, 225, 255, 0.5); }
        .round-button:focus { outline: none; box-shadow: 0 0 0 2px rgba(0, 225, 255, 0.5); }

        .main { flex: 1; display: grid; grid-template-columns: minmax(0, 1.35fr) minmax(470px, 0.9fr); gap: 30px; align-items: center; }

        .hero-wrapper { min-width: 0; }

        .hero {
            min-height: 580px; position: relative; border-radius: 24px;
            border: 1px solid rgba(0, 225, 255, 0.45);
            background:
                radial-gradient(circle at 30% 50%, rgba(0, 225, 255, 0.08), transparent 35%),
                rgba(1, 10, 23, 0.84);
            overflow: hidden;
            box-shadow: inset 0 0 50px rgba(0, 225, 255, 0.025), 0 0 35px rgba(0, 225, 255, 0.05);
            transition: background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
        }
        html.light .hero {
            background:
                radial-gradient(circle at 30% 50%, rgba(0, 140, 255, 0.06), transparent 35%),
                rgba(255, 255, 255, 0.85);
            border-color: rgba(0, 140, 255, 0.3);
            box-shadow: inset 0 0 50px rgba(0, 140, 255, 0.02), 0 0 35px rgba(0, 140, 255, 0.08);
        }

        .hero::before, .hero::after { content: ""; position: absolute; width: 130px; height: 1px; background: var(--cyan); box-shadow: 0 0 12px var(--cyan); }
        .hero::before { top: 0; left: 0; }
        .hero::after { bottom: 0; right: 0; }
        html.light .hero::before, html.light .hero::after { background: var(--blue); box-shadow: 0 0 12px var(--blue); }

        .hero-content { height: 100%; display: flex; align-items: center; gap: 50px; padding: 50px; }

        .globe-container { position: relative; width: 52%; max-width: 560px; aspect-ratio: 1; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .globe {
            position: relative; width: 78%; aspect-ratio: 1; border-radius: 50%;
            background:
                radial-gradient(circle at 35% 30%, rgba(255,255,255,0.45), transparent 2%),
                radial-gradient(circle, rgba(0, 234, 255, 0.08), rgba(0, 234, 255, 0.01) 55%, transparent 72%);
            box-shadow: 0 0 35px rgba(0, 234, 255, 0.55), 0 0 80px rgba(0, 174, 255, 0.18);
            overflow: hidden;
        }
        html.light .globe {
            background:
                radial-gradient(circle at 35% 30%, rgba(255,255,255,0.6), transparent 2%),
                radial-gradient(circle, rgba(0, 140, 255, 0.08), rgba(0, 140, 255, 0.01) 55%, transparent 72%);
            box-shadow: 0 0 35px rgba(0, 140, 255, 0.35), 0 0 80px rgba(0, 140, 255, 0.1);
        }
        .globe::before {
            content: ""; position: absolute; inset: 0;
            background: radial-gradient(circle, var(--cyan) 1px, transparent 1.5px);
            background-size: 8px 8px; opacity: 0.8;
            mask-image: radial-gradient(circle, black 50%, transparent 72%);
            -webkit-mask-image: radial-gradient(circle, black 50%, transparent 72%);
            animation: globePulse 4s ease-in-out infinite;
        }
        .globe::after {
            content: ""; position: absolute; width: 120%; height: 32%; left: -10%; top: 34%;
            border: 1px solid rgba(0, 234, 255, 0.8); border-radius: 50%; transform: rotate(-15deg);
            box-shadow: 0 0 12px rgba(0, 234, 255, 0.5);
        }
        html.light .globe::after { border-color: rgba(0, 140, 255, 0.6); box-shadow: 0 0 12px rgba(0, 140, 255, 0.4); }

        @keyframes globePulse { 0%, 100% { transform: scale(1); opacity: 0.7; } 50% { transform: scale(1.04); opacity: 1; } }

        .orbit {
            position: absolute; width: 105%; height: 32%;
            border: 1px solid rgba(0, 234, 255, 0.5); border-radius: 50%;
            transform: rotate(20deg); box-shadow: 0 0 15px rgba(0, 234, 255, 0.3);
            animation: orbitSpin 8s linear infinite;
        }
        .orbit:nth-child(2) { transform: rotate(-30deg); animation-duration: 11s; }
        .orbit:nth-child(3) { transform: rotate(70deg); animation-duration: 13s; }
        html.light .orbit { border-color: rgba(0, 140, 255, 0.4); box-shadow: 0 0 15px rgba(0, 140, 255, 0.2); }

        @keyframes orbitSpin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

        .brand { min-width: 300px; }
        .brand-logo { font-size: clamp(4rem, 6vw, 7rem); font-weight: 900; letter-spacing: 8px; line-height: 0.9; color: white; text-shadow: 0 0 15px rgba(255,255,255,0.15), 0 0 35px rgba(0, 225, 255, 0.3); }
        html.light .brand-logo { color: #0a1724; text-shadow: 0 0 15px rgba(0, 140, 255, 0.15), 0 0 35px rgba(0, 140, 255, 0.2); }
        .brand-subtitle { margin-top: 18px; font-size: 18px; letter-spacing: 10px; color: white; }
        html.light .brand-subtitle { color: #0a1724; }
        .brand-description { margin-top: 48px; max-width: 360px; font-family: "Orbitron", sans-serif; font-size: 17px; line-height: 1.8; color: #d8edf4; }
        html.light .brand-description { color: #1e3a5f; }
        .brand-description strong { color: var(--cyan); text-shadow: 0 0 10px rgba(0, 234, 255, 0.7); }
        html.light .brand-description strong { color: var(--blue); text-shadow: 0 0 10px rgba(0, 140, 255, 0.5); }
        .small-line { width: 95px; height: 4px; margin-top: 20px; background: var(--cyan); box-shadow: 0 0 12px var(--cyan); border-radius: 10px; }
        html.light .small-line { background: var(--blue); box-shadow: 0 0 12px var(--blue); }

        .login-card {
            position: relative; padding: 40px 50px;
            border: 1px solid var(--border); border-radius: 24px;
            background: linear-gradient(145deg, rgba(4, 24, 45, 0.94), rgba(2, 14, 28, 0.9));
            box-shadow: 0 0 30px rgba(0, 225, 255, 0.1), inset 0 0 30px rgba(0, 225, 255, 0.025);
            overflow: hidden;
            animation: fadeInUp 0.8s ease-out both;
            transition: background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
        }
        html.light .login-card {
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.95), rgba(240, 245, 252, 0.92));
            border-color: rgba(0, 140, 255, 0.4);
            box-shadow: 0 0 30px rgba(0, 140, 255, 0.08), inset 0 0 30px rgba(0, 140, 255, 0.02);
        }

        .login-card::before { content: ""; position: absolute; top: 0; left: 0; width: 52px; height: 52px; border-top: 3px solid var(--cyan); border-left: 3px solid var(--cyan); box-shadow: -4px -4px 20px rgba(0, 225, 255, 0.25); }
        .login-card::after { content: ""; position: absolute; right: 0; bottom: 0; width: 52px; height: 52px; border-right: 3px solid var(--cyan); border-bottom: 3px solid var(--cyan); box-shadow: 4px 4px 20px rgba(0, 225, 255, 0.25); }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .login-title-small { color: var(--cyan); font-size: 18px; letter-spacing: 5px; margin-bottom: 4px; }
        html.light .login-title-small { color: var(--blue); }
        .login-title { font-size: clamp(2rem, 3vw, 3rem); line-height: 1; font-weight: 800; color: white; white-space: nowrap; }
        html.light .login-title { color: #0a1724; }
        .login-subtitle { margin-top: 14px; font-size: 15px; letter-spacing: 5px; color: var(--cyan); }
        html.light .login-subtitle { color: var(--blue); }
        .divider { height: 1px; margin: 20px 0 30px; background: linear-gradient(to right, var(--cyan), rgba(0, 225, 255, 0.05)); }
        html.light .divider { background: linear-gradient(to right, var(--blue), rgba(0, 140, 255, 0.05)); }

        .field { margin-bottom: 25px; position: relative; }
        .field-label { display: flex; align-items: center; gap: 10px; margin-bottom: 9px; font-size: 14px; font-weight: 700; letter-spacing: 1px; color: #dffaff; transition: all 0.25s ease; }
        html.light .field-label { color: #1e40af; }
        .field-label svg { color: var(--cyan); }
        html.light .field-label svg { color: var(--blue); }
        .field.focused .field-label { color: var(--cyan); }
        html.light .field.focused .field-label { color: var(--blue); }

        .input-container {
            display: flex; align-items: center; height: 60px;
            border: 1px solid rgba(0, 225, 255, 0.55); border-radius: 15px;
            background: rgba(3, 19, 36, 0.9); transition: 0.25s;
        }
        html.light .input-container {
            border-color: rgba(0, 140, 255, 0.4); background: rgba(255, 255, 255, 0.85);
        }
        .input-container:focus-within {
            border-color: var(--cyan); box-shadow: 0 0 0 2px rgba(0, 225, 255, 0.08), 0 0 20px rgba(0, 225, 255, 0.12);
        }
        html.light .input-container:focus-within {
            border-color: var(--blue); box-shadow: 0 0 0 2px rgba(0, 140, 255, 0.1), 0 0 20px rgba(0, 140, 255, 0.15);
        }
        .input-icon { width: 60px; display: flex; justify-content: center; color: var(--cyan); }
        html.light .input-icon { color: var(--blue); }
        input { flex: 1; height: 100%; border: none; outline: none; background: transparent; color: white; font-family: "Orbitron", sans-serif; font-size: 14px; }
        html.light input { color: #0f172a; }
        input::placeholder { color: #6b9aaa; }
        html.light input::placeholder { color: #64748b; }
        .password-toggle { width: 55px; border: none; background: transparent; color: var(--cyan); cursor: pointer; }
        html.light .password-toggle { color: var(--blue); }

        .login-button {
            width: 100%; height: 70px; margin-top: 5px; border: none; border-radius: 16px; cursor: pointer;
            font-family: "Orbitron", sans-serif; font-size: 20px; font-weight: 800; color: white;
            background: linear-gradient(100deg, #008de9, #00e5ef);
            box-shadow: 0 0 25px rgba(0, 225, 255, 0.2);
            display: flex; align-items: center; justify-content: center; gap: 15px; transition: 0.3s;
        }
        .login-button:hover { transform: translateY(-3px); box-shadow: 0 0 30px rgba(0, 225, 255, 0.5); }
        .login-button:active { transform: scale(0.98); }
        .login-button:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
        html.light .login-button { background: linear-gradient(100deg, #0070cc, #00a8d4); }

        .register-button {
            width: 100%; height: 62px; margin-top: 20px; border: 1px solid rgba(0, 225, 255, 0.7);
            border-radius: 15px; cursor: pointer; font-family: "Orbitron", sans-serif; font-size: 15px;
            font-weight: 700; color: var(--cyan); background: rgba(0, 30, 50, 0.4); transition: 0.3s;
        }
        .register-button:hover { background: rgba(0, 225, 255, 0.1); box-shadow: 0 0 20px rgba(0, 225, 255, 0.2); }
        html.light .register-button { color: var(--blue); border-color: rgba(0, 140, 255, 0.6); background: rgba(0, 100, 200, 0.05); }
        html.light .register-button:hover { background: rgba(0, 140, 255, 0.1); }

        .status {
            width: fit-content; min-width: 870px; margin: 30px 0 0 30px; padding: 12px 28px;
            border: 1px solid rgba(0, 225, 255, 0.45); border-radius: 50px;
            background: rgba(1, 17, 32, 0.9); display: flex; align-items: center; gap: 28px;
            box-shadow: 0 0 25px rgba(0, 225, 255, 0.08);
        }
        html.light .status { background: rgba(255,255,255,0.7); border-color: rgba(0, 140, 255, 0.3); }
        .status-title { display: flex; align-items: center; gap: 12px; font-size: 18px; font-weight: 700; letter-spacing: 2px; }
        .status-dot { width: 14px; height: 14px; border-radius: 50%; background: var(--cyan); box-shadow: 0 0 12px var(--cyan); }
        html.light .status-dot { background: var(--blue); box-shadow: 0 0 12px var(--blue); }
        .status-item { padding-left: 22px; border-left: 1px solid rgba(0, 225, 255, 0.25); display: flex; flex-direction: column; gap: 3px; }
        html.light .status-item { border-left-color: rgba(0, 140, 255, 0.2); }
        .status-item span:first-child { color: #5c9bad; font-size: 11px; letter-spacing: 1px; }
        html.light .status-item span:first-child { color: #64748b; }
        .status-item span:last-child { color: var(--cyan); font-size: 13px; font-weight: 700; }
        html.light .status-item span:last-child { color: var(--blue); }

        .ripple {
            position: fixed; width: 10px; height: 10px; border-radius: 50%;
            background: rgba(0, 234, 255, 0.7); transform: translate(-50%, -50%);
            pointer-events: none; animation: ripple 0.6s ease-out forwards; z-index: 9999;
        }
        html.light .ripple { background: rgba(0, 140, 255, 0.7); }

        @keyframes ripple {
            from { width: 10px; height: 10px; opacity: 0.7; }
            to { width: 100px; height: 100px; opacity: 0; box-shadow: 0 0 30px var(--cyan); }
        }

        .shake { animation: shake 0.5s ease-in-out; }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20% { transform: translateX(-10px); }
            40% { transform: translateX(10px); }
            60% { transform: translateX(-6px); }
            80% { transform: translateX(6px); }
        }

        .error-message {
            color: #ff6b7a; font-size: 13px; margin-top: 8px; display: none;
            font-family: "Orbitron", sans-serif; letter-spacing: 1px;
        }
        html.light .error-message { color: #dc2626; }
        .error-message.show { display: block; animation: fadeIn 0.3s ease; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        .floating-label,
        .field-label {
            position: absolute; top: 50%; left: 65px; transform: translateY(-50%);
            font-size: 14px; color: #6b9aaa; pointer-events: none; transition: 0.2s ease;
            font-family: "Orbitron", sans-serif; letter-spacing: 1px; white-space: nowrap;
        }
        html.light .floating-label,
        html.light .field-label { color: #94a3b8; }
        input:focus ~ .floating-label,
        input:focus ~ .field-label,
        input:not(:placeholder-shown) ~ .floating-label,
        input:not(:placeholder-shown) ~ .field-label {
            top: -8px; left: 12px; font-size: 10px; color: var(--cyan); background: rgba(3, 19, 36, 0.9);
            padding: 0 6px; border-radius: 4px;
        }
        html.light input:focus ~ .floating-label,
        html.light input:focus ~ .field-label,
        html.light input:not(:placeholder-shown) ~ .floating-label,
        html.light input:not(:placeholder-shown) ~ .field-label {
            color: var(--blue); background: rgba(255,255,255,0.9);
        }

        @media (max-width: 1200px) {
            .main { grid-template-columns: 1fr; }
            .hero { min-height: 500px; }
            .hero-content { justify-content: center; }
            .login-card { max-width: 700px; width: 100%; margin: auto; }
            .status { min-width: auto; width: 100%; margin-left: 0; }
        }
        @media (max-width: 750px) {
            .app { width: 94%; }
            .topbar { gap: 8px; }
            .round-button { width: 45px; height: 45px; }
            .color-selector { gap: 7px; padding: 7px 9px; }
            .color { width: 17px; height: 17px; }
            .hero { min-height: auto; }
            .hero-content { flex-direction: column; padding: 40px 20px; gap: 20px; }
            .globe-container { width: 80%; }
            .brand { text-align: center; }
            .brand-logo { font-size: 4rem; }
            .brand-subtitle { font-size: 13px; }
            .brand-description { margin: 25px auto 0; }
            .small-line { margin-left: auto; margin-right: auto; }
            .login-card { padding: 35px 22px; }
            .login-title { white-space: normal; }
            .status { flex-wrap: wrap; border-radius: 20px; padding: 18px; gap: 15px; }
            .status-title { width: 100%; }
            .status-item { border-left: none; padding-left: 0; }
        }
    </style>
</head>

<body>
    <div class="background">
        <div class="particle" style="left:5%; top:60%; animation-duration:7s;"></div>
        <div class="particle" style="left:12%; top:30%; animation-duration:9s;"></div>
        <div class="particle" style="left:24%; top:70%; animation-duration:6s;"></div>
        <div class="particle" style="left:37%; top:20%; animation-duration:8s;"></div>
        <div class="particle" style="left:48%; top:60%; animation-duration:10s;"></div>
        <div class="particle" style="left:63%; top:35%; animation-duration:7s;"></div>
        <div class="particle" style="left:72%; top:75%; animation-duration:9s;"></div>
        <div class="particle" style="left:84%; top:25%; animation-duration:8s;"></div>
        <div class="particle" style="left:94%; top:55%; animation-duration:6s;"></div>
    </div>

    <div class="app">
        <header class="topbar">
            <div class="color-selector">
                <div class="color cyan active" data-color="#00eaff"></div>
                <div class="color purple" data-color="#c83cff"></div>
                <div class="color green" data-color="#18c875"></div>
                <div class="color red" data-color="#ff3f4c"></div>
            </div>
            <button class="round-button" id="langBtn" title="Idioma"><strong>ES</strong></button>
            <button class="round-button" id="soundButton" title="Audio">
                <i data-lucide="volume-2"></i>
            </button>
            <button class="round-button" title="Configuración">
                <i data-lucide="settings"></i>
            </button>
            <button class="round-button" onclick="toggleTheme()" title="Cambiar Modo Claro/Oscuro">
                <i data-lucide="sun" class="theme-toggle-icon"></i>
            </button>
        </header>

        <main class="main">
            <section class="hero-wrapper">
                <div class="hero">
                    <div class="hero-content">
                        <div class="globe-container">
                            <div class="orbit"></div>
                            <div class="orbit"></div>
                            <div class="orbit"></div>
                            <div class="globe"></div>
                        </div>
                        <div class="brand">
                            <div class="brand-logo">CCG</div>
                            <div class="brand-subtitle">SISTEMA GLOBAL</div>
                            <p class="brand-description">
                                Conectamos talento,<br>
                                creamos <strong>oportunidades</strong>
                            </p>
                            <div class="small-line"></div>
                        </div>
                    </div>
                </div>

                <div class="status">
                    <div class="status-title">
                        <span class="status-dot"></span>
                        NODO GLOBAL CCG
                    </div>
                    <div class="status-item">
                        <span>LATENCIA</span>
                        <span>10ms</span>
                    </div>
                    <div class="status-item">
                        <span>CPU</span>
                        <span>16%</span>
                    </div>
                    <div class="status-item">
                        <span>SEC</span>
                        <span>256-bit</span>
                    </div>
                </div>
            </section>

            <section class="login-card" id="loginCard">
                <div class="login-title-small">LOGIN</div>
                <h1 class="login-title">PLATAFORMA CCG</h1>
                <div class="login-subtitle">SISTEMA GLOBAL</div>
                <div class="divider"></div>

                <form id="loginForm">
                    @csrf
                    <div id="form-error" class="error-message"></div>

                    <div class="field" id="field-email">
                        <label class="field-label" for="email">
                            <i data-lucide="user-round" style="width:18px;height:18px;"></i>
                            USUARIO (E-MAIL)
                        </label>
                        <div class="input-container">
                            <div class="input-icon"><i data-lucide="mail" style="width:20px;height:20px;"></i></div>
                            <input type="email" id="email" name="email" placeholder=" " required autocomplete="email" />
                            <div class="input-icon"><i data-lucide="user-plus" style="width:20px;height:20px;"></i></div>
                        </div>
                    </div>

                    <div class="field" id="field-password">
                        <label class="field-label" for="password">
                            <i data-lucide="lock-keyhole" style="width:18px;height:18px;"></i>
                            CONTRASEÑA
                        </label>
                        <div class="input-container">
                            <div class="input-icon"><i data-lucide="lock" style="width:20px;height:20px;"></i></div>
                            <input type="password" id="password" name="password" placeholder=" " required autocomplete="current-password" />
                            <button type="button" class="password-toggle" id="togglePassword">
                                <i data-lucide="eye" style="width:20px;height:20px;"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="login-button" id="loginButton">
                        <i data-lucide="circle-arrow-right" style="width:24px;height:24px;"></i>
                        ACCEDER
                    </button>

                    <button type="button" class="register-button" id="registerButton">
                        REGÍSTRATE
                    </button>

                    <div style="text-align:center; margin-top:16px;">
                        <a href="{{ route('password.request') }}" style="color:var(--cyan); font-size:13px; letter-spacing:1px; text-decoration:none; transition:0.2s;" id="forgotPassword">
                            ¿Olvidé mi contraseña?
                        </a>
                    </div>
                </form>
            </section>
        </main>
    </div>

    <script>
        lucide.createIcons();

        // Password toggle
        const togglePassword = document.getElementById("togglePassword");
        const password = document.getElementById("password");
        togglePassword.addEventListener("click", () => {
            const isPassword = password.type === "password";
            password.type = isPassword ? "text" : "password";
            togglePassword.innerHTML = isPassword
                ? '<i data-lucide="eye-off" style="width:20px;height:20px;"></i>'
                : '<i data-lucide="eye" style="width:20px;height:20px;"></i>';
            lucide.createIcons();
        });

        // Color selector
        const colors = document.querySelectorAll(".color");
        colors.forEach(color => {
            color.addEventListener("click", () => {
                colors.forEach(c => c.classList.remove("active"));
                color.classList.add("active");
                document.documentElement.style.setProperty("--cyan", color.dataset.color);
            });
        });

        // Floating labels
        document.querySelectorAll("input").forEach(input => {
            input.addEventListener("focus", () => {
                input.closest(".field").classList.add("focused");
            });
            input.addEventListener("blur", () => {
                if (!input.value) input.closest(".field").classList.remove("focused");
            });
        });

        // Focus ring for inputs
        document.querySelectorAll(".input-container").forEach(container => {
            const input = container.querySelector("input");
            if (input) {
                input.addEventListener("focus", () => {
                    container.style.borderColor = "var(--cyan)";
                    container.style.boxShadow = "0 0 0 2px rgba(0, 225, 255, 0.15), 0 0 20px rgba(0, 225, 255, 0.2)";
                });
                input.addEventListener("blur", () => {
                    container.style.borderColor = "";
                    container.style.boxShadow = "";
                });
            }
        });

        // === LOGIN with Laravel backend ===
        const loginForm = document.getElementById("loginForm");
        const loginButton = document.getElementById("loginButton");
        const loginCard = document.getElementById("loginCard");
        const formError = document.getElementById("form-error");
        const emailInput = document.getElementById("email");
        const passwordInput = document.getElementById("password");

        loginForm.addEventListener("submit", async (event) => {
            event.preventDefault();
            formError.classList.remove("show");
            formError.textContent = "";
            loginCard.classList.remove("shake");

            const email = emailInput.value.trim();
            const password = passwordInput.value;

            if (!email || !password) {
                showError("Por favor, complete todos los campos.");
                return;
            }

            // Loading state
            const originalHTML = loginButton.innerHTML;
            loginButton.disabled = true;
            loginButton.innerHTML = `<i data-lucide="loader-circle" style="width:24px;height:24px;animation:spin 1s linear infinite;"></i> CONECTANDO...`;
            lucide.createIcons();

            const formData = new FormData(loginForm);

            try {
                const response = await fetch("{{ route('login') }}", {
                    method: "POST",
                    headers: {
                        "Accept": "application/json",
                        "X-Requested-With": "XMLHttpRequest"
                    },
                    credentials: "same-origin",
                    body: formData
                });

                if (response.redirected) {
                    window.location.href = response.url;
                    return;
                }

                const data = await response.json();

                if (data.errors) {
                    const firstError = Object.values(data.errors)[0][0];
                    showError(firstError);
                } else if (data.message) {
                    showError(data.message);
                } else {
                    showError("Credenciales incorrectas. Intente nuevamente.");
                }
            } catch (error) {
                showError("Error de conexión. Verifique su red e intente de nuevo.");
            } finally {
                loginButton.disabled = false;
                loginButton.innerHTML = originalHTML;
                lucide.createIcons();
            }
        });

        function showError(msg) {
            formError.textContent = msg;
            formError.classList.add("show");
            loginCard.classList.add("shake");
            setTimeout(() => loginCard.classList.remove("shake"), 500);
        }

        // Register button
        document.getElementById("registerButton").addEventListener("click", () => {
            window.location.href = "{{ route('register') }}";
        });

        // Forgot password
        document.getElementById("forgotPassword").addEventListener("click", (e) => {
            e.preventDefault();
            window.location.href = "{{ route('password.request') }}";
        });

        // Sound toggle
        let soundEnabled = true;
        document.getElementById("soundButton").addEventListener("click", function () {
            soundEnabled = !soundEnabled;
            this.innerHTML = soundEnabled
                ? '<i data-lucide="volume-2" style="width:20px;height:20px;"></i>'
                : '<i data-lucide="volume-x" style="width:20px;height:20px;"></i>';
            lucide.createIcons();
        });

        // Language toggle
        let currentLang = "es";
        const translations = {
            es: { lang: "ES", register: "REGÍSTRATE", forgot: "¿Olvidé mi contraseña?" },
            en: { lang: "EN", register: "REGISTER", forgot: "Forgot Password?" }
        };
        document.getElementById("langBtn").addEventListener("click", () => {
            currentLang = currentLang === "es" ? "en" : "es";
            document.getElementById("langBtn").innerHTML = `<strong>${translations[currentLang].lang}</strong>`;
            document.getElementById("registerButton").textContent = translations[currentLang].register;
            document.getElementById("forgotPassword").textContent = translations[currentLang].forgot;
        });

        // Ripple click effect
        document.addEventListener("click", (event) => {
            const ripple = document.createElement("div");
            ripple.className = "ripple";
            ripple.style.left = event.clientX + "px";
            ripple.style.top = event.clientY + "px";
            document.body.appendChild(ripple);
            setTimeout(() => ripple.remove(), 600);
        });

        // Add spin keyframe
        const style = document.createElement("style");
        style.textContent = `@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }`;
        document.head.appendChild(style);
    </script>
</body>
</html>