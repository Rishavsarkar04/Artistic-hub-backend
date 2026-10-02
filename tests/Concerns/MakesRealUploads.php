<?php

namespace Tests\Concerns;

use Illuminate\Http\UploadedFile;

/**
 * Real files for upload tests. UploadedFile::fake() reports its type from the file name and can report
 * made-up sizes and dimensions, which hides what validation does with the actual bytes.
 */
trait MakesRealUploads
{
    /** A file on disk wrapped like a browser upload; its type is detected from the content. */
    protected function realUpload(string $clientName, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, $bytes);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return new UploadedFile($path, $clientName, null, null, true);
    }

    protected function pngBytes(int $width = 20, int $height = 20): string
    {
        ob_start();
        imagepng(imagecreatetruecolor($width, $height));

        return ob_get_clean();
    }

    protected function gifBytes(): string
    {
        ob_start();
        imagegif(imagecreatetruecolor(10, 10));

        return ob_get_clean();
    }
}
