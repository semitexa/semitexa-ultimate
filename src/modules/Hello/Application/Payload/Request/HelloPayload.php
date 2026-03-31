<?php

declare(strict_types=1);

namespace App\Modules\Hello\Application\Payload\Request;

use App\Modules\Hello\Application\Resource\Response\HelloResource;
use Semitexa\Authorization\Attributes\PublicEndpoint;
use Semitexa\Core\Attributes\AsPayload;

#[PublicEndpoint]
#[AsPayload(
    path: '/',
    methods: ['GET'],
    responseWith: HelloResource::class,
)]
class HelloPayload
{
}
