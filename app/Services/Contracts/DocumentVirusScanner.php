<?php

namespace App\Services\Contracts;

use Illuminate\Validation\ValidationException;

/** ClamAV INSTREAM protocol. Production can require a working scanner fail-closed. */
class DocumentVirusScanner
{
    public function scan(string $bytes): array
    {
        if (! config('integrations.contracts.require_antivirus')) {
            return ['status' => 'not_configured', 'checked_at' => null];
        }
        $stream = @stream_socket_client(config('integrations.contracts.clamd_socket'), $errorCode, $error, 5);
        if (! $stream) {
            throw ValidationException::withMessages(['file' => 'Verificação antimalware indisponível. Tente novamente.']);
        }
        try {
            stream_set_timeout($stream, 15);
            $this->writeAll($stream, "zINSTREAM\0");
            for ($offset = 0; $offset < strlen($bytes); $offset += 8192) {
                $chunk = substr($bytes, $offset, 8192);
                $this->writeAll($stream, pack('N', strlen($chunk)).$chunk);
            }
            $this->writeAll($stream, pack('N', 0));
            $response = stream_get_contents($stream, 4096);
            if (! str_contains((string) $response, ': OK')) {
                throw ValidationException::withMessages(['file' => 'O arquivo foi recusado na verificação antimalware.']);
            }
            return ['status' => 'clean', 'engine' => 'clamd', 'checked_at' => now()->toIso8601String()];
        } finally {
            fclose($stream);
        }
    }

    private function writeAll($stream, string $data): void
    {
        while ($data !== '') {
            $written = fwrite($stream, $data);
            if ($written === false || $written === 0) {
                throw ValidationException::withMessages(['file' => 'Falha na verificação antimalware.']);
            }
            $data = substr($data, $written);
        }
    }
}
