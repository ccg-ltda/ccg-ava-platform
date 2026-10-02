@extends('layouts.access')

@section('title', 'CCG Platform - Workspace')

@section('content')
    <form id="workspaceForm" method="POST" action="{{ route('pre-login') }}" novalidate>
        @csrf
        <div class="input-group">
            <label for="workspace_code">Workspace code</label>
            <input type="text" id="workspace_code" name="workspace_code" value="{{ old('workspace_code') }}"
                   placeholder="DESARROLLO_DEV" autocomplete="off" autocapitalize="characters" spellcheck="false"
                   maxlength="100" autofocus data-connect
                   class="{{ $errors->has('workspace_code') ? 'error' : '' }}" />
            <div class="error-message {{ $errors->has('workspace_code') ? 'show' : '' }}" id="workspaceError">{{ $errors->first('workspace_code') ?: 'Ingrese el código de su Workspace' }}</div>
        </div>

        <button type="submit" class="login-btn">Continuar</button>
    </form>
@endsection

@push('scripts')
<script>
    (function() {
        var form = document.getElementById('workspaceForm');
        var input = document.getElementById('workspace_code');
        var error = document.getElementById('workspaceError');

        input.addEventListener('input', function() {
            input.classList.remove('error');
            error.classList.remove('show');
            window.CCG.updateConnections();
        });

        form.addEventListener('submit', function(e) {
            if (input.value.trim() === '') {
                e.preventDefault();
                error.textContent = 'Ingrese el código de su Workspace';
                input.classList.add('error');
                error.classList.add('show');
                window.CCG.showToast('Por favor, corrija los errores del formulario', 'error');
                return;
            }
            window.CCG.showToast('Verificando Workspace...', 'success');
        });
    })();
</script>
@endpush
