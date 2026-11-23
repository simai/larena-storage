<?php

declare(strict_types=1);

namespace Larena\Storage\Admin\Http\Controllers;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Larena\Admin\Runtime\AdminElement;
use Larena\Admin\Runtime\AdminElementDocumentRenderer;
use Larena\Admin\Runtime\AdminNode;
use Larena\Admin\Runtime\AdminSfElement;
use Larena\Storage\Admin\StorageDocumentEditor;
use Larena\Storage\DocumentPort\DocumentPort;
use Larena\Storage\DocumentPort\DocumentPortJson;
use Larena\Ui\Frontend\SourceBackedComponentRegistry;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * The page editor of a record's page document (document port, block 2): one screen per page at
 * /admin/storage/{structure}/{record}/document/{field}, the port the editor talks to beside it, and its pictures and
 * files. The page draws the Framework's sf-editor with the port's address on it; form-state.js hands the editor that
 * port (connect-port) and the editor loads, saves and shows a conflict itself. No editor script is Larena's.
 *
 * The port answers with HTTP 200 and the port's answer as it is, a refusal included: the editor reads the answer, not
 * the status. The reader and scope never come from input; without a reader the answer is `session-expired`.
 */
final readonly class StorageDocumentEditorController
{
    /** The pinned sf-editor edition that carries the document port is told apart by the port its manifest declares. */
    public const TAG = 'sf-editor';

    private const MAX_PAYLOAD_BYTES = 1_200_000;

    public function __construct(
        private StorageDocumentEditor $editor,
        private Config $config,
        private Translator $translator,
        private ViewFactory $views,
        private ?SourceBackedComponentRegistry $registry = null,
    ) {
    }

    /** The page: the editor of the record's page document, or what keeps it from opening. */
    public function page(Request $request, string $structure, string $record, string $field): View|Response
    {
        $actor = $this->actor($request) ?? throw new AccessDeniedHttpException('storage_document_editor_actor_missing');
        $bound = $this->editor->bind($actor, $structure, $record, $field);
        if ($bound === 'access-denied') {
            throw new AccessDeniedHttpException('storage_document_editor_denied');
        }
        if (is_string($bound)) {
            throw new NotFoundHttpException('storage_document_editor_unknown');
        }
        $rights = $this->editor->rights($actor, $bound['binding']) ?? throw new AccessDeniedHttpException('storage_document_editor_denied');
        $base = $this->sectionPath() . '/' . rawurlencode($structure) . '/' . rawurlencode($record) . '/document/' . rawurlencode($field);
        $recordPath = $this->sectionPath() . '/' . rawurlencode($structure) . '?record_id=' . rawurlencode($record) . '&record_mode=view&record_page=1';
        $heading = $bound['record_title'] . ' — ' . $bound['field_label'];

        $parts = [];
        if (!$rights['save']) {
            $parts[] = AdminSfElement::alert($this->word('read_only'));
        }
        $editor = $this->editorNode($bound, $rights, $base);
        if ($editor === null) {
            // The pinned Framework has no page editor yet: the page says so instead of a blank editor.
            $parts[] = AdminSfElement::alert($this->word('waiting_text'), 'info', $this->word('waiting_title'));
        } else {
            $parts[] = new AdminElement('p', ['class' => 'larena-muted', 'hidden' => null, 'data-larena-document-editor-waiting' => null], $this->word('waiting_text'));
            $parts[] = $editor;
        }

        StorageSectionController::breadcrumbs($request, ['label' => $bound['structure_label']], $structure, $this->sectionPath(), $heading);

        return $this->views->make('larena-storage::admin.document-editor', [
            'structureId' => $structure,
            'heading' => $heading,
            'structureLabel' => $bound['structure_label'],
            'recordPath' => $recordPath,
            'content' => (new AdminElementDocumentRenderer())->tree(...$parts),
        ]);
    }

    /** The port: {intent, payload} in, the port's answer out (HTTP 200), its empty objects kept. */
    public function port(Request $http, string $structure, string $record, string $field): Response
    {
        $raw = $http->getContent();
        if (!$http->isJson() || strlen($raw) > self::MAX_PAYLOAD_BYTES) {
            return $this->answer(['answer' => 'refused', 'reason' => 'invalid', 'message' => 'refused: invalid (not a JSON request)']);
        }
        // {intent, payload}: the payload is read by the port's JSON layer, which keeps a document's empty objects.
        $envelope = json_decode($raw, false, 512);
        $intent = $envelope instanceof \stdClass ? ($envelope->intent ?? null) : null;
        if (!is_string($intent) || strlen($intent) > 64) {
            return $this->answer(['answer' => 'refused', 'reason' => $envelope instanceof \stdClass ? 'unknown-intent' : 'invalid']);
        }
        $body = DocumentPortJson::payload((string) json_encode($envelope->payload ?? new \stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        if (isset($body['answer'])) {
            return $this->answer($body['answer']);
        }
        $payload = $body['payload'];
        try {
            $answer = $this->editor->port($this->actor($http), $structure, $record, $field)->send($intent, $payload);
        } catch (Throwable) {
            $answer = ['answer' => 'unavailable', 'reason' => 'storage-unavailable', 'retry_after' => DocumentPort::RETRY_AFTER];
        }

        return $this->answer($answer);
    }

    /**
     * The editor's pictures and files beside the port: a file (multipart `file`) is kept by the safe file service and
     * answered as media:<id> with its address; {ref} is answered with the address of a picture already on the page.
     */
    public function media(Request $request, string $structure, string $record, string $field): Response
    {
        $actor = $this->actor($request);
        if ($request->isJson()) {
            if (strlen($request->getContent()) > 4096) {
                return $this->answer(['answer' => 'refused', 'reason' => 'invalid']);
            }

            return $this->answer($this->editor->url($actor, $structure, $record, $field, $request->input('ref')));
        }

        return $this->answer($this->editor->upload($actor, $structure, $record, $field, $request->file('file')));
    }

    /**
     * The editor element, or null while the pinned Framework does not carry sf-editor.
     *
     * @param array{binding: \Larena\Storage\DocumentPort\DocumentPortBinding, field_label: string, record_title: string, structure_label: string} $bound
     * @param array{save: bool, publish: bool} $rights
     */
    public function editorNode(array $bound, array $rights, string $base): ?AdminNode
    {
        try {
            ($this->registry ?? SourceBackedComponentRegistry::bundled())->get(self::TAG);
        } catch (Throwable) {
            return null;
        }
        $binding = $bound['binding'];

        return new AdminSfElement(self::TAG, [
            'heading' => $bound['record_title'],
            'caption' => $bound['field_label'],
            'label' => $this->word('label'),
            'preferences-key' => 'storage-document',
        ], [
            // The editor stays hidden until form-state.js has handed it the port; an editor without the port is never shown.
            'hidden' => '',
            'data-larena-document-port' => $base . '/port',
            'data-larena-document-media' => $base . '/media',
            'data-larena-document-port-edition' => DocumentPort::EDITION,
            'data-larena-document-capabilities' => (string) json_encode($binding->capabilities(), JSON_UNESCAPED_SLASHES),
            'data-larena-document-rights' => (string) json_encode($rights, JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function actor(Request $request): ?string
    {
        $actor = $request->attributes->get((string) $this->config->get('larena-auth.admin_entry.access_actor_attribute', 'larena_access_actor'));

        return is_string($actor) && trim($actor) !== '' && strlen($actor) <= 191 ? $actor : null;
    }

    /** @param array<string, mixed> $answer */
    private function answer(array $answer): Response
    {
        $headers = ['Content-Type' => 'application/json', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
        if (($answer['answer'] ?? null) === 'unavailable' && is_int($answer['retry_after'] ?? null)) {
            $headers['Retry-After'] = (string) $answer['retry_after'];
        }

        return new Response(DocumentPortJson::answer($answer), 200, $headers);
    }

    private function word(string $key): string
    {
        return (string) $this->translator->get('larena-storage::admin.section.document_editor.' . $key);
    }

    private function sectionPath(): string
    {
        return '/' . trim((string) $this->config->get('larena-admin.storage_section.uri', 'admin/storage'), '/');
    }
}
