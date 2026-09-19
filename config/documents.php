<?php

declare(strict_types=1);

return [
    'disk' => 'business_documents',
    'max_bytes' => 25 * 1024 * 1024,
    'types' => [
        'pdf' => [
            'mime' => 'application/pdf',
            'preview_safe' => true,
        ],
        'docx' => [
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'preview_safe' => false,
            'ooxml_root' => 'word/document.xml',
            'ooxml_content_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml',
        ],
        'xlsx' => [
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'preview_safe' => false,
            'ooxml_root' => 'xl/workbook.xml',
            'ooxml_content_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml',
        ],
        'png' => [
            'mime' => 'image/png',
            'preview_safe' => true,
        ],
        'jpg' => [
            'mime' => 'image/jpeg',
            'preview_safe' => true,
        ],
        'jpeg' => [
            'mime' => 'image/jpeg',
            'preview_safe' => true,
        ],
    ],
];
