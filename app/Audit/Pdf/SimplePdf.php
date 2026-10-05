<?php

namespace App\Audit\Pdf;

use Closure;

/**
 * A small PDF writer for text reports: A4 pages, the standard Helvetica fonts (no embedded fonts, so nothing to
 * install), filled rectangles, measured text and word wrapping. It exists because the project has no PDF library and
 * dependencies may not be added. Coordinates are in points, measured from the TOP-LEFT corner of the page.
 *
 * Text is written in WinAnsi (Windows-1252), which covers Spanish; characters outside it are replaced.
 */
class SimplePdf
{
    public const WIDTH = 595.28;

    public const HEIGHT = 841.89;

    /** Helvetica advance widths (1/1000 em) for ASCII 32..126, regular and bold. */
    private const REGULAR = [278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584];

    private const BOLD = [278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611, 975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556, 333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584];

    private const BASE_LETTERS = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N'];

    /** @var list<string> content stream of each page */
    private array $pages = [];

    /** Page the next drawing goes to (null = the last one). */
    private ?int $target = null;

    private ?Closure $footer = null;

    public function __construct(private readonly string $title) {}

    public function addPage(): void
    {
        $this->pages[] = '';
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    /** Called once per page when the document is written, with the PDF, the page number and the total. */
    public function footer(Closure $callback): void
    {
        $this->footer = $callback;
    }

    /** @param  array{0: int, 1: int, 2: int}  $color */
    public function rect(float $x, float $y, float $width, float $height, array $color): void
    {
        $this->draw(sprintf('%s rg %.2f %.2f %.2f %.2f re f', $this->rgb($color), $x, self::HEIGHT - $y - $height, $width, $height));
    }

    /** @param  array{0: int, 1: int, 2: int}  $color  `$y` is the TOP of the text line */
    public function text(float $x, float $y, string $text, float $size = 10, bool $bold = false, array $color = [15, 23, 42]): void
    {
        if ($text === '') {
            return;
        }

        $baseline = self::HEIGHT - $y - $size * 0.8;
        $this->draw(sprintf('BT /%s %.2f Tf %s rg %.2f %.2f Td (%s) Tj ET', $bold ? 'F2' : 'F1', $size, $this->rgb($color), $x, $baseline, $this->escape($text)));
    }

    /** Text with its right edge at `$right`. */
    public function textRight(float $right, float $y, string $text, float $size = 10, bool $bold = false, array $color = [15, 23, 42]): void
    {
        $this->text($right - $this->width($text, $size, $bold), $y, $text, $size, $bold, $color);
    }

    public function width(string $text, float $size, bool $bold = false): float
    {
        $table = $bold ? self::BOLD : self::REGULAR;
        $total = 0;

        foreach (mb_str_split($text) as $char) {
            $char = self::BASE_LETTERS[$char] ?? $char;
            $code = strlen($char) === 1 ? ord($char) : 0;
            $total += ($code >= 32 && $code <= 126) ? $table[$code - 32] : 556;
        }

        return $total * $size / 1000;
    }

    /**
     * Breaks text into lines no wider than `$width` (long words are cut).
     *
     * @return list<string>
     */
    public function wrap(string $text, float $width, float $size = 10, bool $bold = false): array
    {
        $lines = [];

        foreach (preg_split('/\R/u', $text) as $paragraph) {
            $line = '';

            foreach (preg_split('/\s+/u', trim($paragraph), -1, PREG_SPLIT_NO_EMPTY) ?: [''] as $word) {
                foreach ($this->cut($word, $width, $size, $bold) as $piece) {
                    $candidate = $line === '' ? $piece : "{$line} {$piece}";

                    if ($line !== '' && $this->width($candidate, $size, $bold) > $width) {
                        $lines[] = $line;
                        $line = $piece;
                    } else {
                        $line = $candidate;
                    }
                }
            }

            $lines[] = $line;
        }

        return $lines;
    }

    public function output(): string
    {
        $total = count($this->pages);

        // Footers are drawn last, when the number of pages is known.
        if ($this->footer) {
            foreach (array_keys($this->pages) as $index) {
                $this->target = $index;
                ($this->footer)($this, $index + 1, $total);
            }

            $this->target = null;
        }

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids ['.implode(' ', array_map(fn ($i) => (5 + $i * 2).' 0 R', array_keys($this->pages))).'] /Count '.$total.' >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];

        foreach ($this->pages as $index => $stream) {
            $page = 5 + $index * 2;
            $data = function_exists('gzcompress') ? gzcompress($stream, 6) : $stream;
            $filter = function_exists('gzcompress') ? ' /Filter /FlateDecode' : '';
            $objects[$page] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', self::WIDTH, self::HEIGHT, $page + 1);
            $objects[$page + 1] = '<< /Length '.strlen($data)."{$filter} >>\nstream\n{$data}\nendstream";
        }

        $info = count($objects) + 1;
        $objects[$info] = '<< /Title ('.$this->escape($this->title).') /Producer (Ava) >>';

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".($info + 1)."\n0000000000 65535 f \n";

        for ($i = 1; $i <= $info; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        return $pdf."trailer\n<< /Size ".($info + 1)." /Root 1 0 R /Info {$info} 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    private function draw(string $operator): void
    {
        if ($this->pages === []) {
            $this->addPage();
        }

        $this->pages[$this->target ?? array_key_last($this->pages)] .= $operator."\n";
    }

    /** @param  array{0: int, 1: int, 2: int}  $color */
    private function rgb(array $color): string
    {
        return sprintf('%.3f %.3f %.3f', $color[0] / 255, $color[1] / 255, $color[2] / 255);
    }

    private function escape(string $text): string
    {
        $encoded = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        $encoded = $encoded === false ? preg_replace('/[^\x20-\x7E]/', '?', $text) : $encoded;

        return str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], $encoded);
    }

    /** @return list<string> */
    private function cut(string $word, float $width, float $size, bool $bold): array
    {
        if ($this->width($word, $size, $bold) <= $width) {
            return [$word];
        }

        $pieces = [];
        $piece = '';

        foreach (mb_str_split($word) as $char) {
            if ($piece !== '' && $this->width($piece.$char, $size, $bold) > $width) {
                $pieces[] = $piece;
                $piece = '';
            }

            $piece .= $char;
        }

        return [...$pieces, $piece];
    }
}
