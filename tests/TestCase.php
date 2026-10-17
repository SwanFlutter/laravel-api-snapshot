<?php

declare(strict_types=1);

namespace SwanFlutter\LaravelApiSnapshot\Tests;

use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase as TestbenchTestCase;
use SwanFlutter\LaravelApiSnapshot\ApiSnapshotGenerator;
use SwanFlutter\LaravelApiSnapshot\ApiSnapshotServiceProvider;
use SwanFlutter\LaravelApiSnapshot\Tests\Fixture\Controllers\AttachmentController;
use SwanFlutter\LaravelApiSnapshot\Tests\Fixture\Controllers\HealthController;
use SwanFlutter\LaravelApiSnapshot\Tests\Fixture\Controllers\PatientController;
use SwanFlutter\LaravelApiSnapshot\Tests\Fixture\Controllers\Panel\StaffAttendanceController;
use SwanFlutter\LaravelApiSnapshot\Tests\Fixture\Controllers\ReportController;

abstract class TestCase extends TestbenchTestCase
{
    protected function getPackageProviders($app): array
    {
        return [ApiSnapshotServiceProvider::class];
    }

    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->middleware('api')->prefix('api/v1')->group(function (Router $router): void {
            $router->get('patients', [PatientController::class, 'index'])->name('patients.index');
            $router->post('patients', [PatientController::class, 'store'])->middleware('auth:sanctum');
            $router->get('patients/{id}', [PatientController::class, 'show'])->where('id', '[0-9]+');
            $router->put('patients/{id}', [PatientController::class, 'update']);

            $router->post('reports/generate', [ReportController::class, 'generate']);
            $router->post('reports/days', [ReportController::class, 'days']);
            $router->get('panel/staff/{staff}/attendance', [StaffAttendanceController::class, 'show']);
            $router->post('attachments', [AttachmentController::class, 'store']);
            $router->post('attachments/poster', [AttachmentController::class, 'poster']);
            $router->post('attachments/logo', [AttachmentController::class, 'logo']);
            $router->get('health', HealthController::class);

            $router->get('admin/users/{user}/status', function (string $user) {
                return ['user' => $user];
            })->middleware(['auth:sanctum', 'role:admin|super_admin']);

            $router->get('horizon/jobs', function () {
                return [];
            });
        });

        $router->get('web/page', function () {
            return 'page';
        });

        $router->get('{any}', function () {
            return 'fallback';
        })->where('any', '.*');
    }

    /**
     * Build a generator with the published config defaults applied.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function generator(array $overrides = [], bool $skipCache = true): ApiSnapshotGenerator
    {
        $config = array_replace($this->app['config']->get('api-snapshot', []), $overrides);

        return new ApiSnapshotGenerator(
            $this->app['router'],
            $config,
            $this->app->make('cache'),
            $skipCache,
        );
    }

    /** @return array<string, mixed> */
    protected function collection(array $overrides = [], bool $skipCache = true): array
    {
        return $this->generator($overrides, $skipCache)->generate();
    }

    /**
     * Every request item in the collection as "METHOD url" strings, without
     * the query string so a route stays identifiable.
     *
     * @return list<string>
     */
    protected function endpointLabels(array $collection): array
    {
        $labels = [];

        $walk = static function (array $items) use (&$walk, &$labels): void {
            foreach ($items as $item) {
                if (isset($item['request'])) {
                    $labels[] = $item['request']['method'] . ' ' . strtok($item['request']['url']['raw'], '?');
                } elseif (isset($item['item'])) {
                    $walk($item['item']);
                }
            }
        };

        $walk($collection['item'] ?? []);

        return $labels;
    }

    /**
     * Find the first request item whose URL ends with the given path suffix.
     *
     * @return array<string, mixed>|null
     */
    protected function findRequest(array $collection, string $method, string $pathSuffix): ?array
    {
        $found = null;

        $walk = static function (array $items) use (&$walk, &$found, $method, $pathSuffix): void {
            foreach ($items as $item) {
                if ($found !== null) {
                    return;
                }

                if (isset($item['request'])) {
                    $request = $item['request'];

                    if ($request['method'] === $method && str_ends_with(strtok($request['url']['raw'], '?'), $pathSuffix)) {
                        $found = $item;
                    }

                    continue;
                }

                if (isset($item['item'])) {
                    $walk($item['item']);
                }
            }
        };

        $walk($collection['item'] ?? []);

        return $found;
    }
}
