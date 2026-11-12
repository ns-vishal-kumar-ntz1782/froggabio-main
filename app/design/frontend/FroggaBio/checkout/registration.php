<?php
/**
 * FroggaBio checkout theme: Luma-based, used by Hyvä theme fallback on checkout routes.
 */

declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(ComponentRegistrar::THEME, 'frontend/FroggaBio/checkout', __DIR__);
