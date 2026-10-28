<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** A drawn signature as the signing canvas submits it. */
    protected function signatureData(): string
    {
        $image=imagecreatetruecolor(160,60); imagefill($image,0,0,imagecolorallocate($image,255,255,255));
        imagesetthickness($image,3); imageline($image,12,35,145,16,imagecolorallocate($image,11,89,136));
        ob_start(); imagepng($image); $png=ob_get_clean(); imagedestroy($image);
        return 'data:image/png;base64,'.base64_encode($png);
    }

    /** MySQL's JSON columns return object keys in their own order: compare the content strictly, not the order. */
    protected function assertSameIgnoringKeyOrder(mixed $expected, mixed $actual, string $message = ''): void
    {
        $sort=function($value) use (&$sort) {
            if (!is_array($value)) return $value;
            if (!array_is_list($value)) ksort($value,SORT_STRING);
            return array_map($sort,$value);
        };
        $this->assertSame($sort($expected),$sort($actual),$message);
    }

    /** A face photo as the tablet camera capture submits it. */
    protected function selfieData(): string
    {
        $image=imagecreatetruecolor(320,400); imagefill($image,0,0,imagecolorallocate($image,214,190,170));
        ob_start(); imagejpeg($image,null,80); $jpeg=ob_get_clean(); imagedestroy($image);
        return 'data:image/jpeg;base64,'.base64_encode($jpeg);
    }
}
