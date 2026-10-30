<?php

namespace App\Services\Contracts;

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Páginas do contrato como imagens JPEG: celulares não mostram PDF dentro da página, mas mostram imagens.
 * Cada PDF vira uma pasta (nome = SHA-256 do arquivo) com p-1.jpg, p-2.jpg…; o mesmo PDF nunca é convertido duas vezes.
 */
class PdfPages
{
    /** @return array{key:string,count:int} */
    public function render(string $pdf): array
    {
        if (! str_starts_with($pdf, '%PDF-')) {
            throw new \RuntimeException('O arquivo do contrato não é um PDF válido.');
        }
        $key = hash('sha256', $pdf);
        $directory = $this->directory($key);
        if ($this->count($directory) === 0) {
            $temporary = storage_path('app/private/pdf-pages/'.$key.'.'.Str::uuid().'.tmp');
            mkdir($temporary, 0700, true);
            try {
                file_put_contents($temporary.'/in.pdf', $pdf);
                (new Process(['pdftoppm', '-jpeg', '-jpegopt', 'quality=84', '-r', '115', $temporary.'/in.pdf', $temporary.'/p'], null, null, null, 90))->mustRun();
                $files = glob($temporary.'/p-*.jpg') ?: [];
                natsort($files);
                if (! $files) {
                    throw new \RuntimeException('Não foi possível converter o contrato em imagens.');
                }
                foreach (array_values($files) as $index => $file) {
                    rename($file, $temporary.'/n-'.($index + 1).'.jpg');
                }
                foreach (glob($temporary.'/n-*.jpg') as $file) {
                    rename($file, $temporary.'/p-'.substr(basename($file), 2));
                }
                unlink($temporary.'/in.pdf');
                if (! @rename($temporary, $directory) && $this->count($directory) === 0) {
                    throw new \RuntimeException('Não foi possível guardar as páginas do contrato.');
                }
            } finally {
                if (is_dir($temporary)) {
                    foreach (glob($temporary.'/*') ?: [] as $left) {
                        @unlink($left);
                    }
                    @rmdir($temporary);
                }
            }
        }

        return ['key' => $key, 'count' => $this->count($directory)];
    }

    public function path(string $key, int $page): string
    {
        return $this->directory($key).'/p-'.$page.'.jpg';
    }

    private function directory(string $key): string
    {
        return storage_path('app/private/pdf-pages/'.$key);
    }

    private function count(string $directory): int
    {
        return is_dir($directory) ? count(glob($directory.'/p-*.jpg') ?: []) : 0;
    }
}
