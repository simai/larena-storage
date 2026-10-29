<?php

declare(strict_types=1);

namespace Larena\Storage\Admin\Http\Controllers;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Larena\Storage\Admin\StorageEncryptedFieldReveal;
use Larena\Storage\Admin\StorageEncryptedFields;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * «Показать» of an encrypted field in the record panel (POST /admin/storage/{structure}/reveal, JSON {record_id,
 * field}): the field's plaintext for a person with storage.workbench.record.decrypt, audited by Storage. The answer
 * is never stored by the browser (no-store) and the plaintext is written nowhere on the server.
 */
final readonly class StorageFieldRevealController
{
    public function __construct(private StorageEncryptedFieldReveal $reveal, private Config $config)
    {
    }

    public function __invoke(Request $request, string $structure): JsonResponse
    {
        $attribute = (string) $this->config->get('larena-auth.admin_entry.access_actor_attribute', 'larena_access_actor');
        $actor = $request->attributes->get($attribute);
        if (!is_string($actor) || trim($actor) === '' || strlen($actor) > 191) {
            throw new AccessDeniedHttpException('storage_field_reveal_actor_missing');
        }
        $recordId = $request->input('record_id');
        $field = $request->input('field');
        if (!$request->isJson() || strlen($request->getContent()) > 4096 || !is_string($recordId) || !is_string($field)) {
            return $this->response(['answer' => 'refused', 'reason' => 'storage_field_reveal_invalid',
                'message' => StorageEncryptedFields::word('errors.storage_field_reveal_invalid', 'This value cannot be shown.')], 422);
        }
        $result = $this->reveal->reveal($actor, $structure, $recordId, $field);
        if ($result['status'] === 'ok') {
            return $this->response(['answer' => 'applied', 'text' => $result['text']], 200);
        }
        $reason = $result['reason_code'];
        $message = StorageEncryptedFields::word('errors.'.$reason, '');

        return $this->response(['answer' => $result['status'] === 'unavailable' ? 'unavailable' : 'refused', 'reason' => $reason,
            'message' => $message !== '' ? $message : StorageEncryptedFields::word('reveal_failed', 'The value could not be shown.')],
            match ($result['status']) {
                'denied' => 403,
                'unavailable' => 503,
                default => 422,
            });
    }

    /** @param array<string, mixed> $answer */
    private function response(array $answer, int $status): JsonResponse
    {
        return new JsonResponse($answer, $status, ['Cache-Control' => 'private, no-store', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff']);
    }
}
