<?php
declare(strict_types=1);
namespace LuzPropria\LuzPanel\Controller;

use League\Glide\ServerFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class MediaController
{
    public function image(Request $request, string $path, string $imagem): StreamedResponse
    {
        if (str_contains($path, '..') || str_contains($imagem, '..') || str_contains($imagem, '/') || str_contains($imagem, '\\')) { throw new NotFoundHttpException(); }
        $server = ServerFactory::create(['source' => web_path('upload'), 'cache' => dirname(web_path()).'/var_runtime/images', 'max_image_size' => 4000000]);
        try {
            $cached = $server->makeImage($path.'/'.$imagem, $request->query->all());
            $stream = $server->getCache()->readStream($cached);
            $mime = $server->getCache()->mimeType($cached);
        } catch (\League\Glide\Filesystem\FileNotFoundException $e) { throw new NotFoundHttpException('Imagem não encontrada.', $e); }
        return new StreamedResponse(static function () use ($stream): void { fpassthru($stream); fclose($stream); }, 200, ['Content-Type' => $mime, 'Cache-Control' => 'public, max-age=86400', 'X-Content-Type-Options' => 'nosniff']);
    }
    public function revista(Request $request): BinaryFileResponse
    {
        $pdf = web_path('revista/revista-set-2025.pdf');
        if (!is_file($pdf)) { throw new NotFoundHttpException(); }
        if (getenv('APP_ENV') !== 'test' && !$request->cookies->get('notrack')) {
            $dir = dirname(web_path()).'/var_runtime';
            if (!is_dir($dir)) { mkdir($dir, 0750, true); }
            $log = fopen($dir.'/revista_clicks.csv', 'ab');
            if ($log !== false) {
                if (flock($log, LOCK_EX)) {
                    fputcsv($log, [date('Y-m-d H:i:s'), substr((string) $request->query->get('uid', ''), 0, 200), $request->getClientIp(), substr($request->headers->get('User-Agent', ''), 0, 1000), substr($request->headers->get('Referer', ''), 0, 1000), '/revista/revista-set-2025.pdf'], ';', '"', '');
                    flock($log, LOCK_UN);
                }
                fclose($log);
            }
        }
        return new BinaryFileResponse($pdf, 200, ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff']);
    }
}
