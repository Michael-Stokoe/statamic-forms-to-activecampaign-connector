<?php

declare(strict_types=1);

namespace Stokoe\FormsToActivecampaignConnector;

use Stokoe\FormsToWherever\ConnectorManager;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    public function bootAddon(): void
    {
        $connectorManager = app(ConnectorManager::class);
        $connectorManager->register(new ActivecampaignConnector);
    }
}
