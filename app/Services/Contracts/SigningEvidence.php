<?php

namespace App\Services\Contracts;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SigningEvidence
{
    private const DEVICE_FIELDS = ['screen', 'viewport', 'pixel_ratio', 'platform', 'mobile', 'language', 'languages', 'timezone', 'timezone_offset',
        'touch_points', 'cores', 'memory_gb', 'connection', 'downlink_mbps', 'captured_at'];

    /** Network and device of the signer. The site forwards the visitor's data in X-Portal-* headers. */
    public function collect(Request $request, ?string $deviceJson, bool $forwarded = false): array
    {
        $ip = $forwarded && filter_var($request->header('X-Portal-IP'), FILTER_VALIDATE_IP) ? $request->header('X-Portal-IP') : $request->ip();
        $port = $forwarded ? $request->header('X-Portal-Port') : $request->server('REMOTE_PORT');
        $evidence = [
            'ip' => $ip,
            'ip_port' => preg_match('/^\d{1,5}$/D', (string) $port) ? (int) $port : null,
            'forwarded_for' => $forwarded ? null : $this->text($request->header('X-Forwarded-For'), 300),
            'user_agent' => mb_substr((string) ($forwarded ? $request->header('X-Portal-Agent') : $request->userAgent()), 0, 500),
            'accept_language' => $this->text($forwarded ? $request->header('X-Portal-Language') : $request->header('Accept-Language'), 120),
            'device' => $this->device($deviceJson),
        ];
        return array_filter($evidence, fn ($value) => $value !== null && $value !== '');
    }

    public function device(?string $json): ?array
    {
        $data = $json ? json_decode($json, true, 4) : null;
        if (! is_array($data)) {
            return null;
        }
        $clean = [];
        foreach (self::DEVICE_FIELDS as $field) {
            $value = $data[$field] ?? null;
            if (is_bool($value) || is_int($value) || is_float($value)) {
                $clean[$field] = $value;
            } elseif (is_string($value) && ($value = $this->text($value, 120)) !== null) {
                $clean[$field] = $value;
            }
        }
        return $clean ?: null;
    }

    /** Stores the in-person signer's face photo privately and returns its integrity record. */
    public function storeSelfie(?string $image, int $proposalId): array
    {
        if (! $image || strlen($image) > 900000 || ! str_starts_with($image, 'data:image/jpeg;base64,')) {
            throw ValidationException::withMessages(['selfie' => 'Tire uma foto do rosto do titular para concluir a assinatura presencial.']);
        }
        $raw = base64_decode(substr($image, 23), true);
        $info = $raw ? @getimagesizefromstring($raw) : false;
        if (! $info || ($info['mime'] ?? '') !== 'image/jpeg' || min($info[0], $info[1]) < 160 || max($info[0], $info[1]) > 1600) {
            throw ValidationException::withMessages(['selfie' => 'Não foi possível ler a foto. Tire a foto do rosto novamente.']);
        }
        $path = 'signing-evidence/'.$proposalId.'/'.Str::uuid().'.jpg';
        if (! Storage::disk(config('integrations.contracts.disk', 'local'))->put($path, $raw, ['visibility' => 'private'])) {
            throw new \RuntimeException('Não foi possível guardar a foto do titular.');
        }
        return ['selfie_path' => $path, 'selfie_sha256' => hash('sha256', $raw), 'selfie_size_bytes' => strlen($raw),
            'selfie_dimensions' => $info[0].'x'.$info[1], 'selfie_captured_at' => now()->toIso8601String()];
    }

    public function discardSelfie(array $selfie): void
    {
        if (! empty($selfie['selfie_path'])) {
            Storage::disk(config('integrations.contracts.disk', 'local'))->delete($selfie['selfie_path']);
        }
    }

    private function text(mixed $value, int $limit): ?string
    {
        $value = trim(preg_replace('/[\x00-\x1f\x7f]/u', ' ', (string) $value));
        return $value === '' ? null : mb_substr($value, 0, $limit);
    }
}
