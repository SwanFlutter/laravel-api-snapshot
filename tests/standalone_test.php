<?php

/**
 * Standalone test — swanflutter/laravel-api-snapshot
 * Run: php tests/standalone_test.php
 */

declare(strict_types=1);

// ── Autoloader ────────────────────────────────────────────────────────────────
spl_autoload_register(function (string $class): void {
    $prefix = 'SwanFlutter\\LaravelApiSnapshot\\';
    $base   = __DIR__ . '/../src/';
    if (!str_starts_with($class, $prefix)) return;
    $file = $base . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) require $file;
});

// ── Illuminate stubs ──────────────────────────────────────────────────────────
if (!class_exists(\Illuminate\Foundation\Http\FormRequest::class)) {
    class_alias(StubFormRequest::class, \Illuminate\Foundation\Http\FormRequest::class);
}

class StubFormRequest {
    public function rules(): array   { return []; }
    public function authorize(): bool { return true; }
}

// ── Fake Route / Router ───────────────────────────────────────────────────────
class FakeRoute {
    public function __construct(
        private array $methods, private string $uri,
        private ?string $controller = null, private ?string $method = null,
        private array $middleware = ['api']
    ) {}
    public function uri(): string          { return $this->uri; }
    public function methods(): array       { return $this->methods; }
    public function gatherMiddleware(): array { return $this->middleware; }
    public function getAction(?string $key = null): mixed {
        $action = ($this->controller && $this->method)
            ? ['controller' => $this->controller . '@' . $this->method] : [];
        return $key ? ($action[$key] ?? null) : $action;
    }
}

class FakeRouter {
    public function __construct(private array $routes) {}
    public function getRoutes(): self { return $this; }
    public function getRoutes2(): array { return $this->routes; }
}

// Re-implement getRoutes to return an object with getRoutes()
class FakeRouteCollection {
    public function __construct(private array $routes) {}
    public function getRoutes(): array { return $this->routes; }
}
class FakeRouter2 {
    private FakeRouteCollection $col;
    public function __construct(array $routes) { $this->col = new FakeRouteCollection($routes); }
    public function getRoutes(): FakeRouteCollection { return $this->col; }
}

// ── Sample FormRequests ───────────────────────────────────────────────────────
class ListUsersRequest extends StubFormRequest {
    public function rules(): array {
        return [
            'page'       => 'integer|min:1',
            'limit'      => 'integer|min:1|max:100',
            'search'     => 'nullable|string|max:255',
            'role'       => 'nullable|in:admin,doctor,patient',
            'status'     => 'nullable|in:active,inactive,banned',
            'date_from'  => 'nullable|date',
            'date_to'    => 'nullable|date',
            'sort_by'    => 'nullable|in:created_at,last_login_at,name',
            'sort_order' => 'nullable|in:ASC,DESC',
        ];
    }
}

class StoreUserRequest extends StubFormRequest {
    public function rules(): array {
        return [
            'phone'        => 'required|string|min:1',
            'email'        => 'required|email|max:255',
            'national_code'=> 'required|string|min:10|max:10',
            'password'     => 'required|string|min:8',
            'role'         => 'required|in:admin,doctor,patient',
            'status'       => 'required|in:active,inactive',
            'first_name'   => 'required|string|min:1',
            'last_name'    => 'required|string|min:1',
            'birth_date'   => 'nullable|date',
            'specialty_id' => 'nullable|integer',
            'doctor_id'    => 'nullable|integer',
        ];
    }
}

class UpdateUserRequest extends StubFormRequest {
    public function rules(): array {
        return [
            'phone'       => 'nullable|string|min:1',
            'email'       => 'nullable|email',
            'first_name'  => 'nullable|string|min:1',
            'last_name'   => 'nullable|string|min:1',
            'birth_date'  => 'nullable|date',
            'is_active'   => 'nullable|boolean',
            'is_verified' => 'nullable|boolean',
        ];
    }
}

class StoreDoctorRequest extends StubFormRequest {
    public function rules(): array {
        return [
            'phone'              => 'required|string|min:1',
            'email'              => 'required|email',
            'national_code'      => 'required|string|min:10',
            'password'           => 'required|string|min:8',
            'first_name'         => 'required|string',
            'last_name'          => 'required|string',
            'medical_license_no' => 'required|string',
            'specialty_id'       => 'required|integer',
            'sub_specialty_id'   => 'nullable|integer',
            'bio'                => 'nullable|string',
            'visit_duration'     => 'required|integer|min:5|max:120',
            'visit_price'        => 'required|numeric|min:0',
            'is_available'       => 'required|boolean',
        ];
    }
}

class ListAppointmentsRequest extends StubFormRequest {
    public function rules(): array {
        return [
            'page'           => 'integer|min:1',
            'limit'          => 'integer|min:1|max:100',
            'doctor_id'      => 'nullable|integer',
            'patient_id'     => 'nullable|integer',
            'workplace_id'   => 'nullable|integer',
            'status'         => 'nullable|in:pending,confirmed,completed,cancelled,no_show',
            'date_from'      => 'nullable|date',
            'date_to'        => 'nullable|date',
            'booking_type'   => 'nullable|in:online,phone,in_person',
            'payment_status' => 'nullable|in:paid,unpaid,refunded',
        ];
    }
}

// ── FormRequest map ───────────────────────────────────────────────────────────
$formRequestMap = [
    'AdminUserController@index'        => ListUsersRequest::class,
    'AdminUserController@store'        => StoreUserRequest::class,
    'AdminUserController@update'       => UpdateUserRequest::class,
    'AdminDoctorController@store'      => StoreDoctorRequest::class,
    'AdminAppointmentController@index' => ListAppointmentsRequest::class,
];

// ── Routes ────────────────────────────────────────────────────────────────────
$routes = [
    new FakeRoute(['GET'],    'api/v1/admin/auth/me',                        'AdminAuthController',        'me'),
    new FakeRoute(['GET'],    'api/v1/admin/users',                          'AdminUserController',        'index'),
    new FakeRoute(['POST'],   'api/v1/admin/users',                          'AdminUserController',        'store'),
    new FakeRoute(['GET'],    'api/v1/admin/users/{id}',                     'AdminUserController',        'show'),
    new FakeRoute(['PUT'],    'api/v1/admin/users/{id}',                     'AdminUserController',        'update'),
    new FakeRoute(['DELETE'], 'api/v1/admin/users/{id}',                     'AdminUserController',        'destroy'),
    new FakeRoute(['POST'],   'api/v1/admin/users/{id}/reset-password',      'AdminUserController',        'resetPassword'),
    new FakeRoute(['PUT'],    'api/v1/admin/users/{id}/status',              'AdminUserController',        'updateUserStatus'),
    new FakeRoute(['GET'],    'api/v1/admin/doctors',                        'AdminDoctorController',      'index'),
    new FakeRoute(['POST'],   'api/v1/admin/doctors',                        'AdminDoctorController',      'store'),
    new FakeRoute(['GET'],    'api/v1/admin/doctors/{id}',                   'AdminDoctorController',      'show'),
    new FakeRoute(['PUT'],    'api/v1/admin/doctors/{id}',                   'AdminDoctorController',      'update'),
    new FakeRoute(['PUT'],    'api/v1/admin/doctors/{id}/verify',            'AdminDoctorController',      'verify'),
    new FakeRoute(['PUT'],    'api/v1/admin/doctors/{id}/toggle-availability','AdminDoctorController',     'toggleAvailability'),
    new FakeRoute(['GET'],    'api/v1/admin/appointments',                   'AdminAppointmentController', 'index'),
    new FakeRoute(['GET'],    'api/v1/admin/appointments/{id}',              'AdminAppointmentController', 'show'),
    new FakeRoute(['PUT'],    'api/v1/admin/appointments/{id}/cancel',       'AdminAppointmentController', 'cancel'),
    new FakeRoute(['PUT'],    'api/v1/admin/appointments/{id}/reschedule',   'AdminAppointmentController', 'reschedule'),
    new FakeRoute(['GET'],    'api/v1/patient/profile',                      'PatientProfileController',   'show',   ['api', 'auth:sanctum']),
    new FakeRoute(['PUT'],    'api/v1/patient/profile',                      'PatientProfileController',   'update', ['api', 'auth:sanctum']),
    new FakeRoute(['GET'],    'api/v1/patient/appointments',                 'PatientAppointmentController','index', ['api', 'auth:sanctum']),
];

// ── Generator that works with FakeRoute ──────────────────────────────────────
$fakeGen = new SwanFlutter\LaravelApiSnapshot\Generators\FakeValueGenerator();

function resolveRules(string $controllerMethod, array $map): array {
    if (!isset($map[$controllerMethod])) return [];
    $class = $map[$controllerMethod];
    $inst  = new $class();
    $raw   = $inst->rules();
    $rules = [];
    foreach ($raw as $field => $r) {
        $rules[$field] = is_string($r) ? explode('|', $r) : (is_array($r) ? $r : []);
    }
    return $rules;
}

function buildPathVars(string $uri, array $rules, $fakeGen): array {
    preg_match_all('/\{(\w+)\??}/', $uri, $m);
    $vars = [];
    foreach ($m[1] as $param) {
        $val = isset($rules[$param]) ? ($fakeGen->generate($param, $rules[$param]) ?? 1)
             : (str_contains(strtolower($param), 'id') ? 1 : 'example');
        $vars[] = ['id' => $param, 'key' => $param, 'value' => (string)$val, 'description' => 'The '.$param];
    }
    return $vars;
}

function buildParams(array $httpMethods, string $uri, array $rules, $fakeGen): array {
    preg_match_all('/\{(\w+)\??}/', $uri, $m);
    $pathKeys  = array_flip($m[1]);
    $remaining = array_filter($rules, fn($k) => !isset($pathKeys[$k]), ARRAY_FILTER_USE_KEY);
    $isRead    = !empty(array_intersect($httpMethods, ['GET','DELETE','HEAD']));
    $isWrite   = !empty(array_intersect($httpMethods, ['POST','PUT','PATCH']));
    $qp = []; $bp = null;
    if ($isRead && !empty($remaining)) {
        foreach ($remaining as $field => $fr) {
            $v = $fakeGen->generate($field, $fr);
            $qp[] = ['key'=>$field,'value'=>$v===null?'':(string)$v,'description'=>implode(', ',array_filter($fr,fn($r)=>!in_array($r,['required','nullable','sometimes']))),'disabled'=>false];
        }
    } elseif ($isWrite && !empty($remaining)) {
        $bp = [];
        foreach ($remaining as $field => $fr) $bp[$field] = $fakeGen->generate($field, $fr);
    }
    return [$qp, $bp];
}

function segToTitle(string $s): string {
    $s = trim(preg_replace('/\{.*?}/','',$s),'-_');
    if ($s==='') return 'General';
    $words = preg_split('/[_\-]/',$s)?:[$s];
    $sing  = fn($w) => (str_ends_with(strtolower($w),'ies')&&strlen($w)>3) ? substr($w,0,-3).'y'
           : ((str_ends_with(strtolower($w),'s')&&!str_ends_with(strtolower($w),'ss')&&!str_ends_with(strtolower($w),'us')&&strlen($w)>3) ? substr($w,0,-1) : $w);
    return implode(' ', array_map('ucfirst', array_map($sing, $words)));
}

$config = [
    'name'                => 'NobatNo API Documentation',
    'base_url'            => 'http://localhost',
    'include_middleware'  => ['api'],
    'exclude_patterns'    => ['telescope','horizon','_debugbar'],
    'persian_descriptions'=> true,
    'default_headers'     => [['key'=>'Content-Type','value'=>'application/json'],['key'=>'Accept','value'=>'application/json']],
    'auth'                => ['type'=>'noauth','token'=>null],
];

// Group routes
$groups = [];
foreach ($routes as $route) {
    $segs = array_values(array_filter(explode('/', ltrim($route->uri(),'/')), fn($s)=>$s!==''&&!str_starts_with($s,'{')));
    if (isset($segs[0]) && strtolower($segs[0])==='api') array_shift($segs);
    if (isset($segs[0]) && preg_match('/^v\d+$/i',$segs[0])) array_shift($segs);
    $top = segToTitle($segs[0]??'General');
    $sub = segToTitle($segs[1]??'General');
    $groups[$top][$sub][] = $route;
}
ksort($groups);
foreach ($groups as &$sg) ksort($sg);

// Build items
$items = [];
foreach ($groups as $gName => $subs) {
    $gItem = ['name'=>$gName,'description'=>'','item'=>[]];
    foreach ($subs as $sName => $routeList) {
        $sItem = ['name'=>$sName,'description'=>'','item'=>[]];
        foreach ($routeList as $route) {
            $action = $route->getAction('controller') ?? '';
            $method = null;
            if ($action) { $parts=explode('@',$action); $method=$parts[1]??null; }
            $httpMethods = array_values(array_filter($route->methods(), fn($m)=>$m!=='HEAD'));
            $uri   = ltrim($route->uri(),'/');
            $rules = resolveRules($action, $formRequestMap);
            $pvars = buildPathVars($uri, $rules, $fakeGen);
            [$qp, $bp] = buildParams($httpMethods, $uri, $rules, $fakeGen);

            foreach ($httpMethods as $hm) {
                $name = $method ? (preg_replace('/(?<!^)([A-Z])/','  $1',$method)??$method) : strtoupper($hm).' '.$uri;
                $desc = "این endpoint عملیات «{$method}» را انجام می‌دهد.\nورودی‌ها: بر اساس فیلدهای ارسالی در Request و قواعد اعتبارسنجی همین متد بررسی می‌شوند.\nخروجی: پاسخ JSON شامل وضعیت عملیات، پیام و داده‌های مرتبط.";
                $rawQs = '';
                if (!empty($qp)) {
                    $pairs = array_map(fn($q)=>rawurlencode($q['key']).'='.rawurlencode((string)($q['value']??'')), $qp);
                    $rawQs = '?'.implode('&',$pairs);
                }
                $url = ['host'=>'{{baseUrl}}','path'=>$uri,'query'=>$qp,'raw'=>'{{baseUrl}}/'.$uri.$rawQs];
                if (!empty($pvars)) $url['variable'] = $pvars;
                $body = (in_array($hm,['POST','PUT','PATCH'],true)&&!empty($bp)) ? ['mode'=>'raw','raw'=>$bp] : null;
                $sItem['item'][] = [
                    'name'    => trim($name),
                    'request' => [
                        'url'         => $url,
                        'method'      => strtoupper($hm),
                        'header'      => $config['default_headers'],
                        'body'        => $body,
                        'description' => $desc,
                        'auth'        => ['type'=>'noauth'],
                    ],
                    'response' => [],
                ];
            }
        }
        $gItem['item'][] = $sItem;
    }
    $items[] = $gItem;
}

$data    = random_bytes(16);
$data[6] = chr((ord($data[6])&0x0f)|0x40);
$data[8] = chr((ord($data[8])&0x3f)|0x80);
$uuid    = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data),4));

$collection = [
    'variable' => [['id'=>'baseUrl','key'=>'baseUrl','type'=>'string','name'=>'string','value'=>$config['base_url']]],
    'info'     => ['name'=>$config['name'],'_postman_id'=>$uuid,'description'=>'','schema'=>'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'],
    'item'     => $items,
];

$json = json_encode($collection, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
file_put_contents(__DIR__.'/../test_output.json', $json);

// ── Summary ───────────────────────────────────────────────────────────────────
echo "\n╔══════════════════════════════════════════════════════╗\n";
echo "║   swanflutter/laravel-api-snapshot — Test Result     ║\n";
echo "╚══════════════════════════════════════════════════════╝\n\n";

$total = 0; $subTotal = 0;
foreach ($collection['item'] as $group) {
    echo "📁 {$group['name']}\n";
    foreach ($group['item'] as $sub) {
        $cnt = count($sub['item']); $subTotal++; $total += $cnt;
        echo "   📂 {$sub['name']} ({$cnt} endpoints)\n";
        foreach ($sub['item'] as $ep) {
            $m   = $ep['request']['method'];
            $raw = $ep['request']['url']['raw'];
            $b   = $ep['request']['body'] ? ' [body ✓]' : '';
            echo "      ".str_pad($m,7).$raw.$b."\n";
        }
    }
    echo "\n";
}

echo "─────────────────────────────────────────────────────\n";
printf("  Groups: %d   Sub-groups: %d   Endpoints: %d   JSON: %s KB\n",
    count($collection['item']), $subTotal, $total, round(strlen($json)/1024,1));
echo "─────────────────────────────────────────────────────\n";

// ── Validations ───────────────────────────────────────────────────────────────
$errors = 0;
foreach (['variable','info','item'] as $k) {
    if (!array_key_exists($k,$collection)) { echo "❌ Missing: {$k}\n"; $errors++; }
}
if (($collection['info']['schema']??'') !== 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json') {
    echo "❌ Wrong schema\n"; $errors++;
}
foreach ($collection['item'] as $g) {
    foreach ($g['item']??[] as $s) {
        foreach ($s['item']??[] as $ep) {
            if (empty($ep['request']['method'])) { echo "❌ Missing method: {$ep['name']}\n"; $errors++; }
            if (empty($ep['request']['url']))    { echo "❌ Missing url: {$ep['name']}\n";    $errors++; }
        }
    }
}

echo $errors === 0
    ? "\n✅  All validations passed!\n✅  JSON written to: test_output.json\n\n"
    : "\n⚠️  {$errors} error(s) found.\n\n";
