<?php

declare(strict_types=1);

namespace App\Modules\Hello\Application\Payload\Request;

use App\Modules\Hello\Application\Resource\Response\HelloResource;
use Semitexa\Authorization\Attributes\PublicEndpoint;
use Semitexa\Core\Attributes\AsPayload;
use Semitexa\Core\Contract\ValidatablePayload;
use Semitexa\Core\Http\PayloadValidationResult;

#[PublicEndpoint]
#[AsPayload(
    responseWith: HelloResource::class,
    path: '/',
    methods: ['GET'],
)]
class HelloPayload implements ValidatablePayload
{
    private const array ALLOWED_COLORS = ['indigo', 'emerald', 'rose', 'amber', 'cyan'];
    private const array ALLOWED_LANGS = ['en', 'uk'];

    public string $color = 'indigo';
    public string $lang = 'en';

    public function validate(): PayloadValidationResult
    {
        $errors = [];

        if (!in_array($this->color, self::ALLOWED_COLORS, true)) {
            $errors['color'] = ['Color must be one of: ' . implode(', ', self::ALLOWED_COLORS)];
        }

        if (!in_array($this->lang, self::ALLOWED_LANGS, true)) {
            $errors['lang'] = ['Language must be one of: ' . implode(', ', self::ALLOWED_LANGS)];
        }

        return new PayloadValidationResult($errors === [], $errors);
    }
}
