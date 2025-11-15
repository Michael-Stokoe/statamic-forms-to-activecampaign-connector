<?php

namespace Stokoe\FormsToActivecampaignConnector\Tests;

use Stokoe\FormsToActivecampaignConnector\ServiceProvider;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;
}
