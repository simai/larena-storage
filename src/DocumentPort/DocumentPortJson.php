<?php

declare(strict_types=1);

namespace Larena\Storage\DocumentPort;

use JsonException;
use Larena\Property\Runtime\CompositionDocument;
use stdClass;

/**
 * The port's payloads and answers as JSON, for a host that carries them over HTTP.
 *
 * PHP reads a JSON object as an array, so an empty object `{}` and an empty list `[]` are the same value once decoded.
 * A document keeps `{}` where its schema has an object (data, props, slots, presentation, extensions:
 * CompositionDocument::json()); an empty object anywhere else (inside unknown data, say) could not come back as sent,
 * so payload() refuses that save `would-lose` before anything is stored, as the round trip rule asks.
 */
final class DocumentPortJson
{
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS;

    /**
     * The payload of a request body, or the answer that refuses it.
     *
     * @return array{payload: array<string, mixed>}|array{answer: array<string, mixed>}
     */
    public static function payload(string $json): array
    {
        try {
            $objects = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['answer' => self::refused('invalid', 'the payload is not JSON')];
        }
        if (!$objects instanceof stdClass || !is_array($payload)) {
            return ['answer' => self::refused('invalid', 'the payload is not an object')];
        }
        $values = $objects->values ?? null;
        if ($values instanceof stdClass) {
            $diagnostics = [];
            foreach (get_object_vars($values) as $language => $document) {
                if ($document === null || !is_array($payload['values'][$language] ?? null)) {
                    continue;
                }
                if (json_encode($document, self::FLAGS) !== CompositionDocument::json($payload['values'][$language])) {
                    $diagnostics[] = ['code' => 'empty-object-not-kept', 'severity' => 'error', 'path' => '/values/' . $language, 'language' => (string) $language];
                }
            }
            if ($diagnostics !== []) {
                return ['answer' => self::refused('would-lose', 'an empty object outside the declared ones', ['diagnostics' => $diagnostics])];
            }
        }

        return ['payload' => $payload];
    }

    /** An answer as JSON, each document written by CompositionDocument::json() (its empty objects `{}`). @param array<string, mixed> $answer */
    public static function answer(array $answer): string
    {
        foreach (['data', 'current'] as $part) {
            foreach (['values', 'resolved'] as $key) {
                if (is_array($answer[$part][$key] ?? null)) {
                    $answer[$part][$key] = self::documents($answer[$part][$key]);
                }
            }
        }
        if (is_array($answer['data'] ?? null) && $answer['data'] === []) {
            $answer['data'] = new stdClass();
        }

        return (string) json_encode($answer, self::FLAGS | JSON_THROW_ON_ERROR);
    }

    /** @param array<array-key, mixed> $documents */
    private static function documents(array $documents): object
    {
        $written = new stdClass();
        foreach ($documents as $language => $document) {
            $json = CompositionDocument::json($document);
            $written->{(string) $language} = $json === null ? $document : json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        }

        return $written;
    }

    /** @param array<string, mixed>|null $data @return array<string, mixed> */
    private static function refused(string $reason, string $message, ?array $data = null): array
    {
        return ['answer' => 'refused', 'reason' => $reason, 'message' => 'refused: ' . $reason . ' (' . $message . ')'] + ($data !== null ? ['data' => $data] : []);
    }
}
