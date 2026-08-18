<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

use App\Models\User;

use App\Http\Controllers\CombustibleController;
use App\Http\Controllers\TipoEquipoController;
use App\Http\Controllers\EquipoController;
use App\Http\Controllers\CargaController;
use App\Http\Controllers\OperadorController;
use App\Http\Controllers\UnidadController;
use App\Http\Controllers\ObraController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ReporteRendimientoController;
use App\Http\Controllers\ServicioController;
use App\Http\Controllers\MantenimientoReglaController;
use App\Http\Controllers\MantenimientoRealizadoController;
use App\Http\Controllers\EquipoMedicionesEventoController;
use App\Http\Controllers\EmpresaController;
use App\Http\Controllers\RolController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\RolPermissionController;
use App\Http\Controllers\MantenimientosProximosController;
use App\Services\NumeroEconomicoService;
use App\Http\Controllers\EquipoArchivoController;
use App\Http\Controllers\EquipoArchivoTemporalController;
use App\Http\Controllers\ApiStatusController;

/*
|--------------------------------------------------------------------------
| Rutas PÚBLICAS
|--------------------------------------------------------------------------
*/

Route::get('status', ApiStatusController::class);
Route::get('install/status', [InstallController::class, 'status']);
Route::post('install', [InstallController::class, 'install']);

// Registro (opcional)
Route::post('/register', function (Request $request) {
    $data = $request->validate([
        'name'     => 'required|string|max:255',
        'email'    => 'required|email|unique:users,email',
        'password' => 'required|min:6',
    ]);

    $user = User::create([
        'name'     => $data['name'],
        'email'    => $data['email'],
        'password' => Hash::make($data['password']),
    ]);

    return response()->json(['message' => 'Usuario registrado'], 201);
});

// Login
Route::post('/login', function (Request $request) {
    $needsInstall = !(
        \App\Models\User::query()->exists() ||
        \App\Models\Empresa::query()->exists()
    );

    if ($needsInstall) {
        return response()->json([
            'message' => 'El sistema no está instalado. Debes crear la empresa y el usuario admin.',
            'needs_install' => true
        ], 428);
    }

    $request->validate([
        'email'    => 'required|email',
        'password' => 'required',
    ]);

    $user = User::where('email', $request->email)->first();

    if (! $user || ! Hash::check($request->password, $user->password)) {
        return response()->json(['message' => 'Credenciales inválidas'], 401);
    }

    $user->load(['empresa', 'rol']);

    $token = $user->createToken('auth_token')->plainTextToken;

    return response()->json([
        'message' => 'Login exitoso',
        'token'   => $token,
        'user'    => $user,
    ]);
});

// Ping
Route::get('/ping', fn() => response()->json(['pong' => true]));

// Subida de foto pública
Route::post('/upload-foto', function (Request $request) {
    if ($request->hasFile('foto')) {
        $path = $request->file('foto')->store('fotos', 'public');
        $url  = Storage::url($path);
        return response()->json(['ruta' => $url]);
    }

    return response()->json(['error' => 'No se recibió archivo'], 400);
});

Route::get('/equipos/numeco/preview', function (Request $request, NumeroEconomicoService $svc) {
    try {
        $tipo = (int) $request->query('tipo');
        $clave = (string) $request->query('clave', '');

        if (!$tipo) {
            return response()->json(['message' => 'tipo requerido'], 422);
        }

        if (!$clave) {
            return response()->json(['message' => 'clave requerida'], 422);
        }

        return response()->json(
            $svc->previewPorTipoYClave($tipo, $clave)
        );
    } catch (\Throwable $e) {
        return response()->json([
            'message' => $e->getMessage(),
        ], 500);
    }
});

/*
|--------------------------------------------------------------------------
| Rutas PROTEGIDAS (auth:sanctum)
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', function (Request $request) {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Sesión cerrada']);
    });

    Route::get('/user', fn(Request $request) => $request->user());

    Route::get('/me/permissions', [MeController::class, 'permissions']);

    // 🔐 Todo el módulo MOTIV
    Route::middleware('permission:motiv.view')->group(function () {

        // Users
        Route::apiResource('users', UserController::class)
            ->middleware('permission:catalog.manage');

        // Combustibles
        Route::apiResource('combustibles', CombustibleController::class)
            ->middleware('permission:catalog.manage');

        // Tipos de equipos
        Route::apiResource('tipo_equipos', TipoEquipoController::class)
            ->middleware('permission:catalog.manage');

        // Equipos
        Route::get('/equipos', [EquipoController::class, 'index'])
            ->middleware('permission:equipo.create_update');

        Route::get('/equipos/{equipo}', [EquipoController::class, 'show'])
            ->middleware('permission:equipo.create_update');

        Route::post('/equipos', [EquipoController::class, 'store'])
            ->middleware('permission:equipo.create_update');

        Route::put('/equipos/{equipo}', [EquipoController::class, 'update'])
            ->middleware('permission:equipo.create_update');

        Route::delete('/equipos/{equipo}', [EquipoController::class, 'destroy'])
            ->middleware('permission:equipo.deactivate');

        Route::post('/equipos/{equipo}/assign', [EquipoController::class, 'assign'])
            ->middleware('permission:equipo.create_update');

        Route::get('/equipos/numeco/{numeco}', [EquipoController::class, 'showByNumeco'])
            ->middleware('permission:equipo.create_update');

        /*
        |--------------------------------------------------------------------------
        | Archivos temporales de equipos (alta nueva sin equipo_id)
        |--------------------------------------------------------------------------
        */
        Route::get('/equipos/archivos-temp/{uploadToken}', [EquipoArchivoTemporalController::class, 'index'])
            ->middleware('permission:equipo.create_update');

        Route::post('/equipos/archivos-temp', [EquipoArchivoTemporalController::class, 'store'])
            ->middleware('permission:equipo.create_update');

        Route::delete('/equipos/archivos-temp/{id}', [EquipoArchivoTemporalController::class, 'destroy'])
            ->middleware('permission:equipo.create_update');

        /*
        |--------------------------------------------------------------------------
        | Archivos definitivos de equipos (equipo ya existente)
        |--------------------------------------------------------------------------
        */
        Route::prefix('equipos/{equipo}')->group(function () {
            Route::get('archivos', [EquipoArchivoController::class, 'index'])
                ->middleware('permission:equipo.create_update');

            Route::post('archivos', [EquipoArchivoController::class, 'store'])
                ->middleware('permission:equipo.create_update');

            Route::put('archivos/{archivo}', [EquipoArchivoController::class, 'update'])
                ->middleware('permission:equipo.create_update');

            Route::get('archivos/{archivo}/ver', [EquipoArchivoController::class, 'ver'])
                ->middleware('permission:equipo.create_update')
                ->name('equipos.archivos.ver');

            Route::get('archivos/{archivo}/descargar', [EquipoArchivoController::class, 'descargar'])
                ->middleware('permission:equipo.create_update')
                ->name('equipos.archivos.descargar');

            Route::delete('archivos/{archivo}', [EquipoArchivoController::class, 'destroy'])
                ->middleware('permission:equipo.create_update');
        });

        // Cargas
        Route::get('/cargas/next-folio', [CargaController::class, 'nextFolio'])
            ->middleware('permission:carga.create');

        Route::get('/cargas', [CargaController::class, 'index'])
            ->middleware('permission:carga.view_history');

        Route::post('/cargas', [CargaController::class, 'store'])
            ->middleware('permission:carga.create');

        Route::get('/cargas/{id}', [CargaController::class, 'show'])->whereNumber('id')
            ->middleware('permission:carga.view_history');

        Route::put('/cargas/{id}', [CargaController::class, 'update'])->whereNumber('id')
            ->middleware('permission:carga.update_cancel');

        Route::delete('/cargas/{id}', [CargaController::class, 'destroy'])->whereNumber('id')
            ->middleware('permission:carga.update_cancel');

        // Obras / Unidades / Operadores
        Route::apiResource('obras', ObraController::class)
            ->middleware('permission:catalog.manage');

        Route::apiResource('unidads', UnidadController::class)
            ->middleware('permission:catalog.manage');

        Route::apiResource('operadors', OperadorController::class)
            ->middleware('permission:catalog.manage');

        // Servicios / Mantenimientos
        Route::apiResource('servicios', ServicioController::class)
            ->middleware('permission:mantenimiento.create');

        Route::apiResource('mantenimiento-reglas', MantenimientoReglaController::class)
            ->middleware('permission:catalog.manage');

        Route::apiResource('mantenimiento-realizados', MantenimientoRealizadoController::class)
            ->middleware('permission:mantenimiento.create');

        Route::apiResource('equipo-mediciones-eventos', EquipoMedicionesEventoController::class)
            ->middleware('permission:catalog.manage');

        // Rendimiento / Reportes
        Route::get('equipos/{equipo}/rendimiento', [ReporteRendimientoController::class, 'show'])
            ->middleware('permission:rendimiento.view');

        Route::get(
            'equipos/{equipo}/mantenimientos-proximos',
            [MantenimientosProximosController::class, 'show']
        )->middleware('permission:catalog.manage');

        // Empresas / Roles
        Route::apiResource('empresas', EmpresaController::class)
            ->middleware('permission:catalog.manage');

        Route::apiResource('roles', RolController::class)
            ->middleware('permission:catalog.manage');

        Route::get('/roles/{rol}/permissions', [RolPermissionController::class, 'index'])
            ->middleware('permission:catalog.manage');

        Route::put('/roles/{rol}/permissions', [RolPermissionController::class, 'update'])
            ->middleware('permission:catalog.manage');
    });
});