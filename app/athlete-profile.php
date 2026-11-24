<?php
declare(strict_types=1);

const ATHLETE_FIELDS = [
    'class' => 'Turma',
    'tax_id' => 'NIF',
    'birth_date' => 'Data de nascimento',
    'category' => 'Escalão',
    'phone' => 'Telemóvel do atleta',
    'guardian' => 'Encarregado(s) de educação',
    'guardian_phone' => 'Telemóvel do encarregado de educação',
    'guardian_email' => 'Email do encarregado de educação',
    'license' => 'Licença FPP',
    'medical_exam' => 'Exame médico',
    'season' => 'Época',
];

function nameInitials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts) {
        return '';
    }
    $last = count($parts) > 1 ? mb_substr(end($parts), 0, 1, 'UTF-8') : '';
    return mb_strtoupper(mb_substr($parts[0], 0, 1, 'UTF-8') . $last, 'UTF-8');
}

function athletePhotoPath(int $id): string
{
    return ROOT . "/storage/photos/$id.webp";
}
