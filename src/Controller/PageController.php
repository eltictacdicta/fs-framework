<?php

namespace FSFramework\Controller;

use FSFramework\Core\Base\Controller as LegacyPageController;

/**
 * CMS page controller base for FSFramework.
 *
 * Modern CMS pages should extend this class for automatic page
 * registration, authentication, and menu integration.
 */
class PageController extends LegacyPageController
{
}