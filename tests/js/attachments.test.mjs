// Run with `npm run test:js`. The client-side checks of the files attached to a support message; the server repeats them on the real content.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { addFiles, formatSize } from '../../resources/js/lib/attachments.js';

const limits = { maxFiles: 3, maxMb: { image: 8, video: 40, pdf: 15 }, maxTotalMb: 45 };
const file = (name, type, mb = 1) => ({ name, type, size: Math.round(mb * 1024 * 1024) });

test('allowed images, videos and PDF are kept', () => {
    const { files, errors } = addFiles([], [file('a.png', 'image/png'), file('b.mp4', 'video/mp4', 20), file('c.pdf', 'application/pdf', 2)], limits);

    assert.equal(files.length, 3);
    assert.deepEqual(errors, []);
});

test('other formats, empty files and files over their limit are refused with a reason', () => {
    const { files, errors } = addFiles([], [file('x.exe', 'application/x-msdownload'), file('vacio.png', 'image/png', 0), file('grande.png', 'image/png', 9), file('video.mp4', 'video/mp4', 41), file('ok.pdf', 'application/pdf')], limits);

    assert.deepEqual(files.map((f) => f.name), ['ok.pdf']);
    assert.equal(errors.length, 4);
    assert.match(errors[2], /pesa más de 8 MB/);
});

test('the number and the total size per message are limited, keeping what was already chosen', () => {
    const first = addFiles([], [file('1.png', 'image/png'), file('2.png', 'image/png'), file('3.png', 'image/png')], limits);
    const more = addFiles(first.files, [file('4.png', 'image/png')], limits);

    assert.equal(more.files.length, 3);
    assert.match(more.errors[0], /hasta 3 archivos/);

    const big = addFiles([file('v1.mp4', 'video/mp4', 30)], [file('v2.mp4', 'video/mp4', 20)], limits);
    assert.equal(big.files.length, 1);
    assert.match(big.errors[0], /45 MB en total/);
});

test('sizes read naturally', () => {
    assert.equal(formatSize(500), '500 B');
    assert.equal(formatSize(2048), '2 KB');
    assert.equal(formatSize(1.5 * 1024 * 1024), '1.5 MB');
    assert.equal(formatSize(40 * 1024 * 1024), '40 MB');
});
