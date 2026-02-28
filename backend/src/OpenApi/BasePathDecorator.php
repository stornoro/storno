<?php

declare(strict_types=1);

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\{PathItem, Paths, SecurityScheme, Server};
use ApiPlatform\OpenApi\OpenApi;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

#[AsDecorator(
    decorates: 'api_platform.openapi.factory',
    // The default priority is 0, higher priorities are executed first.
    // To avoid having the Lexik JWT Authentication Bundle decorator executed
    // before this one, we set a lower priority.
    priority: -1
)]
class BasePathDecorator implements OpenApiFactoryInterface
{
    private const BASE_PATH = '/api/v1';

    public function __construct(
        private readonly OpenApiFactoryInterface $decorated,
    ) {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);

        $customPaths = new Paths();

        /**
         * @var  non-empty-array<non-empty-string, PathItem>  $paths
         */
        $paths = $openApi->getPaths()->getPaths();

        foreach ($paths as $path => $pathItem) {
            $customPaths->addPath(\str_replace(self::BASE_PATH, '', $path), $pathItem);
        }

        $openApi = $openApi->withPaths($customPaths);

        $securitySchemes = $openApi->getComponents()->getSecuritySchemes() ?: new \ArrayObject();
        $securitySchemes['JWT'] =  new SecurityScheme(
            type: 'http', // apiKey
            description: 'Use an API token to authenticate - <a href="/account/api">Get Api Token</a>',
            in: "header",
            name: 'Authorization',
            scheme: 'bearer',
        );

        return $openApi->withServers([
            new Server(url: self::BASE_PATH)
        ]);
    }
}
