// Run with `npm run test:js`. The login form never tells whether an account exists: the server's ONE generic failure is shown under the password when the
// e-mail is well formed, and the e-mail only turns red for a malformed address.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

const login = readFileSync(fileURLToPath(new URL('../../resources/js/Pages/Auth/Login.jsx', import.meta.url)), 'utf8');

test('a failed sign-in is shown under the password and leaves a well-formed e-mail in its positive state', () => {
    assert.match(login, /const passwordMessage = client\.password \|\| \(serverMessage && emailFormatOk \? serverMessage : ''\)/);
    assert.match(login, /const emailMessage = client\.email \|\| \(serverMessage && !emailFormatOk \? serverMessage : ''\)/);
    assert.match(login, /valid=\{emailTyped && !emailMessage\}/, 'the e-mail keeps its valid state');
});

test('the form never states which credential was wrong and clears both states when a field changes', () => {
    assert.doesNotMatch(login, /contraseña es incorrecta|correo no existe|no está registrado/i);
    assert.equal((login.match(/clearErrors\('email', 'password'\)/g) ?? []).length, 2);
});
