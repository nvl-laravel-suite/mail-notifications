<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Support;

use Nvl\Media\MediaRegistration;

/** Declares immutable mail bytes without image transformations or cross-owner sharing. */
final class MailMediaSlots
{
    public const string Bytes = 'bytes';

    public const int MAX_FILE_BYTES = 10 * 1024 * 1024;

    /** @var list<string> */
    public const array MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'text/plain', 'text/csv', 'application/zip', 'application/x-empty', 'inode/x-empty'];

    /** Capability registration never grants native Ownership; the host must configure and adopt its actual anchor. */
    public static function register(MediaRegistration $media, bool $public = false): void
    {
        $disk = ManagedConfiguration::string($public ? 'storage.public_disk' : 'storage.disk', $public ? 'public' : 'local');
        $media->slot(self::Bytes)->singleFile()->exclusive()->isPublic($public)->useDisk($disk)
            ->acceptsMimeTypes(self::MIME_TYPES)->maxFileSize(self::MAX_FILE_BYTES);
    }

    private function __construct() {}
}
