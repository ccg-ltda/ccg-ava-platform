<?php

namespace App\Audit;

use App\Audit\Pdf\SimplePdf;

/**
 * Lays out the "Reporte de Auditoría": a header band in the Workspace brand color, the report data (Workspace, period,
 * applied filters, who generated it and when), the totals, and then each event with only its relevant changes as a
 * Campo / Antes / Después table. It receives events already presented (AuditPresenter), so it can never show more than
 * the page does.
 */
class AuditPdf
{
    private const MARGIN = 40;

    private const INK = [15, 23, 42];

    private const MUTED = [100, 116, 139];

    private const LINE = [226, 232, 240];

    private const SOFT = [241, 245, 249];

    private const ACTIONS = ['created' => [21, 128, 61], 'updated' => [180, 83, 9], 'deleted' => [185, 28, 28]];

    private SimplePdf $pdf;

    private float $y = 0;

    /**
     * @param  iterable<array<string, mixed>>  $events
     * @param  array{workspace: string, period: string, filters: list<string>, generatedBy: string, generatedAt: string, included: int, matching: int, summary: array<string, int>, brand: array{0: int, 1: int, 2: int}}  $report
     */
    public function render(iterable $events, array $report): string
    {
        $this->pdf = new SimplePdf('Reporte de Auditoría');
        $this->pdf->addPage();
        $this->pdf->footer(function (SimplePdf $pdf, int $page, int $pages) use ($report) {
            $pdf->rect(self::MARGIN, SimplePdf::HEIGHT - 36, SimplePdf::WIDTH - 2 * self::MARGIN, 0.6, self::LINE);
            $pdf->text(self::MARGIN, SimplePdf::HEIGHT - 28, "Reporte de Auditoría · {$report['workspace']}", 8, false, self::MUTED);
            $pdf->textRight(SimplePdf::WIDTH - self::MARGIN, SimplePdf::HEIGHT - 28, "Página {$page} de {$pages}", 8, false, self::MUTED);
        });

        $this->header($report);
        $this->summary($report);

        $this->heading('Eventos');

        $count = 0;

        foreach ($events as $event) {
            $this->event($event);
            $count++;
        }

        if ($count === 0) {
            $this->pdf->text(self::MARGIN, $this->y, 'No hay eventos que coincidan con los filtros.', 10, false, self::MUTED);
        }

        return $this->pdf->output();
    }

    private function header(array $report): void
    {
        $width = SimplePdf::WIDTH;
        $this->pdf->rect(0, 0, $width, 84, $report['brand']);
        $this->pdf->text(self::MARGIN, 22, 'Reporte de Auditoría', 22, true, [255, 255, 255]);
        $this->pdf->text(self::MARGIN, 52, $report['workspace'], 11, false, [255, 255, 255]);

        $this->y = 104;
        $rows = [
            ['Workspace', $report['workspace']],
            ['Período', $report['period']],
            ['Filtros aplicados', $report['filters'] === [] ? 'Ninguno' : implode(' · ', $report['filters'])],
            ['Generado por', $report['generatedBy']],
            ['Generado el', $report['generatedAt']],
            ['Registros incluidos', $report['included'] === $report['matching']
                ? (string) $report['included']
                : "{$report['included']} de {$report['matching']} (se incluyen los más recientes)"],
        ];

        foreach ($rows as [$label, $value]) {
            $lines = $this->pdf->wrap($value, $width - 2 * self::MARGIN - 120, 10);
            $this->pdf->text(self::MARGIN, $this->y, $label, 10, true, self::MUTED);

            foreach ($lines as $line) {
                $this->pdf->text(self::MARGIN + 120, $this->y, $line, 10);
                $this->y += 15;
            }
        }

        $this->y += 8;
    }

    private function summary(array $report): void
    {
        $boxes = [
            ['Total', $report['summary']['total'], self::INK],
            ['Creados', $report['summary']['created'], self::ACTIONS['created']],
            ['Modificados', $report['summary']['updated'], self::ACTIONS['updated']],
            ['Eliminados', $report['summary']['deleted'], self::ACTIONS['deleted']],
        ];
        $gap = 10;
        $width = (SimplePdf::WIDTH - 2 * self::MARGIN - 3 * $gap) / 4;

        foreach ($boxes as $index => [$label, $value, $color]) {
            $x = self::MARGIN + $index * ($width + $gap);
            $this->pdf->rect($x, $this->y, $width, 46, self::SOFT);
            $this->pdf->rect($x, $this->y, 3, 46, $color);
            $this->pdf->text($x + 12, $this->y + 8, mb_strtoupper($label), 8, true, self::MUTED);
            $this->pdf->text($x + 12, $this->y + 22, (string) $value, 18, true, $color);
        }

        $this->y += 66;
    }

    private function heading(string $title): void
    {
        $this->ensure(40);
        $this->pdf->text(self::MARGIN, $this->y, mb_strtoupper($title), 10, true, self::MUTED);
        $this->y += 18;
    }

    /** @param  array<string, mixed>  $event */
    private function event(array $event): void
    {
        $width = SimplePdf::WIDTH - 2 * self::MARGIN;
        $color = self::ACTIONS[$event['action']] ?? self::INK;

        // An event that fits on one page is never split; a longer one keeps its header with its first change.
        $layout = $this->layout($event);
        $height = 86 + ($layout['rows'] === [] ? 0 : 16 + array_sum(array_column($layout['rows'], 'height')));
        $this->ensure($height <= SimplePdf::HEIGHT - 60 - self::MARGIN ? $height : 116);

        $this->pdf->rect(self::MARGIN, $this->y, $width, 0.6, self::LINE);
        $this->y += 10;

        $badge = mb_strtoupper($event['actionLabel']);
        $badgeWidth = $this->pdf->width($badge, 8, true) + 14;
        $this->pdf->rect(self::MARGIN, $this->y, $badgeWidth, 15, $color);
        $this->pdf->text(self::MARGIN + 7, $this->y + 3.5, $badge, 8, true, [255, 255, 255]);
        $this->pdf->textRight(self::MARGIN + $width, $this->y + 2, "{$event['date']} · {$event['time']}", 10, true);
        $this->y += 22;

        $this->pdf->text(self::MARGIN, $this->y, $event['user']['name'], 10.5, true);
        $this->pdf->text(self::MARGIN + $this->pdf->width($event['user']['name'], 10.5, true) + 6, $this->y + 0.5, "· {$event['user']['email']}", 9, false, self::MUTED);
        $this->y += 15;

        $this->pdf->text(self::MARGIN, $this->y, "{$event['module']}  >  {$event['record']}", 10);
        $this->y += 14;

        $this->pdf->text(self::MARGIN, $this->y, "Workspace: {$event['workspace']['name']}   ·   IP: ".($event['ip'] ?? 'no disponible'), 9, false, self::MUTED);
        $this->y += 16;

        $this->changes($layout);
        $this->y += 8;
    }

    /**
     * Columns, titles and the wrapped rows of an event's changes (measured once, used to place and to draw).
     *
     * @param  array<string, mixed>  $event
     * @return array{columns: list<float|int>, titles: list<string>, rows: list<array{cells: list<list<string>>, height: int}>}
     */
    private function layout(array $event): array
    {
        $total = SimplePdf::WIDTH - 2 * self::MARGIN;
        $updated = $event['action'] === 'updated';
        $columns = $updated ? [120, ($total - 120) / 2, ($total - 120) / 2] : [120, $total - 120];
        $titles = $updated ? ['Campo', 'Antes', 'Después'] : ['Campo', $event['action'] === 'created' ? 'Valor' : 'Valor anterior'];
        $side = $event['action'] === 'created' ? 'after' : 'before';
        $rows = [];

        foreach ($event['changes'] as $change) {
            $cells = $updated
                ? [$change['field'], $this->shown($change, 'before'), $this->shown($change, 'after')]
                : [$change['field'], $this->shown($change, $side)];
            $wrapped = array_map(fn ($text, $i) => $this->pdf->wrap($text, $columns[$i] - 12, 9, $i === 0), $cells, array_keys($cells));

            $rows[] = ['cells' => $wrapped, 'height' => max(array_map('count', $wrapped)) * 12 + 8];
        }

        return ['columns' => $columns, 'titles' => $titles, 'rows' => $rows];
    }

    /** @param  array{columns: list<float|int>, titles: list<string>, rows: list<array{cells: list<list<string>>, height: int}>}  $layout */
    private function changes(array $layout): void
    {
        $total = SimplePdf::WIDTH - 2 * self::MARGIN;

        if ($layout['rows'] === []) {
            return;
        }

        $this->tableHeader($layout['titles'], $layout['columns']);

        foreach ($layout['rows'] as $row) {
            if ($this->y + $row['height'] > SimplePdf::HEIGHT - 60) {
                $this->newPage();
                $this->tableHeader($layout['titles'], $layout['columns']);
            }

            $offset = self::MARGIN;

            foreach ($row['cells'] as $i => $lines) {
                foreach ($lines as $line => $text) {
                    $muted = $i > 0 && ($text === AuditRecorder::HIDDEN || $text === '(vacío)');
                    $this->pdf->text($offset + 6, $this->y + 4 + $line * 12, $text, 9, $i === 0, $muted ? self::MUTED : self::INK);
                }

                $offset += $layout['columns'][$i];
            }

            $this->y += $row['height'];
            $this->pdf->rect(self::MARGIN, $this->y, $total, 0.4, self::LINE);
        }
    }

    /** @param  list<string>  $titles @param  list<float|int>  $columns */
    private function tableHeader(array $titles, array $columns): void
    {
        $this->pdf->rect(self::MARGIN, $this->y, SimplePdf::WIDTH - 2 * self::MARGIN, 16, self::SOFT);
        $offset = self::MARGIN;

        foreach ($titles as $i => $title) {
            $this->pdf->text($offset + 6, $this->y + 4, mb_strtoupper($title), 7.5, true, self::MUTED);
            $offset += $columns[$i];
        }

        $this->y += 16;
    }

    /** @param  array{before: ?string, after: ?string}  $change */
    private function shown(array $change, string $side): string
    {
        $value = $change[$side];

        return $value !== null && $value !== '' ? $value : '(vacío)';
    }

    private function ensure(float $height): void
    {
        if ($this->y + $height > SimplePdf::HEIGHT - 60) {
            $this->newPage();
        }
    }

    private function newPage(): void
    {
        $this->pdf->addPage();
        $this->y = self::MARGIN;
    }
}
