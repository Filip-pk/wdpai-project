<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\UserRepository;
use RuntimeException;

final class AvatarService
{
    private const MAX_BYTES = 4 * 1024 * 1024;
    private const MAX_DIMENSION = 4096;
    private const SIZE = 256;
    private const EXTENSIONS = ['jpg', 'jpeg', 'png'];
    private const MIME_TYPES = ['image/jpeg', 'image/png'];

    public function __construct(
        private readonly UserRepository $users,
        private readonly AvatarStorage $storage,
    ) {
    }

    /** @return list<string> */
    public function upload(int $userId, array $upload): array
    {
        $error = $upload['error'] ?? null;

        if (!is_int($error) || $error === UPLOAD_ERR_NO_FILE) {
            return [Translator::current()->translate('message.avatar.choose')];
        }
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return [Translator::current()->translate('message.avatar.size')];
        }
        if ($error !== UPLOAD_ERR_OK) {
            return [Translator::current()->translate('message.avatar.upload')];
        }

        $path = $upload['tmp_name'] ?? null;
        if (!is_string($path) || !is_uploaded_file($path)) {
            return [Translator::current()->translate('message.avatar.invalid')];
        }

        $bytes = filesize($path);
        if ($bytes === false || $bytes === 0 || $bytes > self::MAX_BYTES) {
            return [Translator::current()->translate('message.avatar.nonempty')];
        }

        // The file name and the browser's type are untrusted: check the extension and the real content type.
        $extension = strtolower(pathinfo(is_string($upload['name'] ?? null) ? $upload['name'] : '', PATHINFO_EXTENSION));
        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($extension, self::EXTENSIONS, true) || !in_array($mimeType, self::MIME_TYPES, true)) {
            return [Translator::current()->translate('message.avatar.format')];
        }

        $info = @getimagesize($path);
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            return [Translator::current()->translate('message.avatar.format')];
        }
        if ($info[0] < 1 || $info[1] < 1
            || $info[0] > self::MAX_DIMENSION || $info[1] > self::MAX_DIMENSION) {
            return [Translator::current()->translate('message.avatar.dimensions')];
        }

        $source = $info[2] === IMAGETYPE_JPEG
            ? @imagecreatefromjpeg($path)
            : @imagecreatefrompng($path);
        if ($source === false) {
            return [Translator::current()->translate('message.avatar.unreadable')];
        }

        $thumbnail = null;

        try {
            $thumbnail = imagecreatetruecolor(self::SIZE, self::SIZE);
            if ($thumbnail === false) {
                throw new RuntimeException(Translator::current()->translate('message.avatar.prepare'));
            }

            imagealphablending($thumbnail, false);
            imagesavealpha($thumbnail, true);
            imagefill($thumbnail, 0, 0, imagecolorallocatealpha($thumbnail, 0, 0, 0, 127));

            $side = min($info[0], $info[1]);
            if (!imagecopyresampled(
                $thumbnail,
                $source,
                0,
                0,
                intdiv($info[0] - $side, 2),
                intdiv($info[1] - $side, 2),
                self::SIZE,
                self::SIZE,
                $side,
                $side,
            )) {
                throw new RuntimeException(Translator::current()->translate('message.avatar.resize'));
            }

            ob_start();
            try {
                $encoded = imagepng($thumbnail, null, 6);
                $contents = ob_get_contents();
            } finally {
                ob_end_clean();
            }

            if (!$encoded || !is_string($contents) || $contents === '') {
                throw new RuntimeException(Translator::current()->translate('message.avatar.save'));
            }

            // The previous file is not deleted here: the request transaction may still roll back.
            // The cleanup task removes files that no account refers to (docs/maintenance.md).
            $this->users->updateAvatar($userId, $this->storage->save($contents), bin2hex(random_bytes(16)));
        } finally {
            imagedestroy($source);
            if ($thumbnail instanceof \GdImage) {
                imagedestroy($thumbnail);
            }
        }

        return [];
    }
}
