<?php

declare(strict_types=1);

namespace Larena\Storage\Admin\Http\Controllers;

use Illuminate\Contracts\Config\Repository as Config;
use Larena\Storage\Admin\StorageWorkbenchDataviewPort;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Throwable;

final readonly class StorageWorkbenchDataviewPortController
{
    /** The data view port intents this list answers; column settings and saved views included. */
    private const INTENTS = [
        'query.change', 'bulk.apply_selected', 'bulk.apply_under_filter', 'record.open', 'record.mutate', 'view.create_record',
        'settings.save_personal', 'settings.save_shared', 'view.open', 'view.save', 'view.delete', 'view.save_shared',
    ];

    public function __construct(private StorageWorkbenchDataviewPort $port, private Config $config) {}

    public function __invoke(Request $request): JsonResponse
    {
        $attribute = (string) $this->config->get('larena-auth.admin_entry.access_actor_attribute', 'larena_access_actor');
        $actor = $request->attributes->get($attribute);
        if (!is_string($actor) || trim($actor) === '' || strlen($actor) > 191) {
            throw new AccessDeniedHttpException('storage_workbench_port_actor_missing');
        }
        $scope = $request->input('scope_ref');
        $structureId = $request->input('structure_id');
        $intent = $request->input('intent');
        $payload = $request->input('payload');
        if (!$request->isJson() || strlen($request->getContent()) > 1_048_576
            || !is_string($scope) || preg_match('/^scope:[a-z][a-z0-9_.:-]{0,184}$/D', $scope) !== 1
            || !is_string($structureId) || preg_match('/^workbench\.[a-z][a-z0-9_.:-]{0,109}$/D', $structureId) !== 1
            || !is_string($intent) || !in_array($intent, self::INTENTS, true)
            || !is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            return $this->response(['answer' => 'refused', 'reason' => 'port_request_invalid'], 422);
        }
        try {
            $answer = $this->port->raise($actor, $scope, $structureId, $intent, $payload);
            if (!in_array($answer['answer'] ?? null, ['applied', 'conflict', 'refused', 'unavailable'], true)) {
                throw new \UnexpectedValueException('storage_workbench_port_answer_invalid');
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
        return new JsonResponse($answer, $status,
            ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
