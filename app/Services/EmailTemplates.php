<?php

namespace App\Services;

use App\Audit\AuditLogger;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Models\Workspace;
use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The ONE presentation shared by the platform's emails. The template is DATA, not code: plain HTML text with a few documented `{{placeholders}}` that are replaced
 * by string substitution. Nothing in it is ever compiled, included or executed (no Blade, no PHP), a placeholder that is not on the list is refused when it is
 * saved, and the HTML itself is checked against a WHITELIST of tags and attributes. The same check runs again every time a template is used, so a stored row that
 * is not valid (edited by hand in the database, or written by an older rule) never reaches an email: the default template that ships with the application is used.
 *
 * Every dynamic value is escaped for the HTML context it lands in. The message specific part (`content`, `action`) is built here from plain text and a validated
 * link, never from HTML that a person or a message supplies, so a reminder cannot inject markup or scripts.
 */
class EmailTemplates
{
    public const KEY = 'global';

    public const MAX_BYTES = 60000;

    /** @var array<string, string> placeholder => what it is (documented in the editor) */
    public const PLACEHOLDERS = [
        'brand' => 'Nombre visible de la plataforma (se escapa).',
        'title' => 'Título del mensaje (se escapa).',
        'content' => 'Contenido del mensaje: párrafos y, si lo hay, un código destacado. Obligatorio.',
        'action' => 'Botón de acción con su enlace, solo si el mensaje lo tiene (enlace http o https validado).',
        'footer' => 'Pie del mensaje (se escapa).',
        'support' => 'Línea de contacto de soporte, solo si está configurada (se escapa).',
    ];

    /** Tags the template may use: layout and text only. No script, style element, form, frame, image, link or object. */
    private const TAGS = ['html', 'head', 'meta', 'title', 'body', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'div', 'span', 'p', 'h1', 'h2', 'h3', 'h4', 'a', 'strong', 'b', 'em', 'i', 'u', 'br', 'hr', 'ul', 'ol', 'li', 'small', 'center'];

    private const ATTRIBUTES = ['style', 'width', 'height', 'align', 'valign', 'bgcolor', 'cellpadding', 'cellspacing', 'border', 'role', 'lang', 'dir', 'colspan', 'rowspan'];

    public function __construct(private readonly AuditLogger $audit) {}

    /** Whether the table that stores an edited template exists (the migration may not be applied yet: the default is used then). */
    public function ready(): bool
    {
        return Schema::hasTable('email_templates');
    }

    /** The template that ships with the application and is restored by «Restaurar». */
    public function defaultHtml(): string
    {
        return File::get(resource_path('email/default.html'));
    }

    /** The stored global template when it exists AND is valid; otherwise the default. */
    public function activeHtml(): string
    {
        return $this->stored() ?? $this->defaultHtml();
    }

    /** @return array{html: string, isDefault: bool, updatedAt: ?string, updatedBy: ?string, ready: bool} */
    public function current(): array
    {
        $row = $this->ready() ? EmailTemplate::with('author:id,name')->where('key', self::KEY)->first() : null;
        $valid = $row !== null && $this->validate($row->html) === [];

        return [
            'html' => $valid ? $row->html : $this->defaultHtml(),
            'isDefault' => ! $valid || $row->html === $this->defaultHtml(),
            'storedInvalid' => $row !== null && ! $valid,
            'updatedAt' => $valid ? $row->updated_at?->toIso8601String() : null,
            'updatedBy' => $valid ? $row->author?->name : null,
            'ready' => $this->ready(),
        ];
    }

    private function stored(): ?string
    {
        if (! $this->ready()) {
            return null;
        }

        $html = EmailTemplate::where('key', self::KEY)->value('html');

        return is_string($html) && $this->validate($html) === [] ? $html : null;
    }

    // --- administration ------------------------------------------------------------------------------------------------------

    /**
     * Validates and stores a template. Returns the list of problems (empty = saved). Audited with the author; the whole HTML is not copied into the audit log.
     *
     * @return list<string>
     */
    public function save(string $html, User $author): array
    {
        $errors = $this->validate($html);

        if ($errors !== [] || ! $this->ready()) {
            return $errors !== [] ? $errors : ['Falta aplicar la migración de la plantilla de correos: no se puede guardar todavía.'];
        }

        $row = EmailTemplate::firstOrNew(['key' => self::KEY]);
        $before = $row->exists ? mb_strlen($row->html).' caracteres' : 'Plantilla predeterminada';
        $row->fill(['html' => $html, 'updated_by' => $author->id])->save();

        $this->audit->recordForActor('updated', 'email_template', $row->id, 'Plantilla HTML para correos', [AuditLogger::change('Plantilla', $before, mb_strlen($html).' caracteres')], $this->adminWorkspace(), 'Plantilla de correos');

        return [];
    }

    /** Back to the template the application maintains: the stored row is replaced by the default (not just cleared). Audited. */
    public function restore(User $author): void
    {
        if (! $this->ready()) {
            return;
        }

        $row = EmailTemplate::firstOrNew(['key' => self::KEY]);
        $existed = $row->exists;
        $row->fill(['html' => $this->defaultHtml(), 'updated_by' => $author->id])->save();

        $this->audit->recordForActor($existed ? 'updated' : 'created', 'email_template', $row->id, 'Plantilla HTML para correos', [AuditLogger::change('Plantilla', $existed ? 'Personalizada' : null, 'Restaurada a la predeterminada')], $this->adminWorkspace(), 'Plantilla de correos');
    }

    private function adminWorkspace()
    {
        return Workspace::where('code', config('workspace.admin_code'))->firstOrFail();
    }

    // --- validation ----------------------------------------------------------------------------------------------------------

    /**
     * Every reason the template can not be used. Empty = valid. The rules are exact and the messages say what to change.
     *
     * @return list<string>
     */
    public function validate(string $html): array
    {
        $errors = [];

        if (trim($html) === '') {
            return ['La plantilla no puede estar vacía.'];
        }

        if (strlen($html) > self::MAX_BYTES) {
            return ['La plantilla es demasiado grande (máximo '.self::MAX_BYTES.' bytes).'];
        }

        if (! mb_check_encoding($html, 'UTF-8')) {
            return ['La plantilla debe estar en UTF-8.'];
        }

        // Anything that looks like server-side code or a template engine is refused outright: the template is text, it is never executed.
        foreach (['<?' => 'PHP', '{!!' => 'Blade', '@php' => 'Blade', '@include' => 'Blade', '@foreach' => 'Blade', '{%' => 'un motor de plantillas', '<!--' => 'comentarios HTML'] as $needle => $what) {
            if (stripos($html, $needle) !== false) {
                $errors[] = "La plantilla no puede contener {$what} («{$needle}»).";
            }
        }

        // Placeholders: only the documented ones, written {{nombre}}; any other double brace is an error (never interpreted).
        preg_match_all('/\{\{(.*?)\}\}/s', $html, $found);
        foreach ($found[1] as $raw) {
            if (! preg_match('/^[a-z_]+$/', trim($raw)) || ! array_key_exists(trim($raw), self::PLACEHOLDERS)) {
                $errors[] = 'Marcador no permitido: {{'.trim($raw).'}}. Los disponibles son: '.implode(', ', array_map(fn ($name) => '{{'.$name.'}}', array_keys(self::PLACEHOLDERS))).'.';
            }
        }

        if (preg_match('/\{\{|\}\}/', preg_replace('/\{\{[a-z_]+\}\}/', '', $html) ?? '')) {
            $errors[] = 'Hay llaves dobles sueltas o mal escritas: usa solo marcadores como {{content}}.';
        }

        if (! str_contains($html, '{{content}}')) {
            $errors[] = 'Falta el marcador {{content}}: sin él los mensajes saldrían sin su contenido.';
        }

        if ($errors !== []) {
            return array_values(array_unique($errors));
        }

        return array_values(array_unique($this->validateDom($html)));
    }

    /** @return list<string> */
    private function validateDom(string $html): array
    {
        $errors = [];
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument;

        try {
            $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        } catch (Throwable) {
            return ['El HTML no se pudo leer.'];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $this->walk($dom->documentElement, $errors);

        return $errors;
    }

    /** @param  list<string>  $errors */
    private function walk(?DOMNode $node, array &$errors): void
    {
        if ($node === null) {
            return;
        }

        if ($node instanceof DOMElement) {
            $tag = strtolower($node->tagName);

            if (! in_array($tag, self::TAGS, true)) {
                $errors[] = "La etiqueta <{$tag}> no está permitida.".($tag === 'style' ? ' Usa estilos en línea (atributo style).' : '');

                return;
            }

            foreach (iterator_to_array($node->attributes) as $attribute) {
                $name = strtolower($attribute->nodeName);
                $value = $attribute->nodeValue ?? '';

                if (str_contains($value, '{{')) {
                    $errors[] = "Los marcadores no pueden ir dentro de un atributo ({$name}): solo en el texto del documento.";

                    continue;
                }

                $allowed = in_array($name, self::ATTRIBUTES, true)
                    || ($tag === 'a' && in_array($name, ['href', 'title'], true))
                    || ($tag === 'meta' && in_array($name, ['charset', 'name', 'content'], true));

                if (! $allowed || str_starts_with($name, 'on')) {
                    $errors[] = "El atributo {$name} no está permitido en <{$tag}>.";

                    continue;
                }

                if ($name === 'style' && preg_match('/url\s*\(|@import|expression\s*\(|behavio[u]?r|javascript:|-moz-binding|position\s*:\s*fixed|\\\\/i', $value)) {
                    $errors[] = 'Un estilo en línea contiene algo no permitido (url(), @import, expression, javascript:, posición fija o barras invertidas).';
                }

                if ($tag === 'a' && $name === 'href' && ! $this->safeLiteralLink($value)) {
                    $errors[] = 'Un enlace usa un destino no permitido: solo http, https o mailto.';
                }

                if ($tag === 'meta' && $name === 'name' && strtolower($value) !== 'viewport') {
                    $errors[] = 'Solo se admite <meta name="viewport">.';
                }
            }

            if ($tag === 'meta' && $node->hasAttribute('http-equiv')) {
                $errors[] = 'No se admite http-equiv en <meta>.';
            }
        }

        foreach ($node->childNodes ?? [] as $child) {
            $this->walk($child, $errors);
        }
    }

    private function safeLiteralLink(string $url): bool
    {
        $url = trim($url);

        return (bool) preg_match('#^(https?://[^\s<>"\']+|mailto:[^\s<>"\']+)$#i', $url);
    }

    // --- rendering ------------------------------------------------------------------------------------------------------------

    /**
     * The full HTML of a message in the global template.
     *
     * @param  list<string|array{code: string}>  $blocks  paragraphs of plain text (escaped; line breaks kept) and, optionally, a highlighted code
     * @param  array{label: string, url: string}|null  $action  a button; ignored unless the URL is a valid http(s) link
     */
    public function render(string $title, array $blocks, ?array $action = null, ?string $footer = null, ?string $template = null): string
    {
        $template = $template ?? $this->activeHtml();

        if ($template === null || $this->validate($template) !== []) {
            $template = $this->defaultHtml();
        }

        $brand = (string) config('branding.name');
        $support = (string) config('branding.support_email');
        $values = [
            'brand' => $this->e($brand),
            'title' => $this->e($title),
            'content' => $this->contentHtml($blocks),
            'action' => $this->actionHtml($action),
            'footer' => $this->e($footer ?? "Este mensaje fue enviado por {$brand}."),
            'support' => $support !== '' ? $this->e("¿Necesitas ayuda? Escribe a {$support}.") : '',
        ];

        // One pass over the template's own text: a value that happens to contain «{{x}}» is NOT substituted again.
        return (string) preg_replace_callback('/\{\{([a-z_]+)\}\}/', fn (array $m) => $values[$m[1]] ?? '', $template);
    }

    /** The plain-text twin of a message (every mail carries one). @param list<string|array{code: string}> $blocks */
    public function text(string $title, array $blocks, ?array $action = null): string
    {
        $lines = [$title, ''];

        foreach ($blocks as $block) {
            $lines[] = is_array($block) ? (string) ($block['code'] ?? '') : (string) $block;
            $lines[] = '';
        }

        if ($action && $this->safeUrl($action['url'] ?? '')) {
            $lines[] = ($action['label'] ?? 'Abrir').': '.$action['url'];
        }

        return trim(implode("\n", $lines))."\n";
    }

    /** The message used by the editor's preview: fictitious data, nothing is sent. */
    public function preview(string $template): string
    {
        return $this->render(
            'Vista previa del mensaje',
            ['Hola, este es un mensaje de ejemplo con datos ficticios.', ['code' => '123456'], "Segunda línea del texto\ncon un salto de línea."],
            ['label' => 'Abrir el enlace de ejemplo', 'url' => 'https://example.com/ejemplo'],
            null,
            $template,
        );
    }

    /** @param  list<string|array{code: string}>  $blocks */
    private function contentHtml(array $blocks): string
    {
        $html = '';

        foreach ($blocks as $block) {
            if (is_array($block)) {
                $html .= '<p style="margin:16px 0;text-align:center;"><span style="display:inline-block;padding:12px 20px;font-family:Consolas,Menlo,monospace;font-size:26px;font-weight:bold;letter-spacing:6px;background-color:#eef3fb;border-radius:8px;color:#0f1b33;">'.$this->e((string) ($block['code'] ?? '')).'</span></p>';

                continue;
            }

            $html .= '<p style="margin:0 0 14px 0;">'.nl2br($this->e((string) $block), false).'</p>';
        }

        return $html;
    }

    /** @param  array{label?: string, url?: string}|null  $action */
    private function actionHtml(?array $action): string
    {
        if (! $action || ! $this->safeUrl($action['url'] ?? '')) {
            return '';
        }

        return '<a href="'.$this->e($action['url']).'" style="display:inline-block;padding:12px 22px;background-color:#1f4ba6;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:bold;font-size:15px;">'.$this->e($action['label'] ?? 'Abrir').'</a>';
    }

    /** A link that is safe to put in an email: absolute http or https with a host, no credentials and no characters that could leave the attribute. */
    public function safeUrl(mixed $url): bool
    {
        if (! is_string($url) || $url === '' || strlen($url) > 2000 || preg_match('/[\x00-\x20\x7f<>"\'`\\\\]/', $url)) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) && ! empty($parts['host']) && ! isset($parts['user']) && ! isset($parts['pass']);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
