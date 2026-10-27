<?php

namespace App\Services;

/**
 * The copyable cURL commands of the automation API (what an n8n «HTTP Request» node sends), built from the REAL routes of this deployment so they never drift from
 * the code. cURL is only an HTTP client: it replaces neither the API nor the token, so every command authenticates with the chatbot token read from the
 * `AVA_TOKEN` environment variable of the shell — the token itself is never written here (Ava shows it once, when it is generated, and stores only its hash).
 */
class AgentCurlExamples
{
    public const TOKEN_VARIABLE = 'AVA_TOKEN';

    /** @return list<array{key: string, title: string, method: string, url: string, description: string, command: string, expect: string}> */
    public function all(): array
    {
        return [
            $this->example('config', 'Leer la configuración del chatbot', 'GET', route('agent.config'), null,
                'Devuelve el nombre, la descripción, las instrucciones y los canales activos del chatbot dueño del token.',
                '200 con la configuración. 401 si el token no existe o fue rotado; 403 si el plan del Workspace no incluye Asistentes.'),
            $this->example('message', 'Reportar un mensaje', 'POST', route('agent.messages.store'), [
                'channel' => 'whatsapp', 'contact_id' => '573001112233', 'contact_name' => 'Nombre del contacto', 'direction' => 'in', 'type' => 'text', 'body' => 'Hola', 'external_id' => 'wamid.XXXX',
            ], 'Guarda un mensaje del contacto («in») o de la IA («out») en su conversación. `external_id` evita duplicados si n8n repite la petición.',
                '200 con el control de la conversación (`control.ai_allowed` y `control.version`). 422 con el campo inválido.'),
            $this->example('authorize', 'Preguntar si la IA puede responder', 'POST', route('agent.conversations.authorize'), [
                'channel' => 'whatsapp', 'contact_id' => '573001112233', 'version' => 0,
            ], 'Justo antes de enviar una respuesta: si la conversación pasó a una persona, la respuesta ya no debe salir.',
                '200 con `control.ai_allowed`. Si es falso, no envíes.'),
            $this->example('handoff', 'Pedir una persona', 'POST', route('agent.conversations.handoff'), [
                'channel' => 'whatsapp', 'contact_id' => '573001112233', 'contact_name' => 'Nombre del contacto', 'reason' => 'El cliente pidió hablar con una persona',
            ], 'Marca la conversación como pendiente de atención humana.', '200 con el nuevo estado de control.'),
            $this->example('knowledge', 'Buscar en la base de conocimiento', 'GET', route('agent.knowledge.search').'?q=horario%20de%20atencion', null,
                'Devuelve los fragmentos de los documentos que este asistente puede consultar y comparten palabras con la pregunta (`q`, 3 a '.config('chatbots.knowledge.query_max').' caracteres), cada uno con su documento de origen. No hay documentos aprendidos: solo lo que la búsqueda devuelve.',
                '200 con `results` (puede estar vacío), `count` y `note`. 401 si el token no existe; 422 si `q` falta o es muy corta; 404 si la base de conocimiento aún no está disponible.'),
        ];
    }

    /**
     * @param  ?array<string, mixed>  $body
     * @return array{key: string, title: string, method: string, url: string, description: string, command: string, expect: string}
     */
    private function example(string $key, string $title, string $method, string $url, ?array $body, string $description, string $expect): array
    {
        $lines = ["curl -sS -X {$method} \"{$url}\"", '  -H "Authorization: Bearer $'.self::TOKEN_VARIABLE.'"', '  -H "Accept: application/json"'];

        if ($body !== null) {
            $lines[] = '  -H "Content-Type: application/json"';
            $lines[] = "  -d '".json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."'";
        }

        return ['key' => $key, 'title' => $title, 'method' => $method, 'url' => $url, 'description' => $description, 'command' => implode(" \\\n", $lines), 'expect' => $expect];
    }
}
