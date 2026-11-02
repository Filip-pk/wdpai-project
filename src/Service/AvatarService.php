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

    public function __construct(private readonly UserRepository $users)
    {
    }

    /** @return list<string> */
    public function upload(int $userId, array $upload): array
    {
        $error = $upload['error'] ?? null;

        if (!is_int($error) || $error === UPLOAD_ERR_NO_FILE) {
            return ['Wybierz zdjęcie profilowe.'];
        }
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return ['Zdjęcie może mieć maksymalnie 4 MB.'];
        }
        if ($error !== UPLOAD_ERR_OK) {
            return ['Nie udało się przesłać zdjęcia. Spróbuj ponownie.'];
        }

        $path = $upload['tmp_name'] ?? null;
        if (!is_string($path) || !is_uploaded_file($path)) {
            return ['Nieprawidłowy plik zdjęcia.'];
        }

        $bytes = filesize($path);
        if ($bytes === false || $bytes === 0 || $bytes > self::MAX_BYTES) {
            return ['Wybierz niepuste zdjęcie o rozmiarze do 4 MB.'];
        }

        $info = @getimagesize($path);
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            return ['Wybierz poprawne zdjęcie w formacie JPG lub PNG.'];
        }
        if ($info[0] < 1 || $info[1] < 1
            || $info[0] > self::MAX_DIMENSION || $info[1] > self::MAX_DIMENSION) {
            return ['Zdjęcie może mieć maksymalnie 4096 × 4096 pikseli.'];
        }

        $source = $info[2] === IMAGETYPE_JPEG
            ? @imagecreatefromjpeg($path)
            : @imagecreatefrompng($path);
        if ($source === false) {
            return ['Nie można odczytać zdjęcia. Wybierz inny plik JPG lub PNG.'];
        }

        $thumbnail = null;

        try {
            $thumbnail = imagecreatetruecolor(self::SIZE, self::SIZE);
            if ($thumbnail === false) {
                throw new RuntimeException('Nie udało się przygotować zdjęcia.');
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
                throw new RuntimeException('Nie udało się przeskalować zdjęcia.');
            }

            ob_start();
            try {
                $encoded = imagepng($thumbnail, null, 6);
                $contents = ob_get_contents();
            } finally {
                ob_end_clean();
            }

            if (!$encoded || !is_string($contents) || $contents === '') {
                throw new RuntimeException('Nie udało się zapisać zdjęcia.');
            }

            $this->users->updateAvatar($userId, $contents, bin2hex(random_bytes(16)));
        } finally {
            imagedestroy($source);
            if ($thumbnail instanceof \GdImage) {
                imagedestroy($thumbnail);
            }
        }

        return [];
    }
}
