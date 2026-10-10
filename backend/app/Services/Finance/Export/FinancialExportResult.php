<?php

namespace App\Services\Finance\Export;

use Symfony\Component\HttpFoundation\Response;

class FinancialExportResult
{
    public function __construct(
        public readonly string $content,
        public readonly string $filename,
        public readonly string $contentType,
        public readonly string $format
    ) {}

    /**
     * Convert export result to Symfony HTTP Response with correct headers.
     */
    public function toResponse(): Response
    {
        // Normalize path separators and extract basename
        $cleanFilename = basename(str_replace('\\', '/', $this->filename));
        // Sanitize filename against CRLF injection, quotes, null bytes, and path traversal
        $cleanFilename = preg_replace('/[\r\n\0"\'\\\\]/', '', $cleanFilename);
        $cleanFilename = ltrim($cleanFilename, '.');

        return response($this->content, 200, [
            'Content-Type' => $this->contentType,
            'Content-Disposition' => 'attachment; filename="'.($cleanFilename ?: 'export.'.$this->format).'"',
            'Cache-Control' => 'no-cache, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
