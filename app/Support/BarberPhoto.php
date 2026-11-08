<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

class BarberPhoto
{
    public static function store(UploadedFile $photo): string
    {
        $path = $photo->store('barber-photos', 'public');

        if ($path === false) {
            throw new \RuntimeException('Não foi possível salvar a foto.');
        }

        return $path;
    }
}
