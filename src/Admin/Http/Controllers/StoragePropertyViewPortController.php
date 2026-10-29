<?php

declare(strict_types=1);

namespace Larena\Storage\Admin\Http\Controllers;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Larena\Admin\Contracts\PropertyViewHostPortGateway;
use Larena\Storage\Admin\StoragePropertyViewPort;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Throwable;

/** The Framework property view of a Storage section record (simai.property-view-port 1.0.0). */
final readonly class StoragePropertyViewPortController
{
    public function __construct(private StoragePropertyViewPort $port, private Config $config,
        private \Larena\Storage\Admin\StorageStructurePropertyViewPort $structures,
        private \Larena\Storage\Admin\StorageFieldPropertyViewPort $fields) {}

    public function __invoke(Request $request): JsonResponse
    {
        $attribute = (string) $this->config->get('larena-auth.admin_entry.access_actor_attribute', 'larena_access_actor');
        $actor = $request->attributes->get($attribute);
        if (!is_string($actor) || trim($actor) === '' || strlen($actor) > 191) {
            throw new AccessDeniedHttpException('storage_property_port_actor_missing');
        }
        $scope = $request->input('scope_ref');
        $intent = $request->input('intent');
        $payload = $request->input('payload');
        // A file travels as multipart beside its payload (field.upload); every other intent is JSON.
        $upload = $intent === 'field.upload' && !$request->isJson();
        if ($upload) {
            $payload = is_string($payload) ? json_decode($payload, true, 8) : null;
            if (is_array($payload)) {
                $payload['file'] = $request->file('file');
            }
        }
        if ((!$upload && (!$request->isJson() || strlen($request->getContent()) > 1_048_576))
            || !is_string($scope) || preg_match('/^scope:[a-z][a-z0-9_.:-]{0,184}$/D', $scope) !== 1
            || !is_string($intent) || !in_array($intent, PropertyViewHostPortGateway::INTENTS, true)
            || !is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            return $this->response(['answer' => 'refused', 'reason' => 'port_request_invalid'], 422);
        }
        try {
            // A storage itself (made from the section's «Create storage») has its own port; a record, the records'.
            // A storage's field (the storage settings list, owner decision 2026-10-16) has its own port too.
            $collection = is_string($payload['collection'] ?? null) ? $payload['collection'] : '';
            $port = match (true) {
                $collection === \Larena\Storage\Admin\StorageStructurePropertyViewPort::COLLECTION => $this->structures,
                str_starts_with($collection, \Larena\Storage\Admin\StorageFieldPropertyViewPort::COLLECTION_PREFIX) => $this->fields,
                default => $this->port,
            };
            $answer = $port->raise($actor, $scope, $intent, $payload);
            if (!in_array($answer['answer'] ?? null, ['applied', 'conflict', 'refused', 'unavailable'], true)) {
                throw new \UnexpectedValueException('storage_property_port_answer_invalid');
            }
        } catch (Throwable) {
            $answer = ['answer' => 'unavailable', 'reason' => 'port_failed'];
        }
        $status = match ($answer['answer']) {
            'applied' => 200, 'conflict' => 409, 'refused' => 422, default => 503,
        };

        return $this->response($answer, $status);
    }

    /** @param array<string,mixed> $answer */
    private function response(array $answer, int $status): JsonResponse
    {
        return new JsonResponse($answer, $status, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
