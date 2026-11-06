<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Operations\Data\BorderPostDto;
use App\Domain\Operations\Models\BorderPost;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Registers a gate (D1). The DTO has already refused an unknown country and impossible
 * coordinates; the Action owns the write so the future API shares one door.
 *
 * A duplicate name in the same country is refused with the existing post's name rather than a raw
 * unique-key error: "Kazungula already exists" is the sentence the desk needs, and the second row
 * would split a gate's crossings across two records that no report rejoins.
 */
final class CreateBorderPost
{
    public function __invoke(BorderPostDto $dto): BorderPost
    {
        try {
            return DB::transaction(static function () use ($dto): BorderPost {
                $post = new BorderPost([
                    'name' => trim($dto->name),
                    'country_code' => $dto->countryCode,
                    'lat' => $dto->lat,
                    'lng' => $dto->lng,
                    'open_from' => $dto->openFrom,
                    'open_to' => $dto->openTo,
                    'notes' => $dto->notes,
                ]);

                $post->save();

                return $post;
            });
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException(sprintf(
                'A gate called "%s" already exists in %s — register each physical post once.',
                trim($dto->name),
                $dto->countryCode,
            ));
        }
    }
}
