<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

use CoquiBot\Coqui\Api\CoreServices;
use CoquiBot\Coqui\Api\Router;

/**
 * A feature contributed by an installed mod that registers HTTP API routes.
 *
 * Mods declare their provider class(es) under extra.php-agents.apiFeatures in
 * composer.json; ApiFeatureDiscovery finds them and ApiCommand calls register()
 * with the live Router and a CoreServices handle. Implementations must be
 * no-arg constructable.
 */
interface ApiFeatureInterface
{
    public function register(Router $router, CoreServices $services): void;
}
