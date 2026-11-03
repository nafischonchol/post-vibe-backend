<?php

namespace App\Services\Courier;

use App\Models\CourierSetting;
use Bonik\Courier\Contracts\CredentialResolverInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MohimaCourierCredentialResolver implements CredentialResolverInterface
{
    protected string $table;

    public function __construct(?string $table = null)
    {
        $this->table = $table ?? config('bd-courier.credential_table', 'courier_fraud_checker_credentials');
    }

    protected function getProviderColumn(): string
    {
        return config('bd-courier.credential_provider_column', 'courier');
    }

    protected function getValueColumn(): string
    {
        return config('bd-courier.credential_value_column', 'credential');
    }

    public function resolve(string $provider): array
    {
        $provider = strtolower($provider);

        // 1. Check courier_fraud_checker_credentials table
        if (Schema::hasTable($this->table)) {
            $now = now();
            $providerColumn = $this->getProviderColumn();
            $valueColumn = $this->getValueColumn();

            $rows = DB::table($this->table)
                ->where($providerColumn, $provider)
                ->where(function ($query) use ($now) {
                    $query->where('status', 'active')
                        ->orWhere(function ($q) use ($now) {
                            $q->where('status', 'rate_limited')
                                ->where('rate_limit_until', '<=', $now);
                        });
                })
                ->get();

            $available = [];

            foreach ($rows as $row) {
                $raw = $row->{$valueColumn} ?? null;
                $credential = is_array($raw) ? $raw : json_decode((string) $raw, true);

                if (! is_array($credential)) {
                    $credential = [];
                }

                if (empty($credential) && $provider !== 'elitemart') {
                    continue;
                }

                $rateLimitUntil = $row->rate_limit_until ?? null;
                if ($rateLimitUntil && $now->lt($rateLimitUntil)) {
                    continue;
                }

                $available[] = [
                    'id' => $row->id,
                    'key' => (string) $row->id,
                    'value' => $credential,
                    'row' => $row,
                ];
            }

            if (! empty($available)) {
                usort($available, function ($a, $b) {
                    $left = $a['row']->last_used_at ?? null;
                    $right = $b['row']->last_used_at ?? null;

                    if ($left === null && $right === null) {
                        return 0;
                    }
                    if ($left === null) {
                        return -1;
                    }
                    if ($right === null) {
                        return 1;
                    }

                    return $left <=> $right;
                });

                return $available[0];
            }
        }

        // 2. EliteMart aggregator does not require real credentials to perform lookup
        if ($provider === 'elitemart') {
            return [
                'id' => 'elitemart_auto',
                'key' => 'elitemart_auto',
                'value' => ['enabled' => true],
                'row' => (object) ['last_used_at' => null],
            ];
        }

        // 3. Fallback: Check credentials from courier_settings table (e.g. for Pathao / Steadfast)
        if (Schema::hasTable('courier_settings')) {
            $setting = CourierSetting::where('courier_name', $provider)->first();
            if ($setting && ! empty($setting->credentials) && is_array($setting->credentials)) {
                $creds = $setting->credentials;
                $payload = [];

                if (! empty($creds['username']) || ! empty($creds['user'])) {
                    $payload['user'] = $creds['user'] ?? $creds['username'];
                    $payload['username'] = $payload['user'];
                }
                if (! empty($creds['password'])) {
                    $payload['password'] = $creds['password'];
                }
                if (! empty($creds['api_key'])) {
                    $payload['api_key'] = $creds['api_key'];
                }
                if (! empty($creds['secret_key'])) {
                    $payload['secret_key'] = $creds['secret_key'];
                }

                if (! empty($payload)) {
                    return [
                        'id' => 'setting_'.$setting->id,
                        'key' => 'setting_'.$setting->id,
                        'value' => $payload,
                        'row' => (object) ['last_used_at' => null],
                    ];
                }
            }
        }

        return [];
    }

    public function markSuccess(string $provider, string $credentialKey): void
    {
        if (Schema::hasTable($this->table) && is_numeric($credentialKey)) {
            DB::table($this->table)
                ->where($this->getProviderColumn(), $provider)
                ->where('id', $credentialKey)
                ->update([
                    'status' => 'active',
                    'last_used_at' => now(),
                    'last_error' => null,
                    'rate_limit_until' => null,
                ]);
        }
    }

    public function markFailure(string $provider, string $credentialKey, int $statusCode = 0, ?string $message = null, ?int $retryAfterMinutes = null): void
    {
        if (Schema::hasTable($this->table) && is_numeric($credentialKey)) {
            $payload = [
                'last_used_at' => now(),
                'last_error' => $message,
            ];

            if ($statusCode === 429 || $retryAfterMinutes) {
                $minutes = $retryAfterMinutes ?? config("bd-courier.rate_limits.{$provider}", config('bd-courier.default_rate_limit_minutes', 15));
                $payload['rate_limit_until'] = now()->addMinutes($minutes);
                $payload['status'] = 'rate_limited';
            }

            DB::table($this->table)
                ->where($this->getProviderColumn(), $provider)
                ->where('id', $credentialKey)
                ->update($payload);
        }
    }
}
