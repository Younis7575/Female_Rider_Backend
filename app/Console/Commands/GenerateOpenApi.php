<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use ReflectionMethod;

/**
 * Generates an OpenAPI 3.1 document from the registered /api/v1 routes and the
 * validation rules inside each controller action, so the contract stays in sync with code.
 */
#[AsCommand(name: 'fr:openapi')]
class GenerateOpenApi extends Command
{
    protected $signature = 'fr:openapi {--output=../documentation/api/openapi.json}';

    protected $description = 'Generate the OpenAPI specification for the FemaleRider REST API';

    private const IDEMPOTENT = ['rides', 'offers/{offer}/accept', 'offers/{offer}/respond', 'trips/{trip}/complete', 'trips/{trip}/cash-collected', 'refunds', 'driver/payouts'];

    public function handle(): int
    {
        $paths = [];
        foreach (RouteFacade::getRoutes() as $route) {
            /** @var Route $route */
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $uri = '/'.Str::after($route->uri(), 'api/v1/');
            $action = $route->getActionName();
            $tag = Str::of($uri)->trim('/')->explode('/')->first();
            $tag = ucfirst(match ($tag) {
                'auth', 'sessions', 'me', 'account', 'devices', 'users' => 'account', 'places', 'saved-places', 'fare', 'routes', 'service-area', 'coupons' => 'maps & pricing', 'offers' => 'rides', default => $tag
            });
            $auth = in_array('auth:sanctum', $route->gatherMiddleware(), true);

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $m = strtolower($method);
                $op = [
                    'tags' => [$tag],
                    'summary' => $this->summary($action, $m, $uri),
                    'operationId' => Str::camel($m.' '.str_replace(['{', '}', '/', '-'], ['', '', ' ', ' '], $uri)),
                    'responses' => [
                        '200' => ['description' => 'Success', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Envelope']]]],
                        '422' => ['$ref' => '#/components/responses/Error'],
                    ],
                ];
                if ($auth) {
                    $op['security'] = [['bearerAuth' => []]];
                    $op['responses']['401'] = ['$ref' => '#/components/responses/Error'];
                    $op['responses']['403'] = ['$ref' => '#/components/responses/Error'];
                }
                $params = [];
                preg_match_all('/\{(\w+)\??\}/', $uri, $mm);
                foreach ($mm[1] as $p) {
                    $params[] = ['name' => $p, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'description' => in_array($p, ['ride', 'trip']) ? ucfirst($p).' UUID' : null];
                }
                if (in_array(trim($uri, '/'), self::IDEMPOTENT, true) && $m === 'post') {
                    $params[] = ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string', 'maxLength' => 80], 'description' => 'Replays return the original response.'];
                }
                if ($params) {
                    $op['parameters'] = array_map(fn ($p) => array_filter($p, fn ($v) => $v !== null), $params);
                }
                $rules = $this->rules($action);
                if ($rules) {
                    if (in_array($m, ['get', 'delete'], true) && $m === 'get') {
                        foreach ($rules as $name => $schema) {
                            $op['parameters'][] = ['name' => $name, 'in' => 'query', 'required' => $schema['x-required'] ?? false, 'schema' => array_diff_key($schema, ['x-required' => 1])];
                        }
                    } else {
                        $hasFile = collect($rules)->contains(fn ($s) => ($s['format'] ?? null) === 'binary');
                        $required = array_keys(array_filter($rules, fn ($s) => $s['x-required'] ?? false));
                        $props = array_map(fn ($s) => array_diff_key($s, ['x-required' => 1]), $rules);
                        $op['requestBody'] = ['required' => (bool) $required, 'content' => [
                            ($hasFile ? 'multipart/form-data' : 'application/json') => ['schema' => array_filter(['type' => 'object', 'required' => $required ?: null, 'properties' => $props])],
                        ]];
                    }
                }
                $paths[$uri][$m] = $op;
            }
        }
        ksort($paths);

        $doc = [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'FemaleRider REST API',
                'version' => '1.0.0',
                'description' => "Women-only ride-hailing platform API. All responses use the envelope {success, message, data, meta, error}. Business rules (eligibility, fares, assignment, trip state machine, payments) are enforced server-side; the mobile app is an untrusted client.\n\nRealtime: Laravel Reverb (Pusher protocol). Authorise private channels at POST /api/v1/broadcasting/auth. Channels: private-user.{id}, private-driver.{id}, private-ride.{rideUuid}, private-trip.{tripUuid}.",
            ],
            'servers' => [['url' => 'http://localhost:8000/api/v1', 'description' => 'Local'], ['url' => 'http://10.0.2.2:8000/api/v1', 'description' => 'Android emulator']],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Sanctum token from POST /auth/otp/verify']],
                'schemas' => [
                    'Envelope' => ['type' => 'object', 'properties' => [
                        'success' => ['type' => 'boolean'], 'message' => ['type' => ['string', 'null']], 'data' => [],
                        'meta' => ['type' => ['object', 'null'], 'properties' => ['pagination' => ['type' => 'object', 'properties' => ['current_page' => ['type' => 'integer'], 'per_page' => ['type' => 'integer'], 'total' => ['type' => 'integer'], 'last_page' => ['type' => 'integer']]]]],
                        'error' => ['type' => ['object', 'null'], 'properties' => ['code' => ['type' => 'string'], 'details' => []]],
                    ]],
                ],
                'responses' => ['Error' => ['description' => 'Error envelope (see error.code)', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Envelope']]]]],
            ],
        ];

        $out = base_path($this->option('output'));
        @mkdir(dirname($out), 0755, true);
        file_put_contents($out, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->info('Wrote '.count($paths).' paths to '.realpath($out));

        return self::SUCCESS;
    }

    private function summary(string $action, string $method, string $uri): string
    {
        if (! str_contains($action, '@')) {
            return strtoupper($method).' '.$uri;
        }

        return Str::headline(Str::after($action, '@')).' ('.class_basename(Str::before($action, '@')).')';
    }

    /** @return array<string, array> */
    private function rules(string $action): array
    {
        if (! str_contains($action, '@')) {
            return [];
        }
        [$class, $method] = explode('@', $action);
        try {
            $ref = new ReflectionMethod($class, $method);
        } catch (\ReflectionException) {
            return [];
        }
        $src = implode('', array_slice(file($ref->getFileName()), $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
        $pos = strpos($src, 'validate([');
        if ($pos === false) {
            return [];
        }
        // Extract the balanced [...] rules array.
        $start = $pos + strlen('validate(');
        $depth = 0;
        $body = '';
        for ($i = $start, $n = strlen($src); $i < $n; $i++) {
            $c = $src[$i];
            $depth += $c === '[' ? 1 : ($c === ']' ? -1 : 0);
            $body .= $c;
            if ($depth === 0) {
                break;
            }
        }
        $out = [];
        preg_match_all("/'([\w.*]+)'\s*=>\s*(\[(?:[^\[\]]|\[[^\]]*\])*\]|'[^']*')/s", substr($body, 1, -1), $rows, PREG_SET_ORDER);
        foreach ($rows as [, $field, $rule]) {
            if (str_contains($field, '*')) {
                continue;
            }
            $schema = ['type' => 'string'];
            if (preg_match("/'(numeric|between)/", $rule)) {
                $schema['type'] = 'number';
            }
            if (str_contains($rule, "'integer'")) {
                $schema['type'] = 'integer';
            }
            if (preg_match("/'(boolean|accepted)'/", $rule)) {
                $schema['type'] = 'boolean';
            }
            if (str_contains($rule, "'array'")) {
                $schema = ['type' => 'array', 'items' => []];
            }
            if (preg_match("/'(file|image)'/", $rule)) {
                $schema = ['type' => 'string', 'format' => 'binary'];
            }
            if (str_contains($rule, "'date'")) {
                $schema['format'] = 'date';
            }
            if (str_contains($rule, "'email")) {
                $schema['format'] = 'email';
            }
            if (preg_match("/'max:(\d+)'/", $rule, $mx) && $schema['type'] === 'string' && ! isset($schema['format'])) {
                $schema['maxLength'] = (int) $mx[1];
            }
            if (preg_match("/Rule::in\(\[([^\]]+)\]\)/", $rule, $en)) {
                $schema['enum'] = array_map(fn ($v) => trim($v, " '"), explode(',', $en[1]));
            }
            $schema['x-required'] = (bool) preg_match("/'required'/", $rule) || str_contains($rule, "'accepted'");
            $out[$field] = $schema;
        }

        return $out;
    }
}
