@extends('layouts.access')

@section('title', 'CCG Platform - Login')

@section('content')
    <div class="workspace-badge">
        <span>Workspace <strong>{{ $workspace->code }}</strong></span>
        <button type="submit" form="workspaceResetForm">Cambiar</button>
    </div>
    <form id="workspaceResetForm" method="POST" action="{{ route('pre-login.reset') }}">
        @csrf
        @method('DELETE')
    </form>

    <form id="loginForm" method="POST" action="{{ route('login') }}" novalidate>
        @csrf
        <div class="input-group">
            <label for="email">Correo Electrónico</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" placeholder="usuario@ccg.platform" autocomplete="email" data-connect
                   class="{{ $errors->has('email') ? 'error' : '' }}" />
            <div class="error-message {{ $errors->has('email') ? 'show' : '' }}" id="emailError">{{ $errors->first('email') ?: 'Ingrese un correo electrónico válido' }}</div>
        </div>

        <div class="input-group">
            <label for="password">Contraseña</label>
            <div class="password-wrapper">
                <input type="password" id="password" name="password" placeholder="••••••••" autocomplete="current-password" data-connect
                       class="{{ $errors->has('password') ? 'error' : '' }}" />
                <button type="button" class="password-toggle" id="passwordToggle" aria-label="Toggle password visibility">
                    <svg id="eyeOpen" viewBox="0 0 24 24">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                    <svg id="eyeClosed" viewBox="0 0 24 24" style="display:none">
                        <path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/>
                        <path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/>
                        <path d="M14.12 14.12a3 3 0 11-4.24-4.24"/>
                        <line x1="1" y1="1" x2="23" y2="23"/>
                    </svg>
                </button>
            </div>
            <div class="error-message {{ $errors->has('password') ? 'show' : '' }}" id="passwordError">{{ $errors->first('password') ?: 'Ingrese su contraseña' }}</div>
        </div>

        <div class="remember-row">
            <label>
                <input type="checkbox" id="rememberMe" name="remember" value="1" />
                Recordar sesión
            </label>
            <a href="#" id="forgotLink">¿Olvidó su contraseña?</a>
        </div>

        <button type="submit" class="login-btn">Iniciar Sesión</button>
    </form>

    <p class="footer-text">
        ¿No tiene cuenta? <a href="#" id="registerLink">Regístrese aquí</a>
    </p>
@endsection

@push('scripts')
<script>
    (function() {
        var form = document.getElementById('loginForm');
        var emailInput = document.getElementById('email');
        var passwordInput = document.getElementById('password');
        var emailError = document.getElementById('emailError');
        var passwordError = document.getElementById('passwordError');
        var emailDefault = 'Ingrese un correo electrónico válido';
        var passwordDefault = 'Ingrese su contraseña';

        function validateEmail(email) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        }

        function clearState(input, error) {
            input.classList.remove('error', 'success');
            error.classList.remove('show');
        }

        emailInput.addEventListener('input', function() {
            clearState(emailInput, emailError);
            emailError.textContent = emailDefault;
            if (emailInput.value.trim() !== '') {
                if (validateEmail(emailInput.value.trim())) {
                    emailInput.classList.add('success');
                } else {
                    emailInput.classList.add('error');
                    emailError.classList.add('show');
                }
            }
            window.CCG.updateConnections();
        });

        passwordInput.addEventListener('input', function() {
            clearState(passwordInput, passwordError);
            passwordError.textContent = passwordDefault;
            window.CCG.updateConnections();
        });

        document.getElementById('passwordToggle').addEventListener('click', function() {
            var isPassword = passwordInput.type === 'password';
            passwordInput.type = isPassword ? 'text' : 'password';
            document.getElementById('eyeOpen').style.display = isPassword ? 'none' : 'block';
            document.getElementById('eyeClosed').style.display = isPassword ? 'block' : 'none';
        });

        form.addEventListener('submit', function(e) {
            var valid = true;

            if (!validateEmail(emailInput.value.trim())) {
                emailError.textContent = emailDefault;
                emailInput.classList.add('error');
                emailError.classList.add('show');
                valid = false;
            }

            if (passwordInput.value.length === 0) {
                passwordError.textContent = passwordDefault;
                passwordInput.classList.add('error');
                passwordError.classList.add('show');
                valid = false;
            }

            if (!valid) {
                e.preventDefault();
                window.CCG.showToast('Por favor, corrija los errores del formulario', 'error');
                return;
            }

            window.CCG.showToast('Iniciando sesión...', 'success');
        });

        document.getElementById('forgotLink').addEventListener('click', function(e) {
            e.preventDefault();
            window.CCG.showToast('Funcionalidad de recuperación en desarrollo', 'warning');
        });

        window.CCG.updateConnections();
    })();
</script>
@endpush
