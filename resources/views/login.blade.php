<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}">

  <title>Plataforma CCG - Login</title>

  <!-- Google Fonts: Orbitron -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />

  <!-- Lucide Icons -->
  <script src="https://unpkg.com/lucide@latest"></script>

  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }

    :root {
      --cyan: #00e5ff;
      --cyan-glow: rgba(0, 229, 255, 0.4);
      --bg: #030a16;
      --card-bg: rgba(2, 14, 28, 0.85);
      --border-cyan: rgba(0, 229, 255, 0.5);
      --text: #e1f8ff;
      --text-muted: #538299;
    }

    body {
      min-height: 100vh;
      background-color: var(--bg);
      background-image: 
        radial-gradient(circle at 15% 30%, var(--cyan-glow), transparent 25%),
        linear-gradient(rgba(0, 229, 255, 0.03) 1px, transparent 1px),
        linear-gradient(90deg, rgba(0, 229, 255, 0.03) 1px, transparent 1px);
      background-size: 100% 100%, 40px 40px, 40px 40px;
      font-family: "Orbitron", sans-serif;
      color: var(--text);
      display: flex;
      justify-content: center;
      align-items: center;
      padding: 20px;
      overflow-x: hidden;
      position: relative;
    }

    .particle {
      position: absolute;
      width: 3px;
      height: 3px;
      border-radius: 50%;
      background: var(--cyan);
      box-shadow: 0 0 8px var(--cyan);
      opacity: 0.6;
      animation: floatParticle linear infinite;
      pointer-events: none;
    }

    @keyframes floatParticle {
      0% { transform: translateY(0) scale(0.8); opacity: 0.2; }
      50% { opacity: 0.8; }
      100% { transform: translateY(-100vh) scale(1.2); opacity: 0; }
    }

    .ripple {
      position: fixed;
      width: 10px;
      height: 10px;
      border-radius: 50%;
      background: var(--cyan);
      box-shadow: 0 0 20px var(--cyan);
      transform: translate(-50%, -50%);
      pointer-events: none;
      animation: rippleAnim 0.6s ease-out forwards;
      z-index: 9999;
    }

    @keyframes rippleAnim {
      0% { width: 0; height: 0; opacity: 1; }
      100% { width: 120px; height: 120px; opacity: 0; }
    }

    .container {
      width: 100%;
      max-width: 1100px;
      display: flex;
      flex-direction: column;
      gap: 20px;
      z-index: 10;
    }

    .topbar {
      display: flex;
      justify-content: flex-end;
      align-items: center;
      gap: 12px;
    }

    .color-selector {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 4px 10px;
      border: 1px solid var(--border-cyan);
      border-radius: 20px;
      background: rgba(1, 15, 30, 0.8);
      height: 36px;
    }

    .color-dot {
      width: 16px; height: 16px; border-radius: 50%; cursor: pointer; transition: 0.2s;
    }
    .color-dot:hover { transform: scale(1.25); }
    .color-dot.active { outline: 2px solid #fff; outline-offset: 1px; }
    .cyan { background: #00e5ff; }
    .purple { background: #d000ff; }
    .green { background: #00ff88; }
    .red { background: #ff2a4b; }

    .btn-top {
      width: 36px; height: 36px; border-radius: 50%;
      background: #ffffff; color: #000;
      border: none; display: flex; align-items: center; justify-content: center;
      font-family: inherit; font-weight: 700; font-size: 11px;
      cursor: pointer; transition: 0.3s;
    }
    .btn-top:hover { transform: translateY(-3px); box-shadow: 0 0 15px rgba(255,255,255,0.8); }
    .btn-top svg { width: 16px; height: 16px; stroke-width: 2.5; }

    .main-grid {
      display: grid;
      grid-template-columns: 1.15fr 0.85fr;
      gap: 24px;
      align-items: stretch;
    }

    .hero-card {
      background: var(--card-bg);
      border: 1px solid var(--border-cyan);
      border-radius: 16px;
      padding: 40px;
      position: relative;
      display: flex;
      align-items: center;
      box-shadow: inset 0 0 20px rgba(0, 229, 255, 0.05), 0 0 20px rgba(0, 229, 255, 0.15);
      overflow: hidden;
    }

    .hero-content {
      display: flex;
      align-items: center;
      width: 100%;
      gap: 30px;
    }

    .globe-box {
      width: 200px;
      height: 200px;
      position: relative;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .globe {
      width: 140px;
      height: 140px;
      border-radius: 50%;
      background: radial-gradient(circle, var(--cyan-glow) 0%, rgba(0,229,255,0.02) 70%);
      border: 1px dashed var(--cyan);
      box-shadow: inset 0 0 20px var(--cyan-glow), 0 0 25px var(--cyan-glow);
      position: relative;
      animation: globePulse 4s ease-in-out infinite alternate;
    }

    @keyframes globePulse {
      0% { transform: scale(0.98); box-shadow: inset 0 0 15px var(--cyan-glow), 0 0 15px var(--cyan-glow); }
      100% { transform: scale(1.03); box-shadow: inset 0 0 25px var(--cyan-glow), 0 0 35px var(--cyan-glow); }
    }

    .globe-ring {
      position: absolute;
      border: 1px solid var(--cyan);
      border-radius: 50%;
      opacity: 0.75;
    }

    .ring-1 { width: 180px; height: 60px; animation: spinRing1 9s linear infinite; }
    .ring-2 { width: 180px; height: 60px; animation: spinRing2 12s linear infinite; }
    .ring-3 { width: 180px; height: 60px; animation: spinRing3 15s linear infinite; }

    @keyframes spinRing1 { from { transform: rotate(-25deg) rotateY(0deg); } to { transform: rotate(-25deg) rotateY(360deg); } }
    @keyframes spinRing2 { from { transform: rotate(25deg) rotateX(0deg); } to { transform: rotate(25deg) rotateX(360deg); } }
    @keyframes spinRing3 { from { transform: rotate(85deg) rotateZ(0deg); } to { transform: rotate(85deg) rotateZ(360deg); } }

    .brand-info { display: flex; flex-direction: column; }

    .brand-title {
      font-size: 52px;
      font-weight: 900;
      color: #fff;
      letter-spacing: 4px;
      line-height: 1;
      text-shadow: 0 0 15px var(--cyan-glow);
    }

    .brand-subtitle {
      font-size: 13px;
      letter-spacing: 6px;
      color: var(--cyan);
      margin-top: 6px;
      font-weight: 600;
    }

    .brand-desc {
      margin-top: 35px;
      font-size: 13px;
      color: #b0d8e6;
      line-height: 1.6;
    }

    .brand-desc span { color: var(--cyan); font-weight: 700; text-shadow: 0 0 8px var(--cyan); }

    .cyan-line {
      width: 60px;
      height: 3px;
      background: var(--cyan);
      margin-top: 15px;
      box-shadow: 0 0 10px var(--cyan);
    }

    .login-card {
      background: var(--card-bg);
      border: 1px solid var(--border-cyan);
      border-radius: 16px;
      padding: 35px;
      box-shadow: inset 0 0 20px rgba(0, 229, 255, 0.05), 0 0 20px rgba(0, 229, 255, 0.15);
      position: relative;
      animation: fadeInUp 0.8s ease-out forwards;
    }

    @keyframes fadeInUp {
      from { opacity: 0; transform: translateY(20px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .login-card::before {
      content: '';
      position: absolute;
      top: -1px; left: -1px;
      width: 20px; height: 20px;
      border-top: 3px solid var(--cyan);
      border-left: 3px solid var(--cyan);
      border-top-left-radius: 16px;
      box-shadow: -2px -2px 10px var(--cyan);
    }

    .login-tag { font-size: 11px; letter-spacing: 3px; color: var(--text-muted); margin-bottom: 4px; }
    .login-title { font-size: 24px; font-weight: 800; color: #fff; letter-spacing: 2px; }
    .login-subtitle { font-size: 10px; letter-spacing: 4px; color: var(--cyan); margin-top: 4px; margin-bottom: 30px; }

    .form-group { margin-bottom: 20px; }

    .label-title {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 11px;
      letter-spacing: 1.5px;
      color: #fff;
      margin-bottom: 8px;
      font-weight: 600;
    }

    .label-title svg { width: 14px; height: 14px; color: var(--cyan); }

    .input-wrapper {
      position: relative;
      display: flex;
      align-items: center;
      background: rgba(0, 18, 36, 0.7);
      border: 1px solid rgba(0, 229, 255, 0.3);
      border-radius: 8px;
      height: 42px;
      padding: 0 12px;
      transition: all 0.3s ease;
    }

    .input-wrapper:focus-within {
      border-color: var(--cyan);
      box-shadow: 0 0 12px var(--cyan-glow);
      transform: translateY(-1px);
    }

    .input-wrapper svg { width: 16px; height: 16px; color: var(--cyan); flex-shrink: 0; }

    .input-wrapper input {
      width: 100%;
      background: transparent;
      border: none;
      outline: none;
      color: #fff;
      font-family: inherit;
      font-size: 12px;
      padding: 0 10px;
    }

    .input-wrapper input::placeholder { color: #3a687c; }

    .icon-btn {
      background: transparent; border: none; cursor: pointer; color: var(--cyan); display: flex; transition: 0.2s;
    }
    .icon-btn:hover { transform: scale(1.15); }

    .btn-submit {
      width: 100%;
      height: 44px;
      background: linear-gradient(90deg, #0088ff, var(--cyan));
      border: none;
      border-radius: 10px;
      color: #fff;
      font-family: inherit;
      font-weight: 700;
      font-size: 13px;
      letter-spacing: 2px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      margin-top: 25px;
      box-shadow: 0 0 15px var(--cyan-glow);
      transition: 0.3s;
    }

    .btn-submit:hover {
      transform: translateY(-2px);
      box-shadow: 0 0 25px var(--cyan);
    }

    .btn-register {
      width: 100%;
      height: 40px;
      background: rgba(0, 25, 45, 0.5);
      border: 1px solid rgba(0, 229, 255, 0.4);
      border-radius: 8px;
      color: var(--cyan);
      font-family: inherit;
      font-weight: 700;
      font-size: 11px;
      letter-spacing: 2px;
      cursor: pointer;
      margin-top: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      text-decoration: none;
      transition: 0.3s;
    }

    .btn-register:hover {
      background: rgba(0, 229, 255, 0.1);
      box-shadow: 0 0 15px var(--cyan-glow);
    }

    .status-bar {
      background: var(--card-bg);
      border: 1px solid var(--border-cyan);
      border-radius: 30px;
      padding: 10px 24px;
      display: flex;
      align-items: center;
      gap: 30px;
      width: fit-content;
      box-shadow: 0 0 15px rgba(0, 229, 255, 0.1);
    }

    .status-node {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 12px;
      font-weight: 800;
      color: #fff;
      letter-spacing: 1.5px;
    }

    .dot-live {
      width: 8px; height: 8px; border-radius: 50%; background: var(--cyan); box-shadow: 0 0 8px var(--cyan);
      animation: blink 1.5s ease-in-out infinite;
    }

    @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }

    .status-metric {
      display: flex;
      flex-direction: column;
      font-size: 9px;
      color: var(--text-muted);
      letter-spacing: 1px;
    }

    .status-metric span {
      color: var(--cyan);
      font-weight: 700;
      font-size: 10px;
      margin-top: 2px;
    }

    .spin { animation: spin 1s linear infinite; }
    @keyframes spin { 100% { transform: rotate(360deg); } }

    @media (max-width: 900px) {
      .main-grid { grid-template-columns: 1fr; }
      .hero-card { display: none; }
      .status-bar { width: 100%; justify-content: space-between; }
    }
  </style>
</head>
<body>

  <div class="particle" style="left: 10%; top: 90%; animation-duration: 8s;"></div>
  <div class="particle" style="left: 30%; top: 80%; animation-duration: 6s;"></div>
  <div class="particle" style="left: 55%; top: 85%; animation-duration: 10s;"></div>
  <div class="particle" style="left: 75%; top: 90%; animation-duration: 7s;"></div>
  <div class="particle" style="left: 90%; top: 95%; animation-duration: 9s;"></div>

  <div class="container">

    <!-- TOPBAR -->
    <div class="topbar">
      <div class="color-selector">
        <div class="color-dot cyan active" data-color="#00e5ff" data-glow="rgba(0, 229, 255, 0.4)"></div>
        <div class="color-dot purple" data-color="#d000ff" data-glow="rgba(208, 0, 255, 0.4)"></div>
        <div class="color-dot green" data-color="#00ff88" data-glow="rgba(0, 255, 136, 0.4)"></div>
        <div class="color-dot red" data-color="#ff2a4b" data-glow="rgba(255, 42, 75, 0.4)"></div>
      </div>
      <button class="btn-top" id="langBtn">ES</button>
      <button class="btn-top" id="soundBtn"><i data-lucide="volume-2"></i></button>
      <button class="btn-top" id="settingsBtn"><i data-lucide="settings"></i></button>
    </div>

    <!-- MAIN GRID -->
    <div class="main-grid">

      <!-- HERO CARD -->
      <div class="hero-card">
        <div class="hero-content">
          <div class="globe-box">
            <div class="globe"></div>
            <div class="globe-ring ring-1"></div>
            <div class="globe-ring ring-2"></div>
            <div class="globe-ring ring-3"></div>
          </div>
          <div class="brand-info">
            <h1 class="brand-title">CCG</h1>
            <div class="brand-subtitle">SISTEMA GLOBAL</div>
            <p class="brand-desc">
              Conectamos talento,<br>
              creamos <span>oportunidades</span>
            </p>
            <div class="cyan-line"></div>
          </div>
        </div>
      </div>

      <!-- LOGIN CARD -->
      <div class="login-card">
        <div class="login-tag">LOGIN</div>
        <h2 class="login-title">PLATAFORMA CCG</h2>
        <div class="login-subtitle">SISTEMA GLOBAL</div>

        <form action="{{ route('login') }}" method="POST" id="loginForm">
          <input type="hidden" name="_token" value="{{ csrf_token() }}">

          <!-- USUARIO -->
          <div class="form-group">
            <div class="label-title">
              <i data-lucide="user"></i> USUARIO (E-MAIL)
            </div>
            <div class="input-wrapper">
              <i data-lucide="mail"></i>
              <input type="email" name="email" value="usuario@ccgrupo.com.co" placeholder="usuario@ccgrupo.com.co" required />
              <button type="button" class="icon-btn"><i data-lucide="user-plus"></i></button>
            </div>
          </div>

          <!-- CONTRASEÑA -->
          <div class="form-group">
            <div class="label-title">
              <i data-lucide="lock"></i> CONTRASEÑA
            </div>
            <div class="input-wrapper">
              <i data-lucide="lock"></i>
              <input type="password" id="passInput" name="password" placeholder="••••••••" required />
              <button type="button" class="icon-btn" id="togglePass"><i data-lucide="eye"></i></button>
            </div>
          </div>

          <!-- BOTÓN ACCEDER -->
          <button type="submit" class="btn-submit" id="submitBtn">
            <i data-lucide="arrow-right-circle" id="btnIcon"></i>
            <span id="btnText">ACCEDER</span>
          </button>

          <!-- BOTÓN REGÍSTRATE -->
          <a href="{{ route('register') ?? '/register' }}" class="btn-register">
            REGÍSTRATE
          </a>
        </form>
      </div>

    </div>

    <!-- STATUS BAR -->
    <div class="status-bar">
      <div class="status-node">
        <div class="dot-live"></div>
        NODO GLOBAL CCG
      </div>
      <div class="status-metric">
        LATENCIA
        <span id="val-latency">9ms</span>
      </div>
      <div class="status-metric">
        CPU
        <span id="val-cpu">11%</span>
      </div>
      <div class="status-metric">
        SEC
        <span>256-bit</span>
      </div>
    </div>

  </div>

  <script>
    lucide.createIcons();

    document.addEventListener("click", (e) => {
      const ripple = document.createElement("div");
      ripple.className = "ripple";
      ripple.style.left = e.clientX + "px";
      ripple.style.top = e.clientY + "px";
      document.body.appendChild(ripple);
      setTimeout(() => ripple.remove(), 600);
    });

    const passInput = document.getElementById("passInput");
    const togglePass = document.getElementById("togglePass");

    togglePass.addEventListener("click", () => {
      const isPass = passInput.type === "password";
      passInput.type = isPass ? "text" : "password";
      togglePass.innerHTML = isPass ? '<i data-lucide="eye-off"></i>' : '<i data-lucide="eye"></i>';
      lucide.createIcons();
    });

    document.querySelectorAll(".color-dot").forEach(dot => {
      dot.addEventListener("click", () => {
        document.querySelectorAll(".color-dot").forEach(d => d.classList.remove("active"));
        dot.classList.add("active");

        const color = dot.dataset.color;
        const glow = dot.dataset.glow;

        document.documentElement.style.setProperty("--cyan", color);
        document.documentElement.style.setProperty("--cyan-glow", glow);
        document.documentElement.style.setProperty("--border-cyan", color);
      });
    });

    const loginForm = document.getElementById("loginForm");
    const submitBtn = document.getElementById("submitBtn");
    const btnText = document.getElementById("btnText");
    const btnIcon = document.getElementById("btnIcon");

    loginForm.addEventListener("submit", () => {
      submitBtn.style.pointerEvents = "none";
      btnText.innerText = "CONECTANDO...";
      btnIcon.setAttribute("data-lucide", "loader-2");
      btnIcon.classList.add("spin");
      lucide.createIcons();
    });

    setInterval(() => {
      document.getElementById("val-latency").innerText = (8 + Math.floor(Math.random() * 5)) + "ms";
      document.getElementById("val-cpu").innerText = (10 + Math.floor(Math.random() * 8)) + "%";
    }, 3000);
  </script>
</body>
</html>