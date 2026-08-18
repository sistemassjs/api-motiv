<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Estado público de la API.
 * Para publicar una release: subir VERSION y desplegar; verificar en GET /api/status.
 */
class ApiStatusController extends Controller
{
    /** Versión de la API (actualizar a mano en cada release). */
    public const VERSION = '1.0.0';

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'SUCCESS',
            'code' => 200,
            'message' => 'API operativa.',
            'data' => [
                'name' => config('app.name'),
                'service' => 'api-motiv',
                'version' => self::VERSION,
            ],
            'errors' => null,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
}
