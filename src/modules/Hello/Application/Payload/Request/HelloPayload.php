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

    public string $color = 'indigo';

    public function validate(): PayloadValidationResult
    {
        if (!in_array($this->color, self::ALLOWED_COLORS, true)) {
            return new PayloadValidationResult(false, [
                'color' => ['Color must be one of: ' . implode(', ', self::ALLOWED_COLORS)],
            ]);
        }

        return new PayloadValidationResult(true);
    }
}
