<?php
declare(strict_types=1);

function userPhotoPath(int $id): string
{
    return ROOT . "/storage/user-photos/$id.webp";
}

function saveProfilePhoto(string $path, array $upload): void
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || ($upload['size'] ?? 0) > 8_000_000 || !is_uploaded_file($upload['tmp_name'] ?? '')) {
        throw new InvalidArgumentException('Escolhe uma fotografia JPEG, PNG ou WebP até 8 MB.');
    }
    if (!function_exists('imagewebp')) {
        throw new InvalidArgumentException('O carregamento de fotografias está temporariamente indisponível.');
    }
    $source = $upload['tmp_name'];
    $info = @getimagesize($source);
    if (!$info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 16_000_000
        || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        throw new InvalidArgumentException('Escolhe uma imagem JPEG, PNG ou WebP com resolução até 16 megapíxeis.');
    }
    $bytes = file_get_contents($source);
    $image = $bytes === false ? false : @imagecreatefromstring($bytes);
    if (!$image) {
        throw new InvalidArgumentException('Não foi possível ler esta imagem.');
    }

    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $orientation = @exif_read_data($source)['Orientation'] ?? 1;
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }
        $angle = match ($orientation) { 3, 4 => 180, 5, 8 => 90, 6, 7 => -90, default => 0 };
        if ($angle !== 0) {
            $image = imagerotate($image, $angle, 0);
        }
    }
    $width = imagesx($image);
    $height = imagesy($image);
    $scale = min(1, 640 / max($width, $height));
    $photo = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Photo directory unavailable.');
    }
    $temp = tempnam($directory, '.photo-');
    if (!$temp) {
        throw new RuntimeException('Photo staging failed.');
    }
    try {
        if (!$photo || !imagewebp($photo, $temp, 85) || !filesize($temp) || !rename($temp, $path)) {
            throw new RuntimeException('Photo write failed.');
        }
    } finally {
        if (is_file($temp)) {
            unlink($temp);
        }
    }
}
